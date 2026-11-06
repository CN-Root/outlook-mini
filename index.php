<?php
require 'config.php';

// 如果已经登录，直接跳转到主面板
if (isset($_SESSION['logged_in'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';

    // 第一层：会话级限频 —— 同一会话连续失败 5 次锁定 10 分钟
    $max_fail = 5;
    $lock_seconds = 600;
    $lock_until = (int) ($_SESSION['login_lock_until'] ?? 0);

    // 第二层：跨会话（跨 IP）限频 —— 同 IP 10 分钟内失败 10 次即拒绝，清 cookie 无效
    $ip_max_fail = 10;
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    // 顺手清理 24 小时前的历史记录，避免表无限增长
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-1 day')");

    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= datetime('now', '-10 minutes')");
    $stmt->execute([$ip]);
    $ip_fail = (int) $stmt->fetchColumn();

    if ($lock_until > time()) {
        // 锁定期内不校验密码、不区分账号是否存在
        $error = '尝试过多，请稍后再试';
    } elseif ($ip_fail >= $ip_max_fail) {
        // 本 IP 已达上限：不校验密码，避免用密码校验结果反推账号是否存在
        $error = '尝试过多，请稍后再试';
    } else {
        if ($lock_until > 0) {
            // 锁定已到期，重置计数
            unset($_SESSION['login_lock_until'], $_SESSION['login_fail']);
        }

        $stmt = $db->prepare("SELECT * FROM admin WHERE username = ?");
        $stmt->execute([$user]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($pass, $admin['password'])) {
            $_SESSION['login_fail'] = 0;
            unset($_SESSION['login_lock_until']);
            $_SESSION['logged_in'] = true;
            // 登录成功：清掉该 IP + 该用户名的失败记录
            $db->prepare("DELETE FROM login_attempts WHERE ip = ? AND username = ?")->execute([$ip, $user]);
            session_regenerate_id(true);
            header('Location: dashboard.php');
            exit;
        } else {
            // 失败落库：这一条是跨会话/IP 限频的数据来源
            $db->prepare("INSERT INTO login_attempts (ip, username) VALUES (?, ?)")->execute([$ip, $user]);

            $fail = (int) ($_SESSION['login_fail'] ?? 0) + 1;
            $_SESSION['login_fail'] = $fail;
            if ($fail >= $max_fail) {
                $_SESSION['login_lock_until'] = time() + $lock_seconds;
                $error = '尝试过多，请稍后再试';
            } else {
                $error = '账号或密码不正确';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - Outlook 管理后台</title>
    <link rel="icon" type="image/svg+xml" href="/static/image/favicon/OutlookPanel_Favicon.svg">
    <style>
        body { 
            background: #F9FAFB; 
            font-family: -apple-system, "Noto Sans SC", sans-serif; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            height: 100vh; 
            margin: 0; 
        }
        .login-card { 
            background: #FFFFFF; 
            padding: 40px; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.05); 
            width: 100%;
            max-width: 320px; 
        }
        h2 { 
            margin: 0 0 24px; 
            font-weight: 600; 
            text-align: center; 
            color: #111; 
            letter-spacing: -0.5px;
        }
        input { 
            width: 100%; 
            padding: 12px; 
            margin-bottom: 15px; 
            border: 1px solid #E5E7EB; 
            border-radius: 8px; 
            box-sizing: border-box; 
            outline: none; 
            transition: border-color 0.2s; 
            font-size: 14px;
        }
        input:focus { border-color: #000; }
        button { 
            width: 100%; 
            padding: 12px; 
            background: #000; 
            color: #FFF; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: 500; 
            font-size: 14px;
            transition: opacity 0.2s;
        }
        button:hover { opacity: 0.8; }
        .error { 
            color: #EF4444; 
            font-size: 13px; 
            margin-bottom: 15px; 
            text-align: center; 
            background: #FEF2F2;
            padding: 8px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>Outlook Panel</h2>
        <?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <input type="text" name="user" placeholder="用户名" required>
            <input type="password" name="pass" placeholder="密码" required>
            <button type="submit">登录系统</button>
        </form>
    </div>
</body>
</html>