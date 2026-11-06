<?php
/**
 * 邮件服务：get_mails 的业务层（包 C Step 5 原样搬迁）
 *
 * 【本步定位：只搬不拆】
 * 这个方法是本项目最复杂的 action：OAuth 刷新 + IMAP 会话 + MIME 解析 + 视图拼装四领域
 * 全挤在一个方法里。包 C 只负责**把它从 api.php 移到 core/Service/**，一行逻辑都不改；
 * 拆领域、修 IMAP（M-3/M-4/M-5）、改抓取策略都是包 D 的 D0/D1。
 *
 * 因此这里刻意保留以下"不美观但必须原样"的东西，改之前请先问包 D 的执行卡：
 *  - 只取最近 15 封、只试 ImapConfig 里的 host 列表（默认 outlook.live.com / outlook.office365.com）
 *  - 用正则从原始响应里抠 Subject/From/boundary，而不是用 imap_* 扩展
 *  - 四领域同处一个方法，共享 $email / $access_token 等局部变量
 *
 * 【包 D · D0-1 追加：连接层可配置 + 可注入】
 * 手搓的 IMAP 协议字符串与 fsockopen/fputs/fgets 已整体搬进 `core/Mail/ImapClient.php`
 * （字面量逐字保留），连接参数搬进 `core/Mail/ImapConfig.php`（默认值 = 原硬编码）。
 * 本步**不改任何协议行为**：仍是 RFC822、仍只取 15 封、仍无读取超时兜底、
 * 仍不支持 literal / SEARCH 多行——M-3/M-4/M-5 分别在 D0-3 / D0-2 / D0-3 修。
 * 本类现在不再直接持有 socket，所有 socket 操作集中在 ImapClient。
 *
 * 与改造前的等价性说明：
 *  - 原 `$db->query("SELECT * FROM accounts WHERE id=$id")->fetch()` → findById()
 *    （$id 已被 (int) 收敛，等价；取到的列是超集，用到的三列都有）
 *  - 原 `$db->prepare("UPDATE accounts SET refresh_token=? WHERE id=?")` → updateRefreshToken()
 *  - 返回值取代了原来的 3 处 `echo ...; exit;`：
 *      {'value': []} / {'error': 'Token失效，请重新获取账号'} / {'value': [...]}
 *
 * 【包 D · D1-2 追加：三模式分发（包 D 收尾）】
 * mail_mode（settings 表）→ 三种取邮件方式，**IMAP 代码块一行未动**，只在外层加分流：
 *   imap  → 只走下面那段 IMAP（= 包 C/D0 的行为；也是 mail_mode 缺失或非法时的兜底）
 *   graph → 只调 GraphClient，失败**不降级**，转成契约错误 {'error': 'Graph 获取邮件失败'}（HTTP 200）
 *   auto  → 先 Graph；Graph 抛 RuntimeException 就记一行日志、继续走 IMAP（唯一会降级的模式）
 *
 * 错误分层（本步定型的契约）：
 *   GraphClient   ：基础设施层，一律抛 RuntimeException（D1-1 已验收）
 *   MailService   ：契约层。auto 吞掉并降级；graph 用 ApiException 把底层原因收成
 *                   {'error': ...} + HTTP 200（与既有的 {'error':'Token失效…'} 同构，
 *                   dashboard 的 `if (data.error)` 分支直接显示红字，不会误报"收件箱为空"）
 *   api.php       ：只换构造参数，错误处理沿用既有 catch 漏斗，一行未改
 *
 * 日志纪律：error_log 只拼 $e->getMessage()。GraphClient 的异常消息只含状态码与
 * 响应体前 200 字节，**不含 Authorization 头**——所以降级日志里不可能出现 token。
 *
 * 【包 E · E-4 追加：正文白名单净化（H-2）】
 * body.content 在写进响应之前一律过 HtmlSanitizer::sanitize——IMAP 路径在拼装处逐封净化，
 * Graph 路径在 fetchViaGraph / auto 拿到 {value:[...]} 后统一净化（见 sanitizeValue()）。
 * 净化只动 body.content 一个字段，响应结构、键序、subject/from 全都不变。
 */
require_once __DIR__ . '/../Mail/HtmlSanitizer.php';

final class MailService
{
    private AccountRepository $accounts;

    /** D0-1：IMAP 连接参数可注入；不注入时默认值 = 包 C 里硬编码的那一套 */
    private ImapConfig $imapConfig;

    /**
     * D0-1：测试用 AccessToken 注入
     *
     * 为什么需要这个缝：本类是 final，没法用子类覆盖"刷新 Token"那一段，而
     * 假服务器验收又必须先拿到 access_token 才能发 A01。生产路径传 null，
     * 走 requestAccessToken()（原 cURL 代码原样搬入，逐字未改）。
     *
     * @var callable(string,string):?array|null
     */
    private $tokenProvider;

    /** D1-2：读 mail_mode；不注入 = 不启用多模式（固定走 IMAP，与包 C 一致） */
    private ?SettingRepository $settings;

    /** D1-2：Graph 客户端；不注入 = 不具备 Graph 能力（auto 会直接走 IMAP，graph 模式返回错误） */
    private ?GraphClient $graph;

    /**
     * @param ImapConfig|null               $imapConfig    连接参数；null = 默认（真实 outlook）
     * @param callable|null                 $tokenProvider 仅测试用；null = 走真实 OAuth 刷新
     * @param SettingRepository|null        $settings      D1-2：mail_mode 来源；null = 固定 imap
     * @param GraphClient|null              $graph         D1-2：Graph 客户端；null = 无 Graph 能力
     */
    public function __construct(
        AccountRepository $accounts,
        ?ImapConfig $imapConfig = null,
        ?callable $tokenProvider = null,
        ?SettingRepository $settings = null,
        ?GraphClient $graph = null
    ) {
        $this->accounts      = $accounts;
        $this->imapConfig    = $imapConfig ?? new ImapConfig();
        $this->tokenProvider = $tokenProvider;
        $this->settings      = $settings;
        $this->graph         = $graph;
    }

    /**
     * 取某账号最近 15 封邮件（发件人 + 主题 + 正文）
     *
     * @param  int $id 账号 id；0 或不存在都走 {'value': []}（与改造前一致）
     * @return array{value?:array, error?:string}
     * @throws ApiException graph 模式下 Graph 取信失败（HTTP 200 + {'error': 'Graph 获取邮件失败'}）
     */
    public function getMails(int $id): array
    {
        // D1-2：三模式分发的取值。未注入 settings（老调用方）→ 固定 imap，行为与包 C 一致。
        // 空串按 auto 处理（与 SettingService::getSettings 的兜底保持一致）；
        // 未知值（库里被手改成 'xxx'）走 imap —— 宁可走已验证的老路，也不让脏值把用户挡在门外。
        $mode = 'imap';
        if ($this->settings !== null) {
            $mode = $this->settings->get('mail_mode');
            $mode = ($mode === null || $mode === '') ? 'auto' : $mode;
        }

        $acc = $this->accounts->findById($id);
        if (!$acc) {
            return ['value' => []];
        }

        $email         = trim($acc['email']);
        $client_id     = trim($acc['client_id']);
        $refresh_token = trim($acc['refresh_token']);

        // 刷新 AccessToken（D0-1：注入时用注入的，否则走原 cURL 逻辑）
        $res = $this->tokenProvider !== null
            ? ($this->tokenProvider)($client_id, $refresh_token)
            : $this->requestAccessToken($client_id, $refresh_token);

        if (empty($res['access_token'])) {
            return ['error' => 'Token失效，请重新获取账号'];
        }

        // 更新 Token（微软会轮换 refresh_token，这是本项目唯一的"读操作顺手写库"）
        if (!empty($res['refresh_token']) && $res['refresh_token'] != $refresh_token) {
            $this->accounts->updateRefreshToken($id, (string) $res['refresh_token']);
        }

        // D1-2：Graph 与 IMAP 共用这一个 access_token——requestAccessToken() 只调一次，
        // 降级时不再换 token（否则一次 get_mails 会打两次 OAuth，还可能把 refresh_token 转掉两次）。
        $access_token = $res['access_token'];

        // ==== graph：只用 Graph，失败不降级（异常由 fetchViaGraph 收成契约错误）====
        if ($mode === 'graph') {
            return $this->fetchViaGraph($access_token);
        }

        // ==== auto：Graph 优先，失败记一行日志后继续往下走 IMAP ====
        if ($mode === 'auto' && $this->graph !== null) {
            try {
                return $this->sanitizeValue($this->graph->fetchMessages($access_token));
            } catch (RuntimeException $e) {
                // 只记异常消息：GraphClient 的消息里只有状态码 + 响应片段，不含 Authorization 头
                error_log('[OutlookPanel] Graph 失败，降级 IMAP: ' . $e->getMessage());
                // 不 return，落到下面的 IMAP 代码块
            }
        }

        // ==== imap（含 auto 降级）：INBOX + Junk Email 各取最新 15 封，按时间合并取前 15 ====
        $merged = [];
        foreach ($this->imapConfig->hosts as $host) {
            $client = new ImapClient($this->imapConfig, $host);
            if (!$client->connect($email, $access_token)) {
                continue;
            }

            // 动态发现 Junk 文件夹（LIST "" "*" 找 \Junk，不硬编码）；LIST 失败则换 host
            try {
                $junkFolder = $client->findJunkFolder();
            } catch (RuntimeException $e) {
                $client->close();
                continue;
            }

            // INBOX 是主文件夹：读失败就换下一个 host（与包 C 的 continue 语义一致）
            $inboxMsgs = $this->readFolder($client, 'INBOX', 'inbox');
            if ($inboxMsgs === null) {
                $client->close();
                continue;
            }

            $merged = $inboxMsgs;
            // Junk 存在则追加；SELECT/SEARCH/FETCH 任一失败都静默跳过，只返回 INBOX 的 15 封
            if ($junkFolder !== null) {
                $junkMsgs = $this->readFolder($client, $junkFolder, 'junk');
                if ($junkMsgs !== null) {
                    $merged = array_merge($merged, $junkMsgs);
                }
            }

            $client->close();
            break;
        }

        // 跨文件夹按时间倒序（Date 头优先，缺失退回 SEARCH 序号——单文件夹内仍代表新旧），取前 15
        usort($merged, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
        $messages = [];
        foreach (array_slice($merged, 0, 15) as $one) {
            $messages[] = $one['mail'];
        }

        return ['value' => $messages];
    }

    /**
     * 读取单个文件夹的最新 15 封邮件
     *
     * 返回每封 `{ts, mail}`：ts 是跨文件夹排序键（优先 Date 头，解析不出退回 SEARCH 序号），
     * mail 是最终视图对象（subject/from/body/folder，folder 在对象末尾）。
     * 返回 null 表示该文件夹不可用（SELECT 失败 / SEARCH 为空 / 读取断连），由调用方决定跳过或换 host。
     *
     * @return array<int,array{ts:int,mail:array}>|null
     */
    private function readFolder(ImapClient $client, string $folder, string $tag): ?array
    {
        try {
            $resp = $client->selectFolder($folder);
            $ok   = false;
            foreach ($resp as $line) {
                if (str_contains($line, 'A02 OK')) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return null;
            }
            $ids = $client->searchAll();
        } catch (RuntimeException $e) {
            return null;
        }

        if (empty($ids)) {
            return null;
        }

        rsort($ids, SORT_NUMERIC);
        $ids = array_slice($ids, 0, 15); // 获取最近15封

        $out = [];
        foreach ($ids as $mid) {
            try {
                $rawMail = $client->fetchMessage((int) $mid);
            } catch (RuntimeException $e) {
                return null; // 连接不可用，整文件夹作废
            }

            // 提取主题和发件人
            preg_match('/^Subject:\s*(.*?)$/im', $rawMail, $subjMat);
            $subject = isset($subjMat[1]) ? iconv_mime_decode(trim($subjMat[1]), 0, 'UTF-8') : '无主题';

            preg_match('/^From:\s*(.*?)$/im', $rawMail, $fromMat);
            $from = isset($fromMat[1]) ? iconv_mime_decode(trim($fromMat[1]), 0, 'UTF-8') : '未知发件人';

            // 处理 Boundary
            preg_match('/boundary="?([^"\r\n;]+)"?/is', $rawMail, $bndMat);
            $boundary = $bndMat[1] ?? '';

            $body = '';
            if ($boundary) {
                $parts = explode("--$boundary", $rawMail);
                // 优先取 HTML
                foreach ($parts as $part) {
                    if (stripos($part, 'Content-Type: text/html') !== false) {
                        $body = extract_message_body($part);
                        break;
                    }
                }
                // 没 HTML 取纯文本
                if (empty($body)) {
                    foreach ($parts as $part) {
                        if (stripos($part, 'Content-Type: text/plain') !== false) {
                            $body = extract_message_body($part);
                            break;
                        }
                    }
                }
            } else {
                // 非多段格式
                $body = extract_message_body($rawMail);
            }

            // 跨文件夹排序键：优先 Date 头；解析不出退回 SEARCH 序号（单文件夹内仍代表新旧）
            $ts = 0;
            if (preg_match('/^Date:\s*(.*?)$/im', $rawMail, $dateMat)) {
                $parsed = strtotime(trim($dateMat[1]));
                if ($parsed !== false) {
                    $ts = $parsed;
                }
            }
            if ($ts === 0) {
                $ts = (int) $mid;
            }

            $out[] = [
                'ts'   => $ts,
                'mail' => [
                    'subject' => $subject,
                    'from'    => ['emailAddress' => ['name' => $from]],
                    // E-4（H-2）：正文写进响应前先过白名单净化，前端 document.write 不改
                    'body'    => ['content' => HtmlSanitizer::sanitize($body)],
                    // G-1：folder 标记来源，加在 body 之后、对象末尾，不影响前三字段键序
                    'folder'  => $tag,
                ],
            ];
        }

        return $out;
    }

    /**
     * graph 模式取信：只调 Graph，**绝不降级**
     *
     * 失败时把 RuntimeException 收成 ApiException(['error' => 'Graph 获取邮件失败'], 200)：
     *  - 200 是本项目 get_mails 的错误惯例（{'error':'Token失效…'} 也是 200），
     *    前端 `if (data.error)` 直接显示红字；
     *  - 若直接抛 RuntimeException，api.php 的 catch(Throwable) 会回 500 + {'status':'error',…}，
     *    而 dashboard 只认 data.error/data.value → 会误显示"收件箱为空"，把故障说成空邮箱。
     *  - 原始异常挂在 previous 上（error_log 里保留底层原因），调用方仍可用 catch (RuntimeException) 接住。
     *
     * @param  string $accessToken 与 IMAP 共用的那个 token
     * @return array{value?:array, error?:string}
     * @throws ApiException 底层 RuntimeException 包装后抛出
     */
    private function fetchViaGraph(string $accessToken): array
    {
        if ($this->graph === null) {
            // 配置为 graph 但没注入客户端（理论上生产不会出现）：明确报错，不冒险降级
            return ['error' => 'Graph 未启用'];
        }

        try {
            return $this->sanitizeValue($this->graph->fetchMessages($accessToken));
        } catch (RuntimeException $e) {
            error_log('[OutlookPanel] Graph 获取失败（mail_mode=graph，不降级）: ' . $e->getMessage());

            throw new ApiException(['error' => 'Graph 获取邮件失败'], 200, $e);
        }
    }

    /**
     * E-4（H-2）：把 Graph 路径产出的 {value:[...]} 里每封邮件的 body.content 过一遍白名单净化。
     *
     * IMAP 路径在拼装处逐封净化（见上方 $messages[]），Graph 路径只有拿到整段返回值后
     * 才有机会改，所以在这里统一兜一遍。error 形态（{'error':...}）没有 value，原样透传；
     * value 里的每封邮件只动 body.content 一个字段，subject/from/键序全都不变。
     *
     * @param  array $result GraphClient::fetchMessages 的返回值
     * @return array 同结构，body.content 已净化
     */
    private function sanitizeValue(array $result): array
    {
        if (isset($result['value']) && is_array($result['value'])) {
            foreach ($result['value'] as $i => $m) {
                if (is_array($m) && isset($m['body']['content'])) {
                    $result['value'][$i]['body']['content'] = HtmlSanitizer::sanitize((string) $m['body']['content']);
                }
            }
        }

        return $result;
    }

    /**
     * 刷新 AccessToken（原 api.php 的 cURL 段原样搬入私有方法，D0-1 只是挪位置）
     *
     * 保持原语义：返回 json_decode 的产物（失败为 null），由调用方用 empty() 判定；
     * 不加异常、不改超时、不改 URL。D1 引入 Graph 后会在这里分叉。
     *
     * @return array|null
     */
    private function requestAccessToken(string $client_id, string $refresh_token): ?array
    {
        $data = [
            'client_id'     => $client_id,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ];

        $ch = curl_init('https://login.microsoftonline.com/consumers/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $res = json_decode($res, true);

        return $res;
    }
}
