<?php
ini_set('session.use_strict_mode', 1);
session_set_cookie_params([
    'httponly' => true,
    'secure'   => true,
    'samesite' => 'Strict'
]);
session_start();
date_default_timezone_set('Asia/Shanghai');

// APP_KEY 引导：真正的环境变量优先；没有时回退到 data/app_key.php（base64 32 字节）
// 目的：密钥与数据库同目录，CLI 迁移和 Web 运行时读到的一定是同一把钥匙，
// 避免迁移完成后因为漏配环境变量而让密文永久解不开。已配环境变量的部署不受影响。
if (trim((string) getenv('APP_KEY')) === '') {
    $app_key_file = __DIR__ . '/data/app_key.php';
    if (is_file($app_key_file)) {
        $app_key = trim((string) include $app_key_file);
        if ($app_key !== '') {
            putenv('APP_KEY=' . $app_key);
        }
    }
}

$db_dir = __DIR__ . '/data';
$db_file = $db_dir . '/outlook.db';

if (!is_dir($db_dir)) @mkdir($db_dir, 0777, true);

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 自动建表（保持不变）
    $db->exec("CREATE TABLE IF NOT EXISTS admin (id INTEGER PRIMARY KEY, username TEXT, password TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT,
        password TEXT,
        client_id TEXT,
        refresh_token TEXT,
        client_secret TEXT, -- 死字段：从未读写，保留仅为兼容旧库（SQLite 删列风险大，不删）
        remark TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 登录失败记录：跨会话/IP 限频用（24 小时前的记录由 index.php 登录时顺手清理）
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT,
        username TEXT,
        attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 分组（包 E · E-1）：账号归组用，纯新增两表，不碰 accounts 的任何一列
    //
    // 默认分组是**虚拟**的：groups 表里没有"默认分组"这条记录，
    // "未加入任何组的账号"就是默认分组的成员（GroupRepository::accountsWithoutGroup）。
    // 这样"删组退回默认"只是一次 DELETE，不需要再往默认组里搬账号——少一层出错面。
    $db->exec("CREATE TABLE IF NOT EXISTS groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 组与账号的多对多关系：主键 (group_id, account_id) 天然挡住"同一账号重复入同组"，
    // 所以 GroupRepository::addAccount 可以直接 INSERT OR IGNORE。
    // 故意不建 FOREIGN KEY：本项目没有 PRAGMA foreign_keys=ON，
    // 写了也不会级联，反而给人"删账号会自动清关联"的错觉（清理由 E-2/E-4 显式做）。
    $db->exec("CREATE TABLE IF NOT EXISTS group_accounts (
        group_id INTEGER NOT NULL,
        account_id INTEGER NOT NULL,
        PRIMARY KEY (group_id, account_id)
    )");

    // 迁移版本表：记录已应用的 schema 版本，供根目录 migrate.php 使用
    $db->exec("CREATE TABLE IF NOT EXISTS schema_version (
        version INTEGER PRIMARY KEY,
        applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // 基线版本：现有库视为 v1，不覆盖已存在的版本记录
    $db->exec("INSERT OR IGNORE INTO schema_version (version) VALUES (1)");

    // 初始化管理员：仅当 admin 表为空时插入随机口令，明文写入错误日志
    $admin_count = $db->query("SELECT COUNT(*) FROM admin")->fetchColumn();
    if ($admin_count == 0) {
        $plain = bin2hex(random_bytes(8));
        $pwd = password_hash($plain, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO admin (username, password) VALUES (?, ?)");
        $stmt->execute(['admin', $pwd]);
        error_log('[OutlookPanel] 初始管理员密码: ' . $plain . ' 请登录后立即修改');
    }

    // 初始化设置：使用 INSERT OR IGNORE 防止重复
    // mail_mode（包 D · D1-1）：auto=Graph 优先失败回落 IMAP / graph=仅 Graph / imap=仅 IMAP
    // 追加在最后，保证老库补行时 rowid 顺序仍是 tg_token → tg_chatid → mail_mode，
    // get_settings 的键序不因这次改动而错位（快照才只需重采 get_settings 一个 action）。
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('tg_token', ''), ('tg_chatid', ''), ('mail_mode', 'auto')");

} catch (PDOException $e) {
    error_log('[OutlookPanel] 数据库异常: ' . $e->getMessage());
    http_response_code(500);
    die('服务异常，请稍后重试');
}

/**
 * 权限检查函数
 * 增加对 Ajax 请求的兼容
 */
function check_auth() {
    if (!isset($_SESSION['logged_in'])) {
        // 如果是 Ajax 请求（API调用），返回 403 错误码
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Login expired']);
            exit;
        }
        // 普通页面请求，跳转回登录页
        header('Location: index.php');
        exit;
    }
}