<?php
/**
 * IMAP 会话客户端（包 D · D0-1 抽出 / D0-2 修 M-4 / D0-3 修 M-3 + M-5）
 *
 * 【D0-1：把包 C 里手搓的 IMAP 协议操作原样搬出 MailService】
 * 从 MailService 搬出来的只有"连接与四个命令"，**字面量一字未改**：
 *   A01 AUTHENTICATE XOAUTH2 <base64>   （含 \x01 分隔符的两处 \x01）
 *   A02 SELECT INBOX
 *   A03 SEARCH ALL                      （含 /^\* SEARCH (.*)/i 的解析）
 *   A04 FETCH n (BODY.PEEK[])           （含 /^A04 OK/m 的结束判定；D0-2 前是 RFC822）
 *
 * 【D0-2：只把 RFC822 换成 BODY.PEEK[]，报文不置已读，其余不动】
 *
 * 【D0-3：只重写"怎么读"，命令串/标签/解析正则一个字面量都没动】
 *   M-3 literal：响应头行末尾的 `{n}` 现在是**权威长度**——`readLiteral($n)` 用 fread
 *       精确读满 n 字节，不再靠"行里正好没换行"的运气，也不受行长限制（16KB 截断随之消失）
 *   M-3 SEARCH 多行：`* SEARCH` 多行结果**合并**（原来是逐行覆盖，只留最后一行）
 *   M-5 超时/断连：readLine/readLiteral 返回 false 时查 stream_get_meta_data 的 timed_out，
 *       区分"超时（抛 RuntimeException）"与"对端关闭（返回 null → 调用方抛）"，
 *       stream_set_timeout 从 D0-1 的"接了参数没生效"变成真正生效
 *
 * 一个实例 = 一次连接 = 一个 host。多 host 的重试循环留在 MailService（与包 C 一致）：
 * 本类抛 RuntimeException，MailService 捕获后 continue 下一个 host，语义与包 C 的 continue 相同。
 */
final class ImapClient
{
    /**
     * 单行读取上限（D0-3 内部常量，与协议无关）
     *
     * 包 C 用的是 1024/4096/16384 三个魔数，而 16384 正是"超长行被截断"的根源。
     * D0-3 之后报文一律走 readLiteral 精确读，fgets 只用来读响应头/状态行，
     * 一条状态行不可能超过 8KB，所以统一成一个常量即可。
     */
    private const LINE_LIMIT = 8192;

    private ImapConfig $config;

    /** 本次连接使用的主机（由 MailService 从 config->hosts 逐个传入） */
    private string $host;

    /** @var resource|null */
    private $sock;

    public function __construct(ImapConfig $config, ?string $host = null)
    {
        $this->config = $config;
        $this->host   = $host ?? ($config->hosts[0] ?? 'outlook.live.com');
    }

    /**
     * 建连 + XOAUTH2 认证
     *
     * 与原代码等价的一步不差：先 fsockopen（ssl://host, port, connectTimeout），
     * 再吃掉一行欢迎语，再发 A01，再读一行判定 'A01 OK'。
     * 认证失败时自己关连接并返回 null（原代码是 `fclose($sock); continue;`）。
     *
     * @return resource|null 成功返回 socket，失败返回 null
     */
    public function connect(string $email, string $accessToken)
    {
        $scheme = $this->config->ssl ? 'ssl://' : 'tcp://';
        $sock   = fsockopen(
            $scheme . $this->host,
            $this->config->port,
            $errno,
            $errstr,
            $this->config->connectTimeout
        );

        if (!$sock) {
            $this->sock = null;
            return null;
        }

        $this->sock = $sock;

        // D0-3：readTimeout 从这里真正生效（readLine 用 timed_out 判定，超时抛 RuntimeException）
        @stream_set_timeout($this->sock, $this->config->readTimeout);

        // 吃掉欢迎语；对端连欢迎语都不给就当这条连接废了（close + null = 原来的 continue）
        if ($this->readLine() === null) {
            $this->close();
            return null;
        }
        // 变量名照抄包 C（$access_token）而不是入参名：这里的两条字符串是协议字面量，
        // D0-1 的验收要求"字面量与 HEAD 逐字节一致"，改名会让差集比对产生假阳性。
        $access_token = $accessToken;
        $auth_str = base64_encode("user=$email\x01auth=Bearer $access_token\x01\x01");
        fputs($this->sock, "A01 AUTHENTICATE XOAUTH2 $auth_str\r\n");
        $login = $this->readLine();
        if ($login === null || !str_contains($login, 'A01 OK')) {
            $this->close();
            return null;
        }

        return $this->sock;
    }

    /**
     * A02 SELECT INBOX（实现委托 selectFolder('INBOX')，行为与包 C 一致）
     *
     * @return string[] 原始响应行（调用方不使用，保留以便排查）
     * @throws RuntimeException 超时/断连
     */
    public function selectInbox(): array
    {
        return $this->selectFolder('INBOX');
    }

    /**
     * A02 SELECT <folder>（读到 'A02 OK' 为止；原代码不检查响应内容，这里也不检查）
     *
     * 文件夹名做 IMAP 引号转义：只含可打印 ASCII 且不含 atom 特殊字符时原样发出
     * （INBOX → `A02 SELECT INBOX`）；含空格/中文/特殊字符时加双引号并转义 `\` 与 `"`。
     * 读到 `A02 NO` / `A02 BAD`（如文件夹不存在）也停下，由调用方看末行是否 `A02 OK`。
     *
     * @return string[] 原始响应行
     * @throws RuntimeException 超时/断连
     */
    public function selectFolder(string $folder): array
    {
        if (!$this->sock) {
            return [];
        }

        fputs($this->sock, "A02 SELECT " . $this->quoteFolder($folder) . "\r\n");
        $lines = [];
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                throw new RuntimeException('IMAP SELECT 阶段连接被关闭');
            }
            $lines[] = $line;
            if (str_contains($line, 'A02 OK')
                || str_contains($line, 'A02 NO')
                || str_contains($line, 'A02 BAD')) {
                break;
            }
        }

        return $lines;
    }

    /**
     * A05 LIST "" "*"（列出全部文件夹及其 flags）
     *
     * @return array<int,array{name:string,flags:string[]}>
     * @throws RuntimeException 超时/断连
     */
    public function listFolders(): array
    {
        if (!$this->sock) {
            return [];
        }

        fputs($this->sock, "A05 LIST \"\" \"*\"\r\n");
        $folders = [];
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                throw new RuntimeException('IMAP LIST 阶段连接被关闭');
            }
            $line = rtrim($line, "\r\n");
            if (preg_match('/^\* LIST \(([^)]*)\) "[^"]*" (.+)$/i', $line, $m)) {
                $flags = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $name  = trim($m[2]);
                if (strlen($name) >= 2 && $name[0] === '"' && substr($name, -1) === '"') {
                    $name = stripcslashes(substr($name, 1, -1));
                }
                $folders[] = ['name' => $name, 'flags' => array_values($flags)];
            }
            if (str_contains($line, 'A05 OK')) {
                break;
            }
        }

        return $folders;
    }

    /**
     * 从 LIST 结果里找 flags 含 \Junk 的文件夹名；找不到返回 null
     *
     * @return string|null
     */
    public function findJunkFolder(): ?string
    {
        foreach ($this->listFolders() as $folder) {
            foreach ($folder['flags'] as $flag) {
                if (strcasecmp($flag, '\\Junk') === 0) {
                    return $folder['name'];
                }
            }
        }

        return null;
    }

    /**
     * IMAP 文件夹名引号转义：只含可打印 ASCII 且不含 atom 特殊字符时原样返回，
     * 否则加双引号并把内部 `\` 与 `"` 转义
     */
    private function quoteFolder(string $folder): string
    {
        if (preg_match('/[^\x21-\x7E]/', $folder) || preg_match('/[(){%*"\\\\]/', $folder)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $folder) . '"';
        }

        return $folder;
    }

    /**
     * A03 SEARCH ALL
     *
     * 【D0-3 · M-3 多行修复】`* SEARCH` 可能被服务器拆成多行返回，包 C 是逐行覆盖
     * （只留最后一行 → 丢 id）。这里改成**合并**，并把 id 去重后返回。
     * 返回的仍是 array_filter/数组函数产物（键可能不连续），MailService 里照旧 rsort 再切 15 条。
     *
     * @return string[]
     * @throws RuntimeException 超时/断连
     */
    public function searchAll(): array
    {
        if (!$this->sock) {
            return [];
        }

        fputs($this->sock, "A03 SEARCH ALL\r\n");
        $ids = [];
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                throw new RuntimeException('IMAP SEARCH 阶段连接被关闭');
            }
            $line = trim($line);
            if (preg_match('/^\* SEARCH (.*)/i', $line, $match)) {
                // D0-3：合并而不是覆盖
                foreach (explode(' ', trim($match[1])) as $oneId) {
                    if ($oneId !== '') {
                        $ids[$oneId] = $oneId;
                    }
                }
            }
            if (str_contains($line, 'A03 OK')) {
                break;
            }
        }

        return array_values($ids);
    }

    /**
     * A04 FETCH n (BODY.PEEK[])
     *
     * 返回的是"原始响应拼接串"，包含 `* n FETCH (BODY.PEEK[] {len}` 行与结尾 `A04 OK` 行——
     * 与 MailService 原来直接拼 $rawMail 完全一致，所以后续 Subject/From/boundary 正则不用改。
     *
     * 【D0-2 · M-4 修复】包 C 用 RFC822，服务器会置 \Seen（用户点开面板就算已读）；
     * 改用 BODY.PEEK[] 后报文内容一模一样，但不置已读。
     *
     * 【D0-3 · M-3 literal 修复】不再用 fgets(16384) 逐行拼报文：先读响应头，
     * 从头行拿到 `{len}` 后由 readLiteral($len) 精确读满，再继续读到 `A04 OK`。
     * 这样报文里有没有换行、单行多长都不影响结果（16KB 截断随之消失）。
     *
     * @throws RuntimeException 超时/断连
     */
    public function fetchMessage(int $seq): string
    {
        if (!$this->sock) {
            throw new RuntimeException('IMAP 未连接，无法 FETCH');
        }

        // 同上：命令串里的变量名保持包 C 的 $mid（入参按执行卡叫 $seq）
        $mid = $seq;
        // M-4：BODY.PEEK[] 不置已读，包 C 的 RFC822 会置已读
        fputs($this->sock, "A04 FETCH $mid (BODY.PEEK[])\r\n");

        $rawMail   = '';
        $lenRead   = false;
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                throw new RuntimeException("IMAP FETCH $mid 阶段连接被关闭（已读 " . strlen($rawMail) . ' 字节）');
            }
            $rawMail .= $line;

            // 响应头行以 `{n}` 结尾 = 后面紧跟 n 字节 literal（大小写/空白都容忍）
            if (!$lenRead && preg_match('/\{(\d+)\}$/', rtrim($line, "\r\n"), $lit)) {
                $body = $this->readLiteral((int) $lit[1]);
                if ($body === null) {
                    throw new RuntimeException("IMAP FETCH $mid 报文 literal 未读满（期望 {$lit[1]} 字节）");
                }
                $rawMail .= $body;
                $lenRead = true;
                continue;
            }

            // IMAP 响应结束标识
            if (preg_match('/^A04 OK/m', $line)) {
                break;
            }
        }

        return $rawMail;
    }

    /**
     * 读一行；返回 null = 对端已关闭，超时 = 抛 RuntimeException
     *
     * 包 C 的 `while ($line = fgets(...))` 把"超时"和"正常结束"混为一谈（都当空行跳出），
     * 于是超时会被静默当成"这封邮件没有内容"。这里用 stream_get_meta_data 的 timed_out 区分：
     * 超时是异常（要换 host / 报错），EOF 才是"读完了"。
     *
     * @throws RuntimeException 读取超时
     */
    private function readLine(): ?string
    {
        $line = fgets($this->sock, self::LINE_LIMIT);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('IMAP 读取超时（' . $this->config->readTimeout . ' 秒无响应）');
            }
            return null;
        }

        return $line;
    }

    /**
     * 精确读满 $len 字节的 literal（不受行长限制，`fread` 可能分批返回，故循环）
     *
     * @return string|null null = 对端提前关闭
     * @throws RuntimeException 读取超时
     */
    private function readLiteral(int $len): ?string
    {
        $buf   = '';
        $left  = $len;
        $empty = 0;
        while ($left > 0) {
            $chunk = fread($this->sock, $left);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException(
                        'IMAP literal 读取超时（已读 ' . strlen($buf) . " / $len 字节）"
                    );
                }
                if (feof($this->sock)) {
                    return null;
                }
                // 极少数情况下 fread 会空返（非超时非 EOF），重试 3 次后放弃
                if (++$empty > 3) {
                    return null;
                }
                continue;
            }
            $empty = 0;
            $buf  .= $chunk;
            $left -= strlen($chunk);
        }

        return $buf;
    }

    public function close(): void
    {
        if ($this->sock) {
            fclose($this->sock);
            $this->sock = null;
        }
    }

    /**
     * 取底层 socket（仅供 D0-3 逐步改造时调试，当前业务代码不用）
     *
     * @return resource|null
     */
    public function socket()
    {
        return $this->sock;
    }
}
