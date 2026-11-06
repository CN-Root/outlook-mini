<?php
/**
 * Microsoft Graph 请求配置（包 D · D1-1）
 *
 * 【本步定位：只承载 Graph 客户端自己的参数，不改任何既有行为】
 * 默认值刻意与项目里已有的 cURL 调用保持一致：
 *   connectTimeout → 包 A 的 CURLOPT_CONNECTTIMEOUT = 5
 *   timeout        → 包 A 的 CURLOPT_TIMEOUT = 20
 *   top            → 与 IMAP 路径一致的 15（MailService::getMails 的 array_slice(..., 0, 15)）
 * 这样 D1-2 做"Graph 优先、失败回落 IMAP"时，两条路径的取数规模不会悄悄不一致。
 *
 * 与 ImapConfig 的分工：
 *   ImapConfig  → 主机/端口/SSL（IMAP 协议栈自己的事）
 *   GraphConfig → 只有一个 baseUrl（Graph 是纯 HTTPS + Bearer，没有端口/SSL 开关要配）
 *
 * 测试时把 baseUrl 指向 http://127.0.0.1:14301/v1.0 即可连本地假 Graph 服务器。
 */
final class GraphConfig
{
    /** Graph API 根地址（不含尾斜杠的用法由 GraphClient 兜底 rtrim） */
    public string $baseUrl;

    /** `CURLOPT_CONNECTTIMEOUT`（连接超时，秒） */
    public int $connectTimeout;

    /** `CURLOPT_TIMEOUT`（整个请求超时，秒） */
    public int $timeout;

    /** `$top`：取最近多少封，默认 15 与 IMAP 路径一致 */
    public int $top;

    public function __construct(
        string $baseUrl = 'https://graph.microsoft.com/v1.0',
        int $connectTimeout = 5,
        int $timeout = 20,
        int $top = 15
    ) {
        $this->baseUrl        = $baseUrl;
        $this->connectTimeout = $connectTimeout;
        $this->timeout        = $timeout;
        $this->top            = $top;
    }

    /**
     * 从数组覆盖默认值（只认已知键，未知键忽略——与 ImapConfig::fromArray 同一策略）
     */
    public static function fromArray(array $overrides): self
    {
        $defaults = new self();

        return new self(
            (string) ($overrides['baseUrl'] ?? $defaults->baseUrl),
            (int) ($overrides['connectTimeout'] ?? $defaults->connectTimeout),
            (int) ($overrides['timeout'] ?? $defaults->timeout),
            (int) ($overrides['top'] ?? $defaults->top)
        );
    }
}
