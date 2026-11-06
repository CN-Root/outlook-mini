<?php
/**
 * 设置服务：get_settings / save_settings 的业务层
 *
 * 职责边界：
 *  - 不读 $_POST / $_GET / $_FILES：字段数组由入口传入
 *  - 不 echo、不 http_response_code：成功返回数组，失败抛 ApiException
 *  - 只做业务判断（哪些字段要写、空值怎么办、密码怎么哈希），SQL 在 SettingRepository
 *
 * 进度：Step 4 搬了 get_settings / save_settings；D1-1 追加 mail_mode。
 */
final class SettingService
{
    /**
     * mail_mode 的合法取值（包 D · D1-1）
     *
     *   auto  —— Graph 优先，失败回落 IMAP（D1-2 实现；本步只是把值存下来）
     *   graph —— 只用 Graph
     *   imap  —— 只用 IMAP（= D0 的行为，兼容老库默认值）
     */
    private const MAIL_MODES = ['auto', 'graph', 'imap'];

    private SettingRepository $settings;

    public function __construct(SettingRepository $settings)
    {
        $this->settings = $settings;
    }

    /**
     * 读设置：settings 全表 + 管理员用户名
     *
     * 对应原 api.php:409-419：
     *   $data = [全表 key=>value]；$data['admin_user'] = username ?? 'admin'
     * 键序即响应字节序：admin_user 必须**追加在最后**，不能并进前面的映射里。
     *
     * D1-1 追加 mail_mode 兜底：库里有这一行时位置由 settings 的 rowid 决定
     * （config.php 的种子把它放在 tg_chatid 之后，即 admin_user 之前）；
     * 老库没有这一行时用 'auto' 补在所有 settings 键之后，同样不改变 admin_user 收尾的约定。
     *
     * @return array<string, string|null>
     */
    public function getSettings(): array
    {
        $data  = $this->settings->all();
        $admin = $this->settings->getAdmin();

        // D1-1：老库/空值兜底 'auto'——前端拿到的永远是三个合法值之一
        if (!isset($data['mail_mode']) || $data['mail_mode'] === '') {
            $data['mail_mode'] = 'auto';
        }

        $data['admin_user'] = $admin['username'] ?? 'admin';

        return $data;
    }

    /**
     * 存设置：TG 两项 + mail_mode + 管理员用户名/密码
     *
     * 逐条照抄原 api.php:262-284 的判定方式，**故意不"改进"**：
     *  - TG：`isset()` 判定 —— 传空串也会写进去（前端清空输入框靠的就是这条）
     *  - 管理员名/密码：`!empty()` 判定 —— 传空串等于"不改"，不是"清空"
     *  - 没有任何重名校验、长度校验、当前密码校验（现状就没有，本步不新增）
     *  - 写库顺序：mail_mode（先校验）→ TG 两项 → 管理员名 → 管理员密码
     *    （把可能失败的校验放最前，非法值就不会留下"TG 写了、mail_mode 没写"的半截状态）
     *
     * D1-1 的 mail_mode 采用「未传=不动，传了就必须合法」：
     *  - 不在 $fields 里 → 完全不动（老前端/快照里的 save_settings 不受影响）
     *  - 传了非法值 → 抛 ApiException(400)，**绝不写库**（不静默写成非法值，
     *    也不静默退回 auto——那会让用户以为自己的选择生效了）
     *
     * @param  array<string, mixed> $fields tg_token / tg_chatid / mail_mode / admin_user / admin_pass
     * @return array{status:string}
     * @throws ApiException mail_mode 非法
     */
    public function saveSettings(array $fields): array
    {
        // 0. mail_mode 先校验再写：非法值在动任何其它设置之前就被拦下
        if (isset($fields['mail_mode'])) {
            $mode = (string) $fields['mail_mode'];
            if (!in_array($mode, self::MAIL_MODES, true)) {
                throw new ApiException(
                    ['status' => 'error', 'message' => 'mail_mode 取值非法，只允许 auto / graph / imap'],
                    400
                );
            }
            $this->settings->set('mail_mode', $mode);
        }

        // 1. TG 设置：isset 而非 !empty —— 空串是合法的"清空"操作
        foreach (['tg_token', 'tg_chatid'] as $k) {
            if (isset($fields[$k])) {
                $this->settings->set($k, (string) $fields[$k]);
            }
        }

        // 2. 管理员名：空串/未传一律不动（!empty 与改造前一致，注意 '0' 也算空）
        $username = !empty($fields['admin_user']) ? (string) $fields['admin_user'] : null;

        // 3. 管理员新密码：空串/未传一律不改；哈希算法用默认（与改造前一致）
        $passwordHash = !empty($fields['admin_pass'])
            ? password_hash((string) $fields['admin_pass'], PASSWORD_DEFAULT)
            : null;

        $this->settings->updateAdmin($username, $passwordHash);

        return ['status' => 'success'];
    }
}
