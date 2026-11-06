<?php
/**
 * 账号仓储
 *
 * 职责边界：
 *  - 只发 SQL，失败直接抛 PDOException（config.php 已设 ERRMODE_EXCEPTION），由上层决定语义
 *  - 禁止 echo / exit / json_encode / http_response_code：这里不认识 HTTP
 *  - 禁止业务判断（去重、格式校验、加密），那属于 Service
 *
 * 进度：Step 2 搬了 list / detail；Step 3 搬了 add / update_account / delete；
 * Step 4 搬了 import（复用 insert / existsByEmail）与 export 的 SQL；
 * Step 5 搬了 get_mails（findById + updateRefreshToken）与 tg_backup（findAllForBackup）。
 * 至此 accounts 表的所有 SQL 都在这里，api.php 不再有查询语句。
 */
final class AccountRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * 列表：只取非敏感字段（密码/凭证走 findById 单独取）
     *
     * SQL 与列顺序照抄原 api.php:10，顺序变了 list 快照就会红。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            'SELECT id, email, remark, created_at FROM accounts ORDER BY id DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 单条：按 id 取全字段（含密文 password 与凭证，解密由 Service 负责）
     *
     * 列顺序照抄原 api.php:17，它决定 detail 响应的键顺序。
     *
     * @return array<string, mixed>|null 无行返回 null（注意 fetch 失败是 false，不是 null）
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, password, client_id, refresh_token, remark FROM accounts WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * 查重：邮箱是否已被占用（excludeId 用于 update 时排除自身）
     *
     * 对应原 api.php:74 的 `SELECT id FROM accounts WHERE email = ? LIMIT 1`。
     * 注意这里**不**依赖 v3 唯一索引也能工作（显式查询），与改造前一致。
     */
    public function existsByEmail(string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM accounts WHERE email = ?';
        $args = [$email];

        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * 新增：返回自增 id
     *
     * 列顺序照抄原 api.php:91。唯一索引冲突**不在这里 catch**，
     * 由 Service 决定是 409 还是 500。
     *
     * @param array{email:string,password:string,client_id:string,refresh_token:string,remark:?string} $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO accounts (email,password,client_id,refresh_token,remark) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([
            $data['email'],
            $data['password'],
            $data['client_id'],
            $data['refresh_token'],
            $data['remark'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新：Set 子句列顺序照抄原 api.php:125
     *
     * @param array{email:string,password:string,client_id:string,refresh_token:string,remark:?string} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE accounts SET email=?, password=?, client_id=?, refresh_token=?, remark=? WHERE id=?'
        );
        $stmt->execute([
            $data['email'],
            $data['password'],
            $data['client_id'],
            $data['refresh_token'],
            $data['remark'],
            $id,
        ]);
    }

    /**
     * 删除：影响 0 行不算失败（改造前也不报错，一律回 success）
     */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM accounts WHERE id=?')->execute([$id]);
    }

    /**
     * 导出用：按 id 升序取四段式所需的列（不含 remark/created_at）
     *
     * SQL 与列序照抄原 api.php:384。ORDER BY id 决定导出行序，不能改。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForExport(): array
    {
        $stmt = $this->db->query(
            'SELECT email, password, client_id, refresh_token FROM accounts ORDER BY id'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 备份用：按 id 倒序取**全部列**
     *
     * 必须是 SELECT *，不能写列名清单：tg_backup 把结果直接 json_encode 出去，
     * JSON 的键序 = 这里的列序，改成显式列名会改动备份文件内容。
     */
    public function findAllForBackup(): array
    {
        $stmt = $this->db->query('SELECT * FROM accounts ORDER BY id DESC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 微软轮换 refresh_token 后回写
     *
     * 对应原 api.php:149。读接口顺手写库是既有设计（不写下次就拿不到邮件），
     * 包 C 只搬不改；是否要挪到独立任务里，包 D 再定。
     */
    public function updateRefreshToken(int $id, string $refreshToken): void
    {
        $this->db->prepare('UPDATE accounts SET refresh_token=? WHERE id=?')
            ->execute([$refreshToken, $id]);
    }
}
