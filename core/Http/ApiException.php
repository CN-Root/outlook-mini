<?php
/**
 * 业务异常载体（包 C Step 1 契约）
 *
 * payload 原样承载，构造时不做任何加工：
 *  - 现状是 {"error":"Token失效，请重新获取账号"}，就存 ['error' => 'Token失效，请重新获取账号']
 *  - 现状是 {"status":"error","message":"..."}，就原样存这两个键
 *  - 不补字段、不改字段名、不加 status、不统一 schema
 *
 * 这条契约是"重构前后响应逐字节一致"的前提，改动它等于改前端契约。
 */
final class ApiException extends RuntimeException
{
    /** @var int 输出的 HTTP 状态码，默认 200（现状绝大多数错误就是 200） */
    private int $httpStatus;

    /** @var array 待 json_encode 的 payload，原样保存 */
    private array $payload;

    /**
     * @param array          $payload    原样输出，禁止归一化
     * @param int            $httpStatus 200 / 400 / 404 / 409 / 413 / 500（与现状逐 action 对齐）
     * @param Throwable|null $previous   底层异常，仅用于错误日志
     */
    public function __construct(array $payload, int $httpStatus = 200, ?Throwable $previous = null)
    {
        parent::__construct('api error', $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->payload    = $payload;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array 原样返回，调用方不得增删键 */
    public function payload(): array
    {
        return $this->payload;
    }
}
