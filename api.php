<?php
// 引导：config → Crypto → check_auth，顺序与拆分前完全一致（契约见 core/Bootstrap.php）
require_once __DIR__ . '/core/Bootstrap.php';

$db     = Bootstrap::boot();   // 原第 2-5 行：require config.php + global $db
$action = Bootstrap::action(); // 原第 7 行：$_GET['action'] ?? ''

// 账号列表（只返回非敏感字段，密码/凭证走 detail 单独取）
if ($action === 'list') {
    try {
        echo json_encode((new AccountService(new AccountRepository($db)))->list());
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] list 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// 单条详情（编辑弹窗用：按 id 单独取，password 解密后返回明文）
if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    try {
        echo json_encode((new AccountService(new AccountRepository($db)))->detail($id));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] detail 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// 添加账号
if ($action === 'add') {
    try {
        // remark 未传时取 null：避免 PHP 8 的 Undefined array key 警告混进 JSON 响应体
        echo json_encode((new AccountService(new AccountRepository($db)))->add(
            (string) ($_POST['raw_data'] ?? ''),
            $_POST['remark'] ?? null
        ));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] add 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// 更新账号信息 (核心修复：解决编辑备注无效)
if ($action === 'update_account') {
    try {
        echo json_encode((new AccountService(new AccountRepository($db)))->update(
            (int) ($_POST['id'] ?? 0),
            $_POST
        ));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] update_account 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// =============================================================================
// 获取邮件逻辑 - 已修复 SEARCH 报错与乱码
// =============================================================================
if ($action === 'get_mails') {
    try {
        // OAuth + IMAP + MIME + 视图拼装仍是一整块，原样在 MailService::getMails 里，
        // 本步只搬不拆（拆四领域是包 D 的 D1）。
        // (int) ($_GET['id'] ?? 0)：id 缺失时原代码会抛 Undefined array key 警告再按 0 处理，
        // 响应仍是 {'value': []}，这里只省掉那条警告（日志差异，已登记）。
        // D1-2：多注入两个协作对象——SettingRepository 读 mail_mode，GraphClient 提供 Graph 能力。
        // 前三个参数保持原样（ImapConfig/tokenProvider 传 null = 生产默认：真实 outlook + 真实 OAuth），
        // 三模式分发逻辑全在 MailService 内，本分支仍是"构造 + 调用 + echo"三件事。
        echo json_encode((new MailService(
            new AccountRepository($db),
            null,
            null,
            new SettingRepository($db),
            new GraphClient(new GraphConfig())
        ))->getMails((int) ($_GET['id'] ?? 0)));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] get_mails 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// -------------------------- 保持不变的辅助功能 --------------------------
if ($action === 'delete') {
    try {
        // 故意保留 (int)$_POST['id'] 的无默认值写法：缺 id 时的 Undefined array key 警告
        // 与改造前一致（登记项 6，本步不修，包 C 后续再议）
        echo json_encode((new AccountService(new AccountRepository($db)))->delete((int) $_POST['id']));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] delete 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// =============================================================================
// 分组（包 E · E-2）
//
// 7 个 action 全是"构造 GroupService + 调用 + echo"，校验/事务/404 文案都在 E-1 里，
// 本文件只负责 HTTP 入口，不重复写业务判断。
//
// 入参口径（E-2 拍板）：group_id / account_id，与数据库列名一致，前端直传，不做 gid/aid 缩写。
// 响应结构（E-3 前端对着写，键序固定）：
//   list_groups   → [ {id, name, created_at, account_count} ]
//   create_group  → {status:'success', id:N}
//   其余写操作     → {status:'success'}
//   group_accounts→ [ {id, email, remark, created_at} ]（默认分组与指定组同结构）
//
// 缺参一律 (int) 收敛成 0 再交给 Service：组/账号不存在 → 404「分组不存在 / 账号不存在」，
// 比在入口写一堆 400 更少分支（0 一定不存在，语义等价）。
// =============================================================================

// 组列表：只给计数，明细走 group_accounts 单独拉（列表页不需要账号数组）
if ($action === 'list_groups') {
    try {
        echo json_encode((new GroupService(new GroupRepository($db), new AccountRepository($db)))->listGroups());
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] list_groups 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

if ($action === 'create_group') {
    try {
        echo json_encode((new GroupService(new GroupRepository($db), new AccountRepository($db)))->createGroup(
            (string) ($_POST['name'] ?? '')
        ));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] create_group 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

if ($action === 'rename_group') {
    try {
        echo json_encode((new GroupService(new GroupRepository($db), new AccountRepository($db)))->renameGroup(
            (int) ($_POST['id'] ?? 0),
            (string) ($_POST['name'] ?? '')
        ));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] rename_group 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// 删组：Service 内一个事务删关联 + 删组，组内账号不删（回到虚拟默认分组）
if ($action === 'delete_group') {
    try {
        echo json_encode((new GroupService(new GroupRepository($db), new AccountRepository($db)))->deleteGroup(
            (int) ($_POST['id'] ?? 0)
        ));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] delete_group 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

if ($action === 'group_add_account') {
    try {
        echo json_encode((new GroupService(new GroupRepository($db), new AccountRepository($db)))->addAccount(
            (int) ($_POST['group_id'] ?? 0),
            (int) ($_POST['account_id'] ?? 0)
        ));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] group_add_account 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// 从组里移除：只删关联行，accounts 表不动（"仅在分组中删除邮箱"）
if ($action === 'group_remove_account') {
    try {
        echo json_encode((new GroupService(new GroupRepository($db), new AccountRepository($db)))->removeAccount(
            (int) ($_POST['group_id'] ?? 0),
            (int) ($_POST['account_id'] ?? 0)
        ));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] group_remove_account 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

// 组内账号：group_id 缺省 = 默认分组（没有加入任何组的账号）
if ($action === 'group_accounts') {
    try {
        // E-3 偏差 3 对齐：缺省 / 空串 / 非正数一律当"默认分组"，不再被 (int)'' 收敛成 0 → 404。
        // 前端 callApi 也不会把空串拼进 query，两侧口径一致（双保险，断言见快照 case group_accounts_empty_id）。
        $gid = (int) ($_GET['group_id'] ?? 0);
        echo json_encode((new GroupService(new GroupRepository($db), new AccountRepository($db)))->accountsInGroup(
            $gid > 0 ? $gid : null
        ));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] group_accounts 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

if ($action === 'save_settings') {
    try {
        // 判定细节（isset vs !empty、改不改 admin、密码怎么哈希）全在 SettingService，
        // 这里只把 $_POST 原样交出去——本分支是 admin 表的唯一写入口，改动务必走快照校验
        echo json_encode((new SettingService(new SettingRepository($db)))->saveSettings($_POST));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] save_settings 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

if ($action === 'import') {
    // $_FILES 的三道校验留在入口：Service 拿不到上传上下文，只接收已读进内存的文本。
    // 判定顺序（未收到 → 上传失败 → 超过 2MB）与响应码（400/400/413）均与改造前一致。
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '未收到上传文件（若文件很大，可能已超过 php.ini 的 post_max_size）']);
        exit;
    }

    $file = $_FILES['file'];
    $max_bytes = 2 * 1024 * 1024; // 2MB

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '文件上传失败（可能已超过 php.ini 的 upload_max_filesize）']);
        exit;
    }

    // 先看客户端声明的 size，再看落盘后的真实大小，两道都过才处理
    if ((int) $file['size'] > $max_bytes || (int) @filesize($file['tmp_name']) > $max_bytes) {
        http_response_code(413);
        echo json_encode(['status' => 'error', 'message' => '文件过大：最大 2MB']);
        exit;
    }

    $raw_text = (string) file_get_contents($file['tmp_name']);

    try {
        echo json_encode((new AccountService(new AccountRepository($db)))->import($raw_text));
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] import 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

if ($action === 'export') {
    // 文件名与四个响应头留在入口（HTTP 关注点），Service 只负责拼四段式正文
    try {
        $text = (new AccountService(new AccountRepository($db)))->export();

        // 生成文件名，带上当前日期
        $filename = "outlook_accounts_" . date('Ymd_His') . ".txt";

        // 核心：设置响应头强制浏览器弹出下载框
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $text;
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] export 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}

if ($action === 'get_settings') {
    try {
        // settings 全表 + admin_user（管理员名缺失时回退 'admin'）
        echo json_encode((new SettingService(new SettingRepository($db)))->getSettings());
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] get_settings 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}
// =============================================================================
// 退出登录
// =============================================================================
if ($action === 'logout') {

    // 清空当前 Session 数据
    $_SESSION = [];

    // 删除 Session Cookie
    // 防止浏览器继续保存登录状态
    if (isset($_COOKIE[session_name()])) {
        setcookie(
            session_name(), // Session 名称
            '',             // 清空内容
            time() - 42000, // 设置为过去时间立即失效
            '/'             // 全站生效
        );
    }

    // 销毁 Session
    session_destroy();

    // 跳转回网站首页（登录页）
    header('Location: /');

    // 终止程序执行
    exit;
}

// TG 备份功能
if ($action === 'tg_backup') {
    try {
        // 读全表 + 读 settings + 临时文件 + cURL 全在 BackupService，逻辑未改
        echo json_encode((new BackupService(new AccountRepository($db), new SettingRepository($db)))->tgBackup());
    } catch (ApiException $e) {
        http_response_code($e->httpStatus());
        echo json_encode($e->payload());
    } catch (Throwable $e) {
        error_log('[OutlookPanel] tg_backup 异常: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '服务异常，请稍后重试']);
    }
    exit;
}