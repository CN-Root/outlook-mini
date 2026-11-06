<?php
/**
 * 对称加解密工具（libsodium secretbox）
 *
 * 密钥来源：环境变量 APP_KEY，内容为 base64 编码的 32 字节密钥
 * 密文格式：base64(nonce . cipher)，nonce 每条随机
 * 失败策略：密钥/扩展不可用时抛异常，绝不静默降级为明文
 */

final class Crypto
{
    /** @var string|null 已解析的原始密钥（32 字节） */
    private static $key = null;

    /**
     * 读取并校验 APP_KEY，结果在进程内缓存
     *
     * @throws RuntimeException 扩展缺失 / 变量未设置 / 密钥长度错误
     */
    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        if (!extension_loaded('sodium')) {
            error_log('[OutlookPanel] 加密不可用：PHP 未启用 sodium 扩展');
            throw new RuntimeException('sodium extension not loaded');
        }

        $b64 = getenv('APP_KEY');
        if ($b64 === false || trim($b64) === '') {
            error_log('[OutlookPanel] 加密不可用：环境变量 APP_KEY 未设置');
            throw new RuntimeException('APP_KEY not configured');
        }

        $raw = base64_decode(trim($b64), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            error_log('[OutlookPanel] 加密不可用：APP_KEY 必须是 base64 编码的 ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' 字节密钥');
            throw new RuntimeException('APP_KEY malformed');
        }

        return self::$key = $raw;
    }

    /**
     * 加密明文，返回 base64(nonce . cipher)
     *
     * @throws RuntimeException 密钥不可用
     */
    public static function encrypt(string $plain): string
    {
        $key   = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

        return base64_encode($nonce . $cipher);
    }

    /**
     * 解密 base64(nonce . cipher)
     *
     * @return string|null 成功返回明文；不是合法密文（含旧明文数据）返回 null
     */
    public static function decrypt(string $cipher): ?string
    {
        try {
            $key = self::key();
        } catch (Throwable $e) {
            // 密钥不可用时无法判定，按"非密文"处理，由调用方决定后续动作
            return null;
        }

        $raw = base64_decode($cipher, true);
        if ($raw === false) {
            return null;
        }

        $nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $nonce_len) {
            return null;
        }

        $nonce  = substr($raw, 0, $nonce_len);
        $body   = substr($raw, $nonce_len);
        $plain  = sodium_crypto_secretbox_open($body, $nonce, $key);

        return $plain === false ? null : $plain;
    }
}
