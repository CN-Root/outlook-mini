# OutLook Mini

一个零依赖、单目录的 Outlook/Hotmail 邮箱账号托管面板。把多个 Outlook 账号的 OAuth2 凭据集中存进本地 SQLite，提供「批量导入 → 分组管理 → 在线只读收件箱 → 导出/备份」。

不是邮件客户端：不能发信、不能管理文件夹，本质是「凭据库 + 只读收件箱预览器」。

## 特性

- 零第三方依赖：无 composer、无前端框架、无构建流程
- 单目录部署：任意支持 PHP 8.0+ 的主机即可跑
- 凭据加密：`password`/`refresh_token` 用 libsodium 对称加密存库（`APP_KEY`）
- 双协议读信：Graph 优先，失败降级 IMAP（`mail_mode=auto/graph/imap`）
- 收件箱 + 垃圾邮件合并读，按时间排序取最新 15 封
- 分组管理：建组/改名/删组，组内增删账号，无分组自动退回默认分组
- 安全：Session 加固、登录限频、iframe sandbox + 服务端 HTML 白名单净化、`escapeHtml` + 事件委托

## 技术栈

| 层 | 选型 |
| --- | --- |
| 语言 | PHP 8.0+ |
| 数据库 | SQLite（PDO） |
| 鉴权 | Session + `password_hash`（bcrypt） |
| 邮件 | Graph API / IMAP 993（XOAUTH2） |
| OAuth | Microsoft identity platform v2.0 设备码授权 |
| 前端 | 原生 HTML/CSS/JS |
| 依赖扩展 | `pdo_sqlite`、`curl`、`openssl`、`mbstring`、`iconv`、`sodium` |

## 快速开始
### 1. 上传文件

上传 `index.php`、`dashboard.php`、`api.php`、`get_token.php`、`config.php`、`Crypto.php`、`core/`、`static/` 到站点根。

### 2. 建 data/ 目录

```
mkdir data && chmod 750 data
```

### 3. 生成 APP_KEY

```
php -r 'echo "<?php return \x27" . base64_encode(random_bytes(32)) . "\x27;\n";' > data/app_key.php
chmod 600 data/app_key.php
cat data/app_key.php   # 立刻备份，丢失 = 所有加密凭据永久解不开
```

### 4. 配置 Web 服务器

**必须**拦截 `data/`（Nginx）：

```
location ^~ /data/ {
    deny all;
    return 403;
}
```

验证：

```
curl -I https://你的域名/data/outlook.db          # 403
curl -I https://你的域名/data/app_key.php         # 403
```

**必须启用 HTTPS。**

### 5. 首次访问

浏览器打开 `index.php`，`config.php` 自动建表并生成随机管理员口令，写入 `error_log`。从日志取口令登录，**立即改密**。

## 配置

| 项 | 说明 |
| --- | --- |
| `APP_KEY` | 环境变量或 `data/app_key.php`，base64 32 字节密钥，**与 `outlook.db` 成对备份、永不同时更换** |
| `mail_mode` | 设置页切换：`auto` / `graph` / `imap` |
| `data/outlook.db` | 生产库，**绝不上传/公开** |

## 目录结构

```
.
├── index.php            登录页
├── dashboard.php        控制台三栏 UI
├── api.php              API 路由
├── get_token.php        设备码授权向导
├── config.php           引导
├── Crypto.php           加解密
├── core/
│   ├── Bootstrap.php
│   ├── Http/            ApiException、Json
│   ├── Mail/            ImapClient、GraphClient、MimeParser、HtmlSanitizer 等
│   ├── Repository/      Account、Group、Setting
│   └── Service/         Account、Backup、Group、Mail、Setting
├── static/image/favicon/
└── data/                （部署时新建）
```

## 功能边界

- **能读**：收件箱 + 垃圾邮件文件夹各最新 15 封，合并按时间排序
- **读不到**：附件、其他文件夹、15 封以外历史、全文搜索、发信

## 安全说明

- `data/` 必须由 Web 服务器拦截，`APP_KEY` 与库成对备份
- 已知缺口：CSRF 未做（内网可接受）、无审计日志、单管理员设计

## 开源协议

MIT License，见 `LICENSE`。

## 免责声明

本项目仅供个人合法用途。批量保管他人邮箱凭据可能违反 Microsoft 服务条款，使用者需自行确保授权合规。作者不对滥用负责。