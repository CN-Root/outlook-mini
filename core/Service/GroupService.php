<?php
/**
 * 分组服务（包 E · E-1）
 *
 * 职责边界（与 AccountService 同一套规矩）：
 *  - 做业务判断（去空白、非空、重名、404/409 的取舍），失败抛 ApiException(payload, status)
 *  - 禁止 echo / exit：输出只发生在入口层（E-2 才接 api.php）
 *  - 禁止直接读 $_GET / $_POST：入参由调用方显式传入，便于脱离 HTTP 测试
 *  - 不拼 HTML、不做转义：E-3 的前端在浏览器侧用 textContent/escapeHtml 处理
 *
 * 三条容易写错、这里刻意守住的行为：
 *  1. 删组 = 删关联 + 删组（同一事务），**不删账号**。删除后组内账号自动回到"默认分组"
 *     （虚拟分组：groups 表里没有这条记录，靠"没有关联"算出来）。
 *  2. 移除账号 = 只删 group_accounts 那一行，accounts 表一个字节都不动。
 *  3. 改名查重要排除自身，否则"改成同名（不改）"会被自己判成 409。
 *
 * 状态码口径：参数不合法 400、组/账号不存在 404、重名 409，payload 统一
 * ['status' => 'error', 'message' => ...]，与包里既有 action 的写法一致。
 */
final class GroupService
{
    private GroupRepository $groups;

    /** 只用来判"账号是否存在"（addAccount 的 404），不读账号的敏感列 */
    private AccountRepository $accounts;

    public function __construct(GroupRepository $groups, AccountRepository $accounts)
    {
        $this->groups   = $groups;
        $this->accounts = $accounts;
    }

    /**
     * 组列表：每组追加 account_count
     *
     * account_count 用 accountIdsOfGroup() 取 id 再 count（不额外加 COUNT(*) 方法）：
     * 组的数量级是个位数，N+1 换来的可读性更值钱；真到几十个组再改成一次聚合查询。
     *
     * @return array<int, array<string, mixed>> 每行键序 id → name → created_at → account_count
     */
    public function listGroups(): array
    {
        $rows = $this->groups->all();

        foreach ($rows as $i => $row) {
            // 追加在行尾：与 AccountService::detail 的 password_decrypted 同一手法，
            // 原位塞进去会打乱既有键序
            $rows[$i]['account_count'] = count($this->groups->accountIdsOfGroup((int) $row['id']));
        }

        return $rows;
    }

    /**
     * 建组
     *
     * 校验顺序：400（空名）→ 409（重名）→ 写库。先返回信息量更大的那个错。
     *
     * @return array{status:string, id:int}
     * @throws ApiException 400 空名 / 409 重名
     */
    public function createGroup(string $name): array
    {
        $name = $this->normalizeName($name);

        if ($name === '') {
            throw new ApiException(['status' => 'error', 'message' => '分组名不能为空'], 400);
        }

        if ($this->groups->existsByName($name)) {
            throw new ApiException(['status' => 'error', 'message' => '分组名已存在'], 409);
        }

        return ['status' => 'success', 'id' => $this->groups->create($name)];
    }

    /**
     * 改名
     *
     * 校验顺序按卡的约定：404（组不存在）→ 400（空名）→ 409（重名）→ 写库。
     *
     * @return array{status:string}
     * @throws ApiException 404 组不存在 / 400 空名 / 409 重名
     */
    public function renameGroup(int $id, string $name): array
    {
        if ($this->groups->findById($id) === null) {
            throw new ApiException(['status' => 'error', 'message' => '分组不存在'], 404);
        }

        $name = $this->normalizeName($name);

        if ($name === '') {
            throw new ApiException(['status' => 'error', 'message' => '分组名不能为空'], 400);
        }

        // excludeId = $id：改成"跟原来同名"不算重名，否则用户点保存都会失败
        if ($this->groups->existsByName($name, $id)) {
            throw new ApiException(['status' => 'error', 'message' => '分组名已存在'], 409);
        }

        $this->groups->rename($id, $name);

        return ['status' => 'success'];
    }

    /**
     * 删组：删关联 + 删组，同一事务
     *
     * 为什么顺序是"先删关联再删组"：万一将来加上外键/约束，先删组会因残留关联失败；
     * 事务保证两步要么都成、要么都不成，不会出现"组没了但关联还在"的孤儿行。
     * 组内账号**不删**，也没有任何"搬到默认组"的写操作——默认组是虚拟的。
     *
     * @return array{status:string}
     * @throws ApiException 404 组不存在 / 500 事务失败（PDOException 原样冒泡）
     */
    public function deleteGroup(int $id): array
    {
        if ($this->groups->findById($id) === null) {
            throw new ApiException(['status' => 'error', 'message' => '分组不存在'], 404);
        }

        $this->groups->transaction(function () use ($id): void {
            $this->groups->deleteAssociations($id);
            $this->groups->delete($id);
        });

        return ['status' => 'success'];
    }

    /**
     * 把账号加进组：组和账号都必须存在
     *
     * 重复添加是幂等的（INSERT OR IGNORE），不报错——"加进去"这个目标已经达成。
     *
     * @return array{status:string}
     * @throws ApiException 404 组不存在 / 404 账号不存在
     */
    public function addAccount(int $groupId, int $accountId): array
    {
        if ($this->groups->findById($groupId) === null) {
            throw new ApiException(['status' => 'error', 'message' => '分组不存在'], 404);
        }

        if ($this->accounts->findById($accountId) === null) {
            throw new ApiException(['status' => 'error', 'message' => '账号不存在'], 404);
        }

        $this->groups->addAccount($groupId, $accountId);

        return ['status' => 'success'];
    }

    /**
     * 把账号移出组：**只删关联，不删账号**
     *
     * 只校验组存在，不校验账号：账号若已被删除，这里正好把残留的关联行清掉，
     * 反而有利于 accountsWithoutGroup() 的 NOT IN 判断。移出后账号自动回到默认分组。
     *
     * @return array{status:string}
     * @throws ApiException 404 组不存在
     */
    public function removeAccount(int $groupId, int $accountId): array
    {
        if ($this->groups->findById($groupId) === null) {
            throw new ApiException(['status' => 'error', 'message' => '分组不存在'], 404);
        }

        $this->groups->removeAccount($groupId, $accountId);

        return ['status' => 'success'];
    }

    /**
     * 按组取账号：$groupId === null 即"默认分组"（没有加入任何组的账号）
     *
     * 默认分组分支不查 groups 表（它本来就没有记录）；指定组时先确认组存在，
     * 否则"组被删了但前端还拿着旧 id"会静默返回空列表，看起来像"这个组是空的"。
     *
     * @param  int|null $groupId null = 默认分组
     * @return array<int, array<string, mixed>> 两个分支列清单一致：id/email/remark/created_at
     * @throws ApiException 404 组不存在（仅指定组时）
     */
    public function accountsInGroup(?int $groupId): array
    {
        if ($groupId === null) {
            return $this->groups->accountsWithoutGroup();
        }

        if ($this->groups->findById($groupId) === null) {
            throw new ApiException(['status' => 'error', 'message' => '分组不存在'], 404);
        }

        return $this->groups->accountsOfGroup($groupId);
    }

    /**
     * 账号所属的组（一个账号可属多组）
     *
     * 账号不存在时返回空数组而不是 404：这里是"这个账号在哪些组里"的查询，
     * 账号不存在 = 一个组都没有，与 404 语义不同（详情由 AccountService::detail 负责）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function groupsOfAccount(int $accountId): array
    {
        return $this->groups->groupsOfAccount($accountId);
    }

    /**
     * 组名归一：只去首尾空白，不做大小写折叠、不做长度截断
     *
     * 不做 mb_strlen 上限：卡没要求，且"名字太长"是产品规则不是数据规则，
     * 等 E-2 定了上限再在这里加，避免现在写死一个数后面前端后端各说一套。
     */
    private function normalizeName(string $name): string
    {
        return trim($name);
    }
}
