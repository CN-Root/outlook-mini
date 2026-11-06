<?php
/**
 * IMAP 连接配置（包 D · D0-1）
 *
 * 【本步定位：只把"连接参数"抽出来，不改任何协议行为】
 * 抽取的原则是"默认值必须与包 C 的硬编码逐字节一致"：
 *   hosts            → 原 `$hosts = ['outlook.live.com', 'outlook.office365.com']`
 *   port             → 原 `fsockopen(..., 993, ...)`
 *   connectTimeout   → 原 `fsockopen(..., 10)` 的第 5 个参数
 *   readTimeout      → 原代码**没有**，本步新增但只传给 stream_set_timeout（不生效的占位，
 *                      真正的读取超时逻辑在 D0-3，见 ImapClient 注释）
 *   ssl              → 原 `'ssl://'` 前缀
 *
 * 不传覆盖时，行为与改造前完全一致——这是本步能被 12/12 快照验收的前提。
 * 测试时把 hosts 指向 127.0.0.1 + ssl=false，就能连本地假服务器跑通 A01–A04。
 */
final class ImapConfig
{
    /** @var string[] 依次尝试的 IMAP 主机，与包 C 的尝试顺序一致 */
    public array $hosts;

    public int $port;

    /** `fsockopen` 的第 5 个参数（连接超时，秒） */
    public int $connectTimeout;

    /** 只交给 `stream_set_timeout`，D0-3 之前不影响读取行为 */
    public int $readTimeout;

    /** true → `ssl://`；false → `tcp://`（本地假服务器用） */
    public bool $ssl;

    public function __construct(
        array $hosts = ['outlook.live.com', 'outlook.office365.com'],
        int $port = 993,
        int $connectTimeout = 10,
        int $readTimeout = 30,
        bool $ssl = true
    ) {
        $this->hosts          = $hosts;
        $this->port           = $port;
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout    = $readTimeout;
        $this->ssl            = $ssl;
    }

    /**
     * 从数组覆盖默认值（只认已知键，未知键忽略——避免测试脚本传错键却静默生效）
     */
    public static function fromArray(array $overrides): self
    {
        $defaults = new self();

        return new self(
            $overrides['hosts'] ?? $defaults->hosts,
            (int) ($overrides['port'] ?? $defaults->port),
            (int) ($overrides['connectTimeout'] ?? $defaults->connectTimeout),
            (int) ($overrides['readTimeout'] ?? $defaults->readTimeout),
            (bool) ($overrides['ssl'] ?? $defaults->ssl)
        );
    }
}
