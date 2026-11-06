<?php
/**
 * Microsoft Graph 邮件读取客户端（包 D · D1-1）
 *
 * 【本步定位：让 Graph 单独能跑通，不接进 MailService】
 * D1-2 的降级逻辑是"先试 Graph，失败回落 IMAP"。如果 Graph 客户端本身没验证过，
 * 降级就是空谈。所以本步只做：
 *   1. 一条 GET /me/messages（$top / $select 固定）
 *   2. 把响应映射成与 IMAP 路径**逐字节一致**的 {value:[...]} 结构
 *   3. 任何失败直接抛 RuntimeException（不静默、不重试、不降级）
 * MailService::getMails 本步仍只走 IMAP——接入是 D1-2 的事。
 *
 * 【为什么返回 {value:[...]} 而不是裸数组】
 * 前端零改动的唯一前提是响应体一致。MailService::getMails 的返回契约就是
 * `{'value': [...]}` / `{'error': '...'}`，Graph 客户端直接产出同一个外壳，
 * D1-2 接降级时才能写成 `return $graph->fetchMessages($token);` 而不用二次包装。
 *
 * 【字段映射（与 MailService::getMails 的拼装逐字段对齐）】
 *   Graph 响应                                   → 本项目响应
 *   ----------------------------------------------------------------
 *   subject                                      → subject
 *   from.emailAddress.name                       → from.emailAddress.name
 *   body.content                                 → body.content
 * Graph 返回的其它字段（id / receivedDateTime / body.contentType /
 * from.emailAddress.address ...）**一律丢弃**——IMAP 路径本来就不产出它们，
 * 多带一个键就会让 JSON 字节数变化、前端拿到的结构漂移。
 *
 * 【本步不做的事】
 *  - 不做降级、不做重试、不做分页（nextLink）
 *  - 不读 settings（mail_mode 由 D1-2 的调用方决定）
 *  - 不碰 ImapClient / MimeParser / MailService
 */
final class GraphClient
{
    /** 资源路径：只取这一条，写成常量便于差集断言 */
    private const PATH_MESSAGES = '/me/messages';

    /** $select 固定三字段：与 IMAP 路径解析出的三样东西一一对应 */
    private const SELECT_FIELDS = 'subject,from,body';

    /** 授权头前缀（Bearer 与 token 之间是一个空格） */
    private const AUTH_HEADER = 'Authorization: Bearer ';

    private GraphConfig $config;

    public function __construct(GraphConfig $config)
    {
        $this->config = $config;
    }

    /**
     * 取最近 N 封邮件：GET {baseUrl}/me/messages?$top=15&$select=subject,from,body
     *
     * @param  string $accessToken Graph 访问令牌（与 IMAP 用的 XOAUTH2 token 同一个）
     * @return array{value:array<int,array{subject:string,from:array{emailAddress:array{name:string}},body:array{content:string}}>}
     * @throws RuntimeException cURL 失败 / 非 200 / 非 JSON / 缺 value
     */
    public function fetchMessages(string $accessToken): array
    {
        $url = rtrim($this->config->baseUrl, '/') . self::PATH_MESSAGES
             . '?$top=' . $this->config->top
             . '&$select=' . self::SELECT_FIELDS;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Graph 请求初始化失败');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeout,
            CURLOPT_TIMEOUT        => $this->config->timeout,
            CURLOPT_HTTPHEADER     => [self::AUTH_HEADER . $accessToken],
        ]);

        $raw = curl_exec($ch);

        // cURL 层失败（DNS / 连接被拒 / 超时）：$raw === false
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Graph 请求失败: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 401 也走这条分支：调用方据此判断"token 失效"
        if ($status !== 200) {
            throw new RuntimeException('Graph 返回 HTTP ' . $status . '：' . self::snippet((string) $raw));
        }

        $data = json_decode((string) $raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Graph 响应不是合法 JSON（' . json_last_error_msg() . '）：' . self::snippet((string) $raw)
            );
        }

        if (!is_array($data) || !isset($data['value']) || !is_array($data['value'])) {
            throw new RuntimeException('Graph 响应缺少 value 数组：' . self::snippet((string) $raw));
        }

        $messages = [];
        foreach ($data['value'] as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Graph 响应 value 中存在非对象元素：' . self::snippet((string) $raw));
            }
            $messages[] = self::mapMessage($item);
        }

        return ['value' => $messages];
    }

    /**
     * 单封邮件映射：只取三个字段，键序与 IMAP 路径的拼装完全一致
     *
     * 字段缺失时给空串而不是 null：IMAP 路径同位置永远是 string
     * （无主题时是 '无主题'，这里是 ''——但绝不出现 null，前端不需要多一套判空）。
     *
     * @param  array<string,mixed> $item Graph 的单条 message
     * @return array{subject:string,from:array{emailAddress:array{name:string}},body:array{content:string}}
     */
    private static function mapMessage(array $item): array
    {
        return [
            'subject' => (string) ($item['subject'] ?? ''),
            'from'    => [
                'emailAddress' => [
                    'name' => (string) ($item['from']['emailAddress']['name'] ?? ''),
                ],
            ],
            'body'    => [
                'content' => (string) ($item['body']['content'] ?? ''),
            ],
            // G-1：与 IMAP 路径保持结构一致。Graph 的 /me/messages 已包含所有文件夹且不区分来源，
            // 无法廉价判断某封邮件属于 INBOX 还是 Junk，故固定标 unknown（IMAP 路径标 inbox/junk）。
            'folder'  => 'unknown',
        ];
    }

    /** 错误信息里只带前 200 字节响应体，避免日志被整页 HTML/JSON 刷屏 */
    private static function snippet(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '(空响应)';
        }

        return strlen($raw) > 200 ? substr($raw, 0, 200) . '…' : $raw;
    }
}
