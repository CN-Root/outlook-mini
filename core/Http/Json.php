<?php
/**
 * JSON 输出封装（包 C Step 1）
 *
 * 两条铁律，写在代码里是为了防止后续步骤误改：
 *
 *  1. 本类任何方法内部都不许 exit。
 *     现状 api.php 有 29 处 echo，但只有 27 处 exit——说明有调用点在输出后还要继续执行。
 *     一旦把 exit 挪进封装，多出来的那两处行为会被悄悄改掉。exit 一律留在调用点，
 *     保证"换输出方式"这件事对控制流零影响。
 *
 *  2. 不加工 payload：不改字段名、不补 status、不做 schema 归一化，只 json_encode + 输出。
 *     现状并存 5 种响应形态（裸数组 / 裸对象 / {"error":...} / {"status":"error",...} /
 *     {"status":"success",...}），统一化属于改业务契约，不在本包范围内。
 */
final class Json
{
    /**
     * 原样输出 payload
     *
     * @param array $payload    调用方给定的完整 payload
     * @param int   $httpStatus 仅当 != 200 时才调用 http_response_code（与现状一致）
     */
    public static function send(array $payload, int $httpStatus = 200): void
    {
        if ($httpStatus !== 200) {
            http_response_code($httpStatus);
        }

        echo json_encode($payload);
    }

    /**
     * 输出 ApiException 携带的 payload 与状态码，逐字节不做改写
     */
    public static function fail(ApiException $e): void
    {
        self::send($e->payload(), $e->httpStatus());
    }
}
