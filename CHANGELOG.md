# 更新日志

## [1.1.0] - 2026-06-22

### 新增

#### 安全功能
- ✨ **图形验证码注册**：4位数字验证码，防止机器人注册
- ✨ **注册开关控制**：后台可开启/关闭自助注册
- ✨ **IP封禁管理**：支持临时封禁（指定分钟数）
- ✨ **设备指纹追踪**：记录登录设备指纹/IP/UA/登录次数
- ✨ **设备管理**：查看/拉黑/信任/删除登录设备
- ✨ **登录失败锁定**：连续失败超过阈值自动锁定
- ✨ **密码SHA256加密传输**：前端加密后传输
- ✨ **30分钟无操作退出**：前端自动检测，30分钟后提示退出

#### DNS 解析
- ✨ **DNS 服务商支持**：腾讯云DNS、阿里云DNS、华为云DNS
- ✨ **一键解析**：配置API凭证后可一键自动添加DNS记录
- ✨ **详细注释说明**：包含完整的服务商配置指南

#### 数据库新增
- `ms_ip_blacklist` - IP封禁表
- `ms_user_devices` - 设备管理表
- `ms_captchas` - 图形验证码表
- 新增设置项：`require_captcha`, `require_device_verify`, `session_timeout`, `dns_provider` 等

#### API 新增端点
- `GET /api/auth/captcha` - 获取图形验证码
- `POST /api/auth/register` - 用户注册
- `/api/security/*` - IP封禁与设备管理全系列端点
- `POST /api/domains/{id}/dns-sync` - 一键DNS同步

### 修复
- 修复 `CREATE USER IF NOT EXISTS` MariaDB 低版本语法错误
- 修复 rsync 未安装导致文件部署失败
- 修复 yum/apt 错误使用 ghfast.top 作为 HTTP 代理
- 修复 git 代理配置导致连接失败

## [1.0.2] - 2026-06-22

### 新增
- ✨ 宝塔面板环境支持
  - `install.sh` 自动检测宝塔环境并适配
  - 新增 `scripts/bt-install.sh` 宝塔专用安装脚本
  - 宝塔环境下自动跳过依赖安装/Nginx配置/防火墙配置
  - 自动检测宝塔 PHP 版本和 MySQL
  - 自动设置 www:www 权限
- ✨ README 新增宝塔安装说明（方式一）

### 修复
- 修复 `CREATE USER IF NOT EXISTS` 在 MariaDB 低版本语法错误
- 修复 rsync 未安装导致文件部署失败
- 修复 yum/apt 错误使用 ghfast.top 作为 HTTP 代理
- 修复 git 代理配置导致 Gitee 克隆失败

## [1.0.1] - 2026-06-22

### 新增
- ✨ 完整的 SMTP / POP3 / IMAP 协议服务端（原生 PHP socket 实现）
- ✨ 自定义端口支持：SMTP 25/465/587，POP3 110/995，IMAP 143/993
- ✨ SSL/TLS 加密支持（SMTPS/POP3S/IMAPS + STARTTLS）
- ✨ 美观的 Vue 3 + Element Plus 后台管理 SPA
- ✨ 独立的 Web 邮件端（类 Foxmail 风格）
- ✨ 域名绑定与多域名管理
- ✨ RESTful API（v1），支持 Bearer Token 与 API Key
- ✨ Maildir 邮件存储格式
- ✨ 自实现 MIME 解析（multipart, base64, quoted-printable）
- ✨ 前后端分离，后台路径与端口可自定义
- ✨ Linux 一键安装脚本（CentOS / Debian / Ubuntu）
- ✨ systemd 服务管理
- ✨ 完整的命令行工具（CLI 安装、服务启停、状态监控）
- ✨ Web 安装向导
- ✨ 单元测试 + 集成测试

### 技术栈
- **后端**：原生 PHP 7.4+，PDO，pdo_mysql，sockets，pcntl，openssl
- **前端**：Vue 3，Element Plus（CDN 引入）
- **数据库**：MySQL 5.7+ / MariaDB 10.x
- **Web 服务器**：Nginx + PHP-FPM

### 修复
- 修复 MimeParser 中 content-type 小写化导致 boundary 匹配失败的问题
- 修复中文显示名无法被 `iconv_mime_decode` 正确处理的 bug
- 修复 BaseServer 中 foreach 迭代时修改数组导致未定义键警告的问题
- 修复 fork 后子进程共享父进程数据库连接导致的 "MySQL server has gone away" 问题
- 修复 POP3 客户端初次连接时 state 未初始化的 bug
- 修复 closeClient 中对 null socket 调 fclose 抛错的 bug
- 修复 schema.sql 中 `ssl` 字段（MySQL 保留字）需要反引号的问题
- 修复 MailStorage 中新投递的邮件被错误写入 cur/ 而非 new/ 的问题
- 修复 install-cli.php 中 `getOpt` 与 PHP 内置函数冲突的问题
- 修复 .env.example 缺少 MAIL_HOSTNAME 默认值的问题
