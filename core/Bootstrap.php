<?php
/**
 * 入口引导（包 C Step 1）
 *
 * 唯一职责：按与拆分前完全一致的顺序加载依赖、校验登录，然后把 $db 与 $action 交给调用方。
 *
 * 明确禁止（写进代码是为了防止后续步骤顺手加东西）：
 *  - 不含任何业务逻辑（不读写业务表、不碰 $_POST）
 *  - 不 echo、不 exit：未登录时到底是 302 跳转还是 403 JSON，由 config.php 的
 *    check_auth() 自己决定，Bootstrap 一律不拦截、不改写
 *  - 不调换加载顺序：config（含 session_start 与 APP_KEY 回退）→ Crypto → check_auth
 */
final class Bootstrap
{
    /**
     * 引导外部依赖并返回 PDO 连接
     *
     * 返回值而不是依赖 global：config.php 被 require 进来时，它内部的 $db 落在本方法的
     * 局部作用域；显式 return 才能保证调用方拿到的一定是同一个连接对象。
     *
     * @throws PDOException config.php 内部已捕获并 die()，此处不会再抛
     */
    public static function boot(): PDO
    {
        // 顺序即契约，不许调换
        require_once dirname(__DIR__) . '/config.php';
        require_once dirname(__DIR__) . '/Crypto.php';
        check_auth();

        // 骨架类：Step 1 只加载不使用，业务搬迁从 Step 2 开始
        require_once __DIR__ . '/Http/ApiException.php';
        require_once __DIR__ . '/Http/Json.php';
        require_once __DIR__ . '/Repository/AccountRepository.php';
        require_once __DIR__ . '/Repository/SettingRepository.php';
        // E-1：分组两表（groups / group_accounts）的读写；E-2 才接到 api.php
        require_once __DIR__ . '/Repository/GroupRepository.php';
        require_once __DIR__ . '/Mail/MimeParser.php';
        require_once __DIR__ . '/Mail/ImapConfig.php';
        require_once __DIR__ . '/Mail/ImapClient.php';
        // D1-2：Graph 客户端（MailService 的 auto/graph 模式依赖；D1-1 遗留的加载点）
        require_once __DIR__ . '/Mail/GraphConfig.php';
        require_once __DIR__ . '/Mail/GraphClient.php';
        require_once __DIR__ . '/Service/AccountService.php';
        require_once __DIR__ . '/Service/SettingService.php';
        require_once __DIR__ . '/Service/MailService.php';
        require_once __DIR__ . '/Service/GroupService.php';
        require_once __DIR__ . '/Service/BackupService.php';

        /** @var PDO $db 由上面 require 的 config.php 在本作用域内创建 */
        return $db;
    }

    /**
     * 取 action 参数（原 api.php 第七行：$_GET['action'] ?? ''）
     *
     * 只做取值与类型收敛，不做任何合法性判断——判断属于后续 Service 层。
     */
    public static function action(): string
    {
        return (string) ($_GET['action'] ?? '');
    }
}
