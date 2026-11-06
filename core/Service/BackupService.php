<?php
/**
 * 备份服务：tg_backup 的业务层（包 C Step 5 原样搬迁）
 *
 * 逻辑没拆也没修：读全表 → JSON 序列化 → 读 settings → 校验 TG 配置 → 临时文件 → cURL
 * → 删临时文件。包 C 的定位只是让它离开 api.php，任何"顺手优化"（比如改成流式上传、
 * 换成 multipart 手写、加重试）都留到后续包，避免快照与真实发送行为同时变化。
 *
 * 与改造前的等价性说明：
 *  - `SELECT * FROM accounts ORDER BY id DESC` → findAllForBackup()（保留 SELECT *，列序即 JSON 键序）
 *  - `SELECT * FROM settings` + while fetch → SettingRepository::all()
 *  - 返回数组取代原来的 3 处 echo：未配置 / CURL 错误 / TG 返回错误 / 成功
 */
final class BackupService
{
    private AccountRepository $accounts;
    private SettingRepository $settings;

    public function __construct(AccountRepository $accounts, SettingRepository $settings)
    {
        $this->accounts = $accounts;
        $this->settings = $settings;
    }

    /**
     * 把全量账号 JSON 发到 Telegram（sendDocument）
     *
     * 注意：调用方必须已登录。本方法会真的发外网请求，测试只能在副本上用未配置分支，
     * 或显式打桩——曲线救国式的"跑一次看看"会污染 TG 群，也会消耗真实 token。
     *
     * @return array{status:string, message:string}
     */
    public function tgBackup(): array
    {
        // 1. 获取所有账号数据
        $accounts  = $this->accounts->findAllForBackup();
        $json_data = json_encode($accounts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // 2. 获取 TG 配置
        $settings = $this->settings->all();

        $token   = trim($settings['tg_token'] ?? '');
        $chat_id = trim($settings['tg_chatid'] ?? '');

        if (empty($token) || empty($chat_id)) {
            return ['status' => 'error', 'message' => '请先在设置中配置 TG Token 和 ChatID'];
        }

        // 3. 发送 JSON 文件到 Telegram (推荐发送文件，因为账号多了会触发消息长度限制)
        // 创建临时文件
        $tmpFile = tempnam(sys_get_temp_dir(), 'backup_');
        file_put_contents($tmpFile, $json_data);

        $url = "https://api.telegram.org/bot{$token}/sendDocument";
        $post_data = [
            'chat_id'  => $chat_id,
            'document' => new CURLFile($tmpFile, 'application/json', 'outlook_backup_' . date('Ymd_His') . '.json'),
            'caption'  => "📅 Outlook Panel 自动备份\n⏰ 时间: " . date('Y-m-d H:i:s') . "\n👤 账号总数: " . count($accounts),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_data,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        // 删除临时文件
        @unlink($tmpFile);

        if ($err) {
            return ['status' => 'error', 'message' => 'CURL 错误: ' . $err];
        }

        $res_arr = json_decode($res, true);
        if ($res_arr && $res_arr['ok']) {
            return ['status' => 'success', 'message' => '备份已发送至 Telegram'];
        }

        return ['status' => 'error', 'message' => 'TG 返回错误: ' . ($res_arr['description'] ?? '未知')];
    }
}
