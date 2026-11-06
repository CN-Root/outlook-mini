<?php
/**
 * 微软账号授权工具 - 设备码版
 * 适用场景：解决所有 redirect_uri 报错，一键生成数据库所需的 4 段式格式
 */

require_once __DIR__ . '/config.php';
check_auth();

// 推荐使用这个 Client ID，它在设备码模式下非常稳定
$client_id = '9e5f94bc-e8a4-4e73-b8be-63364c29d753'; 
$scopes = "https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/POP.AccessAsUser.All offline_access";

// 会话已由 config.php 统一初始化，此处不再重复开启

// 处理重置请求
if (isset($_GET['reset'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- 第一步：请求设备代码 ---
if (!isset($_POST['check_auth'])) {
    $ch = curl_init('https://login.microsoftonline.com/consumers/oauth2/v2.0/devicecode');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $client_id,
        'scope' => $scopes
    ]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!isset($res['device_code'])) {
        error_log('[OutlookPanel] 获取设备码失败: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        die("<h3 style='color:red;'>授权失败，请检查账号或稍后重试</h3>");
    }

    $_SESSION['device_code'] = $res['device_code'];
?>
    <div style="max-width:500px; margin:50px auto; font-family:sans-serif; border:1px solid #ddd; padding:40px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.1);">
        <h2 style="color:#0067b8; text-align:center;">微软账号授权</h2>
        
        <div style="background:#fff9e6; padding:15px; border-radius:8px; border-left:5px solid #ffcc00; margin-bottom:20px;">
            <p style="margin:0; font-size:14px; color:#666;">第一步：记下验证码</p>
            <div style="font-size:32px; font-weight:bold; color:#d9534f; text-align:center; margin:10px 0;">
                <?= $res['user_code'] ?>
            </div>
        </div>

        <p>第二步：点击按钮在微软页面输入上面的验证码</p>
        <a href="<?= $res['verification_uri'] ?>" target="_blank" style="display:block; background:#0067b8; color:#fff; padding:12px; text-align:center; text-decoration:none; border-radius:6px; font-weight:bold; margin-bottom:30px;">打开微软授权页面</a>
        
        <form method="POST" style="border-top:1px dashed #ccc; padding-top:20px;">
            <p>第三步：输入账号信息（仅用于生成格式）</p>
            <div style="margin-bottom:10px;">
                <input type="text" name="email" placeholder="邮箱账号" required style="width:100%; padding:10px; box-sizing:border-box; border:1px solid #ccc; border-radius:4px;">
            </div>
            <div style="margin-bottom:20px;">
                <input type="text" name="pass" placeholder="邮箱密码" required style="width:100%; padding:10px; box-sizing:border-box; border:1px solid #ccc; border-radius:4px;">
            </div>
            
            <input type="hidden" name="check_auth" value="1">
            <button type="submit" style="width:100%; background:#28a745; color:#fff; border:none; padding:15px; border-radius:6px; cursor:pointer; font-size:16px; font-weight:bold;">我已完成激活，点击生成</button>
        </form>
        <p style="text-align:center;"><a href="?reset=1" style="color:#999; font-size:12px;">重置重新开始</a></p>
    </div>
<?php
    exit;
}

// --- 第二步：换取 Token ---
if (!isset($_SESSION['device_code'])) {
    die("<h3 style='color:red;'>请先获取验证码</h3>");
}
$device_code = $_SESSION['device_code'];

$ch = curl_init('https://login.microsoftonline.com/consumers/oauth2/v2.0/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => $client_id,
    'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
    'device_code' => $device_code
]));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$res = json_decode(curl_exec($ch), true);
curl_close($ch);

if (isset($res['refresh_token'])) {
    $email = trim($_POST['email']);
    $pass = trim($_POST['pass']);
    $rt = $res['refresh_token'];
    $final_string = "{$email}----{$pass}----{$client_id}----{$rt}";
    
    session_destroy(); // 成功后清除
?>
    <div style="max-width:800px; margin:50px auto; font-family:sans-serif; padding:20px;">
        <h3 style="color:#28a745;">✅ 令牌获取成功！</h3>
        <p>请复制下方整行内容直接存入你的数据库：</p>
        <textarea style="width:100%; height:100px; padding:15px; font-family:monospace; background:#f4f4f4; border:1px solid #ccc; word-break:break-all; border-radius:8px;"><?= $final_string ?></textarea>
        <p style="margin-top:20px;"><a href="<?= $_SERVER['PHP_SELF'] ?>" style="background:#007bff; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px;">处理下一个账号</a></p>
    </div>
<?php
} else {
    $error = $res['error'] ?? 'unknown_error';
    if ($error === 'authorization_pending') {
        echo "<script>alert('尚未检测到授权，请在微软页面完成操作并输入验证码后再回来点击！'); window.history.back();</script>";
    } else {
        error_log('[OutlookPanel] 授权失败: ' . $error . ' | ' . ($res['error_description'] ?? ''));
        echo "<div style='color:red; padding:50px;'>授权失败，请检查账号或稍后重试</div>";
        echo "<a href='?reset=1'>返回重试</a>";
    }
}