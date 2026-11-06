<?php
/**
 * 账号服务
 *
 * 职责边界：
 *  - 做业务判断（去重、格式校验、加解密时机），失败抛 ApiException(payload, status)
 *  - 禁止 echo / exit：输出只发生在入口层
 *  - 禁止直接读 $_GET / $_POST：入参由调用方显式传入，便于脱离 HTTP 测试
 *
 * 进度：Step 2 搬了 list / detail；Step 3 搬了 add / update_account / delete；
 * Step 4 搬了 import / export。其余 action（get_mails / tg_backup / logout）仍留在 api.php。
 */
final class AccountService
{
    private AccountRepository $accounts;

    public function __construct(AccountRepository $accounts)
    {
        $this->accounts = $accounts;
    }

    /**
     * 列表：原样返回裸数组，不套壳
     *
     * 现状响应就是 `[ {...}, {...} ]`，加任何 {"data":...} 包装都会改前端契约。
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return $this->accounts->findAll();
    }

    /**
     * 详情：解密 password 并透传
     *
     * 与改造前逐字节一致的两个细节：
     *  1. password_decrypted 是**追加**在行尾的键（先 select 出 6 列，再追加），
     *     不能提前塞进数组，否则键顺序变化会让 detail 快照变红
     *  2. 解不开时保留密文原值并只标 false，不报错、不丢数据
     *
     * @param  int $id 账号 id，由调用方从请求里解析后传入
     * @return array<string, mixed>
     * @throws ApiException 404 账号不存在（payload 与改造前逐字节一致）
     */
    public function detail(int $id): array
    {
        $row = $this->accounts->findById($id);

        if ($row === null) {
            throw new ApiException(['error' => 'Account not found'], 404);
        }

        $plain = Crypto::decrypt((string) $row['password']);
        $row['password_decrypted'] = $plain !== null;
        if ($plain !== null) {
            $row['password'] = $plain;
        }

        return $row;
    }

    /**
     * 新增账号（四段格式：email----password----client_id----refresh_token）
     *
     * 校验顺序照抄原 api.php:41-93：段数 → 字段合法性 → 查重 → 加密 → INSERT。
     * 顺序不能调：先查重再加密，密文就不会白算一次；先校验再查重，400 优先于 409。
     *
     * @param  string      $rawData 原始四段字符串（不 trim 也行，这里会 trim）
     * @param  string|null $remark  未传时保持 null（原实现就是 null，不是 ''）
     * @return array{status:string}
     * @throws ApiException 400 格式/字段非法，409 邮箱已存在，500 加密不可用
     */
    public function add(string $rawData, ?string $remark): array
    {
        $raw = trim($rawData);
        $p   = explode('----', $raw);

        // L-1：任何不合法都返回 JSON，不再静默 exit（前端能显示原因）
        if (count($p) !== 4) {
            throw new ApiException([
                'status'  => 'error',
                'message' => '格式错误：需要 4 段 email----password----client_id----refresh_token',
            ], 400);
        }

        $email = trim($p[0]);
        $pass  = trim($p[1]);
        $cid   = trim($p[2]);
        $token = trim($p[3]);

        $invalid = '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $invalid = '邮箱格式不正确';
        } elseif ($pass === '') {
            $invalid = '密码不能为空';
        } elseif ($cid === '') {
            $invalid = 'client_id 不能为空';
        } elseif ($token === '') {
            $invalid = 'refresh_token 不能为空';
        }

        if ($invalid !== '') {
            throw new ApiException(['status' => 'error', 'message' => $invalid], 400);
        }

        // v3 唯一索引兜底：重复邮箱先拦下来，避免 PDOException 变成 500 白页
        if ($this->accounts->existsByEmail($email)) {
            throw new ApiException(['status' => 'error', 'message' => '该邮箱已存在'], 409);
        }

        try {
            $pass = Crypto::encrypt($pass);
        } catch (Throwable $e) {
            // 日志前缀与改造前逐字符一致，便于沿用既有告警检索
            error_log('[OutlookPanel] 新增账号失败（加密）: ' . $e->getMessage());
            throw new ApiException(['status' => 'error', 'message' => '加密服务未就绪，操作已取消'], 500);
        }

        $this->accounts->insert([
            'email'         => $email,
            'password'      => $pass,
            'client_id'     => $cid,
            'refresh_token' => $token,
            'remark'        => $remark,
        ]);

        return ['status' => 'success'];
    }

    /**
     * 更新账号（编辑弹窗）
     *
     * 幂等与防二次加密是这段的核心：
     *  - 密码留空 = 不修改，沿用库中现值（不做任何加密）
     *  - 表单回填的是库中现值：能解开的说明已是密文，原样存回，避免二次加密
     *
     * @param  int                 $id     账号 id，<=0 时按现状返回 ID无效（HTTP 200，不抛异常）
     * @param  array<string,mixed> $fields 调用方传入的字段数组（email/password/client_id/refresh_token/remark）
     * @return array{status:string,message?:string}
     * @throws ApiException 409 邮箱被占用，500 加密/DB 失败
     */
    public function update(int $id, array $fields): array
    {
        $email  = trim((string) ($fields['email'] ?? ''));
        $pass   = trim((string) ($fields['password'] ?? ''));
        $cid    = trim((string) ($fields['client_id'] ?? ''));
        $token  = trim((string) ($fields['refresh_token'] ?? ''));
        $remark = $fields['remark'] ?? '';

        if ($id <= 0) {
            // 注意：现状是 200 + status=error，不是 4xx，不能"顺手"改成异常
            return ['status' => 'error', 'message' => 'ID无效'];
        }

        // 密码留空 = 不修改，沿用库中现值
        // 原实现用 fetchColumn()，取不到行时是 false → (string)false = ''，这里等价为 null → ''
        $existing = $pass === ''
            ? (string) (($this->accounts->findById($id)['password'] ?? ''))
            : null;

        try {
            if ($pass === '') {
                $stored = (string) $existing;
            } else {
                // 编辑表单会回填库中现值：已是本系统密文则原样保存，避免二次加密
                $stored = Crypto::decrypt($pass) === null ? Crypto::encrypt($pass) : $pass;
            }
        } catch (Throwable $e) {
            error_log('[OutlookPanel] 更新账号失败（加密）: ' . $e->getMessage());
            throw new ApiException(['status' => 'error', 'message' => '加密服务未就绪，操作已取消'], 500);
        }

        try {
            $this->accounts->update($id, [
                'email'         => $email,
                'password'      => $stored,
                'client_id'     => $cid,
                'refresh_token' => $token,
                'remark'        => $remark,
            ]);
        } catch (PDOException $e) {
            // v3 唯一索引兜底：改成别人已占用的邮箱时给出 JSON 提示，而不是 500
            if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                throw new ApiException(['status' => 'error', 'message' => '该邮箱已被其他账号使用'], 409);
            }
            error_log('[OutlookPanel] 更新账号失败（DB）: ' . $e->getMessage());
            throw new ApiException(['status' => 'error', 'message' => '更新失败，请稍后重试'], 500);
        }

        return ['status' => 'success'];
    }

    /**
     * 删除账号
     *
     * 影响 0 行也算成功（改造前就无视行数），别在这里加"账号不存在"判断。
     *
     * @return array{status:string}
     */
    public function delete(int $id): array
    {
        $this->accounts->delete($id);

        return ['status' => 'success'];
    }

    /**
     * 批量导入（JSON 数组 或 四段式文本，每行一条）
     *
     * 大小校验（2MB）与 $_FILES 的错误码判定**留在入口**：Service 拿不到上传上下文，
     * 只接收已读进内存的文本，这样这一段是纯函数、可脱离 HTTP 单测。
     *
     * 与改造前的两处关键一致：
     *  1. 解析阶段就丢的行（段数 < 4）也计入 skipped，否则统计偏低
     *  2. 整批放在同一个 try 里：任何一行抛异常（加密/DB）都中止整批，
     *     但已导入/已跳过的计数要原样带出去，前端据此知道自己导入到哪了
     *
     * @param  string $rawText 文件内容（入口已做过 2MB 与 $_FILES 校验）
     * @return array{status:string,imported:int,skipped:int}
     * @throws ApiException 500 加密/DB 中断（payload 带部分计数）
     */
    public function import(string $rawText): array
    {
        $data = json_decode($rawText, true);

        // 兼容 export 产出的四段式文本（email----password----client_id----refresh_token，每行一条）
        $parseSkipped = 0;
        if (!is_array($data)) {
            $data = [];
            foreach (preg_split('/\r\n|\r|\n/', $rawText) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $p = explode('----', $line);
                if (count($p) < 4) {
                    $parseSkipped++;
                    continue;
                }
                $data[] = [
                    'email'         => $p[0],
                    'password'      => $p[1],
                    'client_id'     => $p[2],
                    'refresh_token' => $p[3],
                    'remark'        => null,
                ];
            }
        }

        $imported = 0;
        $skipped  = $parseSkipped;

        try {
            foreach ($data as $i) {
                if (!is_array($i)) {
                    $skipped++;
                    continue;
                }

                $email = trim((string) ($i['email'] ?? ''));
                $plain = trim((string) ($i['password'] ?? ''));
                $cid   = trim((string) ($i['client_id'] ?? ''));
                $token = trim((string) ($i['refresh_token'] ?? ''));

                // 四段缺一不可 + 邮箱格式校验，不合格的行跳过并计数，不再静默吞掉
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $plain === '' || $cid === '' || $token === '') {
                    $skipped++;
                    continue;
                }

                // v3 唯一索引兜底：库里已有 / 文件内重复的邮箱算 skipped，不中断整批
                if ($this->accounts->existsByEmail($email)) {
                    $skipped++;
                    continue;
                }

                // 导入文件可能是明文，也可能是本系统导出的密文：明文加密，密文原样
                $enc = Crypto::decrypt($plain) === null ? Crypto::encrypt($plain) : $plain;

                $this->accounts->insert([
                    'email'         => $email,
                    'password'      => $enc,
                    'client_id'     => $cid,
                    'refresh_token' => $token,
                    'remark'        => $i['remark'] ?? null,
                ]);
                $imported++;
            }
        } catch (Throwable $e) {
            // 日志前缀与改造前逐字符一致
            error_log('[OutlookPanel] 导入账号失败（加密）: ' . $e->getMessage());
            throw new ApiException([
                'status'   => 'error',
                'message'  => '加密服务未就绪，导入已中断',
                'imported' => $imported,
                'skipped'  => $skipped,
            ], 500);
        }

        return ['status' => 'success', 'imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * 导出为四段式文本（每行一条，与 add 的 raw_data 同一格式，便于再导入）
     *
     * 响应头（Content-Type / Content-Disposition / 文件名里的时间戳）留在入口，
     * Service 只返回正文字符串。非空时带结尾换行，空库返回空串——都与改造前一致。
     *
     * @return string
     */
    public function export(): string
    {
        $lines = [];
        foreach ($this->accounts->findForExport() as $r) {
            $plain = Crypto::decrypt((string) $r['password']);
            // 解不开（旧明文或密文损坏）就按原值导出，不导出空密码
            $pw = $plain === null ? (string) $r['password'] : $plain;

            $lines[] = $r['email'] . '----' . $pw . '----' . $r['client_id'] . '----' . $r['refresh_token'];
        }

        return $lines ? implode("\n", $lines) . "\n" : '';
    }
}
