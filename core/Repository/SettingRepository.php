<?php
/**
 * 设置仓储（settings 表 + admin 表的读写）
 *
 * 职责边界与 AccountRepository 一致：
 *  - 只发 SQL，失败直接抛 PDOException（config.php 已设 ERRMODE_EXCEPTION）
 *  - 禁止 echo / exit / json_encode / http_response_code
 *  - 禁止业务判断（空值校验、密码哈希、重名检测），那属于 Service
 *
 * 进度：Step 4 把 get_settings / save_settings 的 SQL 从 api.php 搬到这里。
 * tg_backup 仍直接读 settings（Step 5 收尾）。
 */
final class SettingRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * 全部设置项：key => value
     *
     * 对应原 api.php:410 的 `SELECT * FROM settings` + while fetch 建映射。
     * 这里**不加 ORDER BY**：settings 是 TEXT 主键表、仍带 rowid，扫描顺序即 rowid 顺序，
     * 与改造前的 `SELECT *` 完全一致；加了 ORDER BY key 反而会和快照的键序错位。
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT key, value FROM settings');

        $data = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data[$row['key']] = $row['value'];
        }

        return $data;
    }

    /**
     * 单项设置
     */
    public function get(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * 写单项设置
     *
     * 注意：原 api.php:266 是 `UPDATE settings SET value=? WHERE key=?`，
     * 键不存在时静默不写。这里用 INSERT OR REPLACE（卡里指定），
     * 差别只在「键不存在」这一种情况：旧代码什么都不做，新代码会补建该行。
     * settings 的 key 是 PRIMARY KEY，不存在插出重复行的风险。
     */
    public function set(string $key, string $value): void
    {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);
    }

    /**
     * 当前管理员（只取 id + username，password 不往上层传）
     *
     * @return array{id:int|string, username:string|null}|null
     */
    public function getAdmin(): ?array
    {
        $stmt = $this->db->query('SELECT id, username FROM admin WHERE id = 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * 改管理员：username / password 各自为 null 表示「这一项不动」
     *
     * 对应原 api.php:273 与 api.php:279 两条独立的 UPDATE，顺序也是先名后密。
     * 不要合并成一条 UPDATE：原实现里两项互不影响，合并后一条失败会连带另一条。
     */
    public function updateAdmin(?string $username, ?string $passwordHash): void
    {
        if ($username !== null) {
            $stmt = $this->db->prepare('UPDATE admin SET username=? WHERE id=1');
            $stmt->execute([$username]);
        }

        if ($passwordHash !== null) {
            $stmt = $this->db->prepare('UPDATE admin SET password=? WHERE id=1');
            $stmt->execute([$passwordHash]);
        }
    }
}
