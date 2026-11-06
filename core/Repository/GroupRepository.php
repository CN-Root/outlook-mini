<?php
/**
 * 分组仓储（包 E · E-1）
 *
 * 职责边界（与 AccountRepository 同一套规矩）：
 *  - 只发 SQL，失败直接抛 PDOException（config.php 已设 ERRMODE_EXCEPTION），由上层决定语义
 *  - 禁止 echo / exit / json_encode / http_response_code：这里不认识 HTTP
 *  - 禁止业务判断（去空白、重名校验、404/409 的取舍），那属于 GroupService
 *  - 不跨表做"删组时顺手删关联"这类组合动作：delete + deleteAssociations 由 Service 在同一事务里调，
 *    事务边界留在 Service（GroupRepository::transaction 只提供壳子，不替调用方决定做什么）
 *
 * 默认分组是虚拟的：groups 表里没有这条记录，"无组账号"由 accountsWithoutGroup() 算出。
 */
final class GroupRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * 全部组：按 id 升序（= 创建顺序，E-3 界面的展示顺序）
     *
     * 列顺序即响应键顺序，改列名或调换顺序会让 E-2 的 list_groups 契约漂移。
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT id, name, created_at FROM groups ORDER BY id');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 单个组：不存在返回 null（注意 fetch 失败是 false，不是 null）
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, created_at FROM groups WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * 查重：组名是否已被占用（excludeId 用于改名时排除自身）
     *
     * 表上有 UNIQUE(name) 兜底，但这里显式查一次：Service 才能在写库**之前**给出 409，
     * 而不是等 PDOException 冒泡成 500。
     */
    public function existsByName(string $name, ?int $excludeId = null): bool
    {
        $sql  = 'SELECT id FROM groups WHERE name = ?';
        $args = [$name];

        if ($excludeId !== null) {
            $sql   .= ' AND id <> ?';
            $args[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * 新增组：返回自增 id
     *
     * 唯一约束冲突**不在这里 catch**：Service 已经用 existsByName 先拦了一道，
     * 真撞上（并发）就是 500，不把异常悄悄改成成功。
     */
    public function create(string $name): int
    {
        $this->db->prepare('INSERT INTO groups (name) VALUES (?)')->execute([$name]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * 改名：影响 0 行不算失败（Service 已先确认组存在）
     */
    public function rename(int $id, string $name): void
    {
        $this->db->prepare('UPDATE groups SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    /**
     * 删组：**只删 groups 行**，关联由 deleteAssociations 单独删，两者同事务由 Service 保证
     */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM groups WHERE id = ?')->execute([$id]);
    }

    /**
     * 删组前清关联：不删的话会留下指向已删除组的孤儿行，
     * 且 accountsWithoutGroup() 的 NOT IN 子查询会把那些账号误判成"已在组内"
     */
    public function deleteAssociations(int $groupId): void
    {
        $this->db->prepare('DELETE FROM group_accounts WHERE group_id = ?')->execute([$groupId]);
    }

    /**
     * 入组：INSERT OR IGNORE —— 主键 (group_id, account_id) 挡住重复，重复调用是幂等的
     */
    public function addAccount(int $groupId, int $accountId): void
    {
        $this->db->prepare('INSERT OR IGNORE INTO group_accounts (group_id, account_id) VALUES (?, ?)')
            ->execute([$groupId, $accountId]);
    }

    /**
     * 出组：只删关联行，**绝不碰 accounts 表**（"仅在分组中移除邮箱"的需求）
     */
    public function removeAccount(int $groupId, int $accountId): void
    {
        $this->db->prepare('DELETE FROM group_accounts WHERE group_id = ? AND account_id = ?')
            ->execute([$groupId, $accountId]);
    }

    /**
     * 组内账号 id 列表（只取 id，用于计数/存在性判断）
     *
     * @return array<int, int> 升序
     */
    public function accountIdsOfGroup(int $groupId): array
    {
        $stmt = $this->db->prepare('SELECT account_id FROM group_accounts WHERE group_id = ? ORDER BY account_id');
        $stmt->execute([$groupId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * 组内账号明细
     *
     * 只取 id/email/remark/created_at 四列（**不含 password/client_id/refresh_token**）：
     * 与 accountsWithoutGroup() 的列清单保持完全一致，E-2 两个分支的 JSON 结构才对得上。
     * ORDER BY id 是显式加的（卡的 SQL 片段没写）：不排序时 SQLite 的返回顺序不构成契约，
     * 而"默认分组"分支是有序的，两分支排序不一致会让前端列表顺序跳动。
     *
     * @return array<int, array<string, mixed>>
     */
    public function accountsOfGroup(int $groupId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.email, a.remark, a.created_at
               FROM accounts a
               INNER JOIN group_accounts ga ON ga.account_id = a.id
              WHERE ga.group_id = ?
              ORDER BY a.id'
        );
        $stmt->execute([$groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 某账号所属的组（多对多：一个账号可以同时在多个组里）
     *
     * @return array<int, array<string, mixed>> 按组 id 升序
     */
    public function groupsOfAccount(int $accountId): array
    {
        $stmt = $this->db->prepare(
            'SELECT g.id, g.name, g.created_at
               FROM groups g
               INNER JOIN group_accounts ga ON ga.group_id = g.id
              WHERE ga.account_id = ?
              ORDER BY g.id'
        );
        $stmt->execute([$accountId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 默认分组（虚拟）：没有任何组关联的账号
     *
     * 用 NOT IN 子查询而不是 LEFT JOIN ... IS NULL：与卡的约定一致，且语义一眼可读。
     * 列清单与 accountsOfGroup() 完全一致（见那边的说明）。
     *
     * @return array<int, array<string, mixed>> 按 id 升序
     */
    public function accountsWithoutGroup(): array
    {
        $stmt = $this->db->query(
            'SELECT id, email, remark, created_at FROM accounts
              WHERE id NOT IN (SELECT account_id FROM group_accounts)
              ORDER BY id'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 事务包装：BEGIN → $fn() → COMMIT；$fn 抛异常则 ROLLBACK 后原样抛出
     *
     * 放在仓储层是为了让 PDO 不出现在 GroupService 的构造函数里——
     * Service 只表达"这两步要原子"，怎么开事务仍归数据层管。
     *
     * @param  callable():mixed $fn
     * @return mixed $fn 的返回值
     */
    public function transaction(callable $fn): mixed
    {
        $this->db->beginTransaction();

        try {
            $result = $fn();
            $this->db->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }
}
