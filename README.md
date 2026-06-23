# MailSystem - 轻量级自托管邮件系统

一个基于 PHP + MySQL 的完整自托管邮件系统，支持自定义 SMTP 发送端口（25/465/587）与 POP3/IMAP 接收端口（110/995/143/993），提供美观的可视化后台管理、域名绑定、API 对接、Linux 一键部署。

## ✨ 核心特性

- 📬 **完整邮件协议**：自实现 SMTP / POP3 / IMAP 服务端（基于 PHP socket），无依赖外部 MTA
- 🔒 **多端口 SSL 支持**：SMTP 465/587（STARTTLS）、POP3 995、IMAP 993
- 🌐 **域名绑定**：支持绑定多个域名，独立管理邮件账户
- 🎨 **前后端分离**：Vue 3 + Element Plus 后台 SPA + 独立 Web 邮件端
- 🔧 **后台管理可定制**：管理路径、访问端口可自定义
- 🔌 **API 对接**：RESTful API，支持 API Key 认证
- 🚀 **一键安装**：自动检测系统、自动安装依赖（PHP / MariaDB / Nginx）
- 🐧 **Linux 部署**：支持 CentOS / Debian / Ubuntu，提供 systemd 服务管理
- 🔐 **安全功能**：IP封禁、设备指纹追踪、图形验证码、30分钟无操作退出
- 📡 **DNS 解析**：支持腾讯云DNS、阿里云DNS、华为云DNS一键解析

## 📋 系统要求

- **PHP** 7.4+ (推荐 8.0+)
- **PHP 扩展**：pdo_mysql, sockets, openssl, mbstring, iconv, pcntl, posix
- **MySQL / MariaDB** 5.7+
- **Linux** 内核 3.10+（CentOS 7+/Debian 10+/Ubuntu 18.04+）
- **Web 服务器**（可选）：Nginx / Apache

## 🚀 安装方式

### 方式一：宝塔面板安装（推荐国内用户）

宝塔面板提供可视化界面管理服务器，适合不熟悉命令行的用户。

---

#### ▎前置条件

```
┌─────────────────────────────────────────────────┐
│ ① 已安装宝塔面板                                  │
│   yum install -y wget && wget -O install.sh      │
│   http://download.bt.cn/install/install_6.0.sh   │
│   && sh install.sh                                │
├─────────────────────────────────────────────────┤
│ ② 已安装以下软件（宝塔面板→软件商店）              │
│   ☑ Nginx 1.18+                                  │
│   ☑ PHP 7.4 / 8.0 / 8.1 / 8.2                    │
│   ☑ MySQL 5.7+ / MariaDB 10.x                     │
│   ☑ PHP扩展: pdo_mysql, sockets, openssl,        │
│     mbstring, iconv, pcntl                       │
└─────────────────────────────────────────────────┘
```

> **注意**: PHP 扩展安装方法：宝塔面板 → 软件商店 → PHP设置 → 安装扩展

---

#### ▎安装步骤

**第一步：下载源码**

```
┌─────────────────────────────────────────────────────────────┐
│ 宝塔面板 → 文件 → 远程下载                                   │
│                                                             │
│ 下载地址（任选一个）：                                       │
│ ① GitHub 直连:                                             │
│   https://github.com/jiujiu123520/mail-system/archive/       │
│            refs/heads/main.zip                              │
│ ② 国内加速镜像:                                             │
│   https://gh.jasonzeng.dev/https://github.com/               │
│   jiujiu123520/mail-system/archive/refs/heads/main.zip      │
│                                                             │
│ 保存路径: /opt/                                             │
└─────────────────────────────────────────────────────────────┘
```

或者使用命令行下载：

```bash
# SSH登录服务器
ssh root@你的服务器IP

# 下载到 /opt 目录（任选一种方式）
cd /opt

# 方式①：GitHub 直连
wget -O mail-system-main.zip https://github.com/jiujiu123520/mail-system/archive/refs/heads/main.zip

# 方式②：国内加速镜像（GitHub 下载慢时使用）
wget -O mail-system-main.zip https://gh.jasonzeng.dev/https://github.com/jiujiu123520/mail-system/archive/refs/heads/main.zip

# 解压
unzip mail-system-main.zip
mv mail-system-main mail-system
cd mail-system
chmod +x scripts/*.sh
```

**第二步：宝塔创建站点**

```
┌─────────────────────────────────────────────────────────────┐
│ 宝塔面板操作：                                              │
│                                                             │
│ 网站 → 添加站点 → 填写以下信息：                             │
│                                                             │
│  ┌─────────────────────────────────────────────────┐        │
│  │ 域名:     mail.yourdomain.com                    │        │
│  │ 根目录:   /www/wwwroot/mailsystem                │        │
│  │ PHP版本:  选择 PHP 7.4 / 8.0 / 8.1 / 8.2        │        │
│  │ FTP:     不创建                                  │        │
│  │ 数据库:  创建 MySQL 数据库                        │        │
│  │  ├─ 数据库名:  mail_system                       │        │
│  │  ├─ 用户名:   mail_user                          │        │
│  │  └─ 密码:    记录下自动生成的密码                 │        │
│  └─────────────────────────────────────────────────┘        │
└─────────────────────────────────────────────────────────────┘
```

**第三步：运行安装脚本**

```bash
cd /opt/mail-system
sudo bash scripts/bt-install.sh
```

安装脚本会提示你输入数据库密码（就是上一步记录的那个），输入后会自动完成：

```
┌─────────────────────────────────────────────────────┐
│ [INFO] 检测到宝塔面板环境                              │
│ [ OK ] PHP 版本: 80                                   │
│ [ OK ] MySQL 已安装                                   │
│                                                       │
│ 请在宝塔面板完成以下操作：                              │
│   1. 网站 → 添加站点                                   │
│      - 填写域名                                       │
│      - 根目录: /www/wwwroot/mailsystem                 │
│      - PHP 版本: 80                                   │
│   2. 创建数据库                                       │
│   3. 完成后，输入数据库密码继续安装                      │
│                                                       │
│ 请输入数据库密码: ****                                 │
│                                                       │
│ [ OK ] 文件已部署                                      │
│ [ OK ] .env 已生成                                     │
│ [ OK ] 数据库已初始化                                  │
│ ───────────────────────────────────────────────────   │
│ 安装完成！                                             │
└─────────────────────────────────────────────────────┘
```

**第四步：配置网站目录**

```
┌─────────────────────────────────────────────────────────────┐
│ 宝塔面板 → 网站 → 选择站点(mail.yourdomain.com)              │
│  → 设置 → 网站目录                                          │
│                                                             │
│  ┌─────────────────────────────────────────────────┐        │
│  │ ☑ 启用防跨站攻击(open_basedir)                   │        │
│  │ 运行目录: 选择 /public                          │        │
│  └─────────────────────────────────────────────────┘        │
│                                                             │
│ 示例:                                                       │
│   正确: /www/wwwroot/mailsystem/public                      │
│   错误: /www/wwwroot/mailsystem                             │
└─────────────────────────────────────────────────────────────┘
```

**第五步：配置伪静态**

```
┌─────────────────────────────────────────────────────────────┐
│ 宝塔面板 → 网站 → 选择站点 → 设置 → 伪静态                   │
│                                                             │
│ 下拉选择: Laravel5                                          │
│ 或手动填入:                                                 │
│                                                             │
│  ┌─────────────────────────────────────────────────┐        │
│  │ location / {                                      │        │
│  │     try_files $uri $uri/ /index.php?$query_string;│        │
│  │ }                                                 │        │
│  └─────────────────────────────────────────────────┘        │
└─────────────────────────────────────────────────────────────┘
```

**第六步：放行邮件端口**

```
┌─────────────────────────────────────────────────────────────┐
│ 宝塔面板 → 安全 → 放行端口                                   │
│                                                             │
│ 需要放行的端口（共8个）：                                    │
│  ┌─────────────────────────────────────────────────┐        │
│  │ 25   → SMTP 发送（必须）                        │        │
│  │ 465  → SMTP SSL（推荐）                        │        │
│  │ 587  → SMTP STARTTLS（推荐）                   │        │
│  │ 110  → POP3（可选）                            │        │
│  │ 995  → POP3 SSL（推荐）                        │        │
│  │ 143  → IMAP（可选）                            │        │
│  │ 993  → IMAP SSL（推荐）                        │        │
│  │ 8080 → 管理后台端口（如果开启）                  │        │
│  └─────────────────────────────────────────────────┘        │
│                                                             │
│ 放行方法: 安全 → 添加端口规则 → 输入端口号 → 提交            │
└─────────────────────────────────────────────────────────────┘
```

**第七步：启动邮件服务**

```bash
cd /www/wwwroot/mailsystem
sudo bash scripts/service.sh start
```

验证启动：

```bash
# 查看服务状态
sudo bash scripts/service.sh status

# 查看端口监听
ss -tlnp | grep -E '25|465|587|110|995|143|993'
```

**第八步：访问后台**

```
┌─────────────────────────────────────────────────────────────┐
│ 打开浏览器访问:                                             │
│                                                             │
│   http://mail.yourdomain.com/admin                          │
│                                                             │
│  ┌─────────────────────────────────────────────────┐        │
│  │ 用户名: admin                                    │        │
│  │ 密码:   安装脚本打印的密码（可在.env中查看）       │        │
│  └─────────────────────────────────────────────────┘        │
└─────────────────────────────────────────────────────────────┘
```

---

### 方式二：一键安装脚本（纯净系统，自动装依赖）

适用于 CentOS 7+/Debian 10+/Ubuntu 18+ 全新系统，脚本自动安装 PHP/MySQL/Nginx。

---

#### ▎安装步骤（全自动）

**第一步：下载源码并运行脚本**

```bash
# 1. SSH登录服务器
ssh root@你的服务器IP

# 2. 下载源码（任选一种方式）
cd /opt

# 方式①：GitHub 直连
wget -O mail-system-main.zip https://github.com/jiujiu123520/mail-system/archive/refs/heads/main.zip

# 方式②：国内加速镜像（GitHub 下载慢时使用）
wget -O mail-system-main.zip https://gh.jasonzeng.dev/https://github.com/jiujiu123520/mail-system/archive/refs/heads/main.zip

unzip mail-system-main.zip
mv mail-system-main mail-system
cd mail-system
chmod +x scripts/*.sh

# 3. 运行一键安装脚本
sudo bash scripts/install.sh
```

**第二步：交互式配置**

运行脚本后，会进入交互问答，如下所示：

```
┌─────────────────────────────────────────────────────┐
│  __  __          _ _           ____            _    │
│ |  \/  | ___  __| (_) ___ ___ / ___|  ___ _ __(_)   │
│ ...                                                 │
│        Self-hosted Mail System v1.0.0                │
│                                                     │
│ 即将开始安装，配置如下：                              │
│   Web 目录:   /var/www/mailsystem                    │
│   数据库:     mail_user@127.0.0.1:3306/mail_system   │
│   管理员:     admin / aB3xK9mP2wR7                   │
│   后台路径:   /admin/                                │
│   邮件主机名: mail.local                             │
│                                                     │
│ 按 Enter 继续，Ctrl+C 取消...                        │
└─────────────────────────────────────────────────────┘
```

**第三步：等待自动安装**

安装脚本会自动完成以下流程（无需人工干预）：

```
┌─────────────────────────────────────────────────────┐
│ 进度条                                            │
│                                                     │
│ [1/8] 检测系统类型... ✓ CentOS 7.9                  │
│ [2/8] 安装 PHP/MySQL/Nginx... ✓                     │
│ [3/8] 部署文件... ✓                                 │
│ [4/8] 初始化数据库... ✓                             │
│ [5/8] 生成配置文件... ✓                             │
│ [6/8] 配置 Nginx... ✓                              │
│ [7/8] 配置防火墙... ✓                              │
│ [8/8] 启动服务... ✓                                │
│                                                     │
│ ─────────────────────────────────────────────────   │
│  MailSystem 安装完成！                               │
│ ─────────────────────────────────────────────────   │
│                                                     │
│  后台地址:  http://服务器IP/admin/                   │
│  默认账号:  admin                                   │
│  默认密码:  aB3xK9mP2wR7                            │
│                                                     │
│  SMTP:   25 / 465 / 587                             │
│  POP3:   110 / 995                                  │
│  IMAP:   143 / 993                                  │
└─────────────────────────────────────────────────────┘
```

**第四步：访问后台**

```
┌─────────────────────────────────────────────────────────────┐
│ 打开浏览器 → 输入 http://你的服务器IP/admin/                  │
│                                                             │
│  ┌─────────────────────────────────────────────────┐        │
│  │ 用户名: admin                                    │        │
│  │ 密码:   安装完成后打印的密码                     │        │
│  │                                                   │        │
│  │ 登录后建议立即修改默认密码！                       │        │
│  └─────────────────────────────────────────────────┘        │
└─────────────────────────────────────────────────────────────┘
```

---

### 方式三：手动安装（自定义环境）

适合已有 LAMP/LEMP 环境的用户，手动配置每一个组件。

---

#### ▎安装步骤

**步骤 1：安装系统依赖**

```bash
# ┌─ CentOS / RHEL ──────────────────────────────────┐
sudo yum install -y epel-release
sudo yum install -y nginx mariadb-server mariadb \
    php php-fpm php-cli php-mysqlnd php-pdo \
    php-mbstring php-iconv php-openssl php-curl \
    php-xml php-zip php-sockets php-pcntl firewalld

# ┌─ Debian / Ubuntu ────────────────────────────────┐
sudo apt update
sudo apt install -y nginx mariadb-server mariadb-client \
    php php-fpm php-cli php-mysql php-mbstring \
    php-xml php-curl php-zip php-sockets php-pcntl \
    php-openssl php-iconv ufw
```

**步骤 2：配置数据库**

```bash
# 启动 MariaDB
sudo systemctl enable --now mariadb

# 安全配置（设置root密码等）
sudo mysql_secure_installation

# 登录 MySQL
sudo mysql -u root -p
```

在 MySQL 提示符下执行：

```sql
-- ┌─────────────────────────────────────────────┐
-- │ 创建数据库                                   │
CREATE DATABASE mail_system DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- │ 创建用户并授权                               │
CREATE USER 'mail_user'@'localhost' IDENTIFIED BY '你的密码';
GRANT ALL PRIVILEGES ON mail_system.* TO 'mail_user'@'localhost';
FLUSH PRIVILEGES;

-- │ 退出                                        │
EXIT;
```

**步骤 3：下载源码并部署**

```bash
# 下载源码（任选一种方式）
cd /var/www

# 方式①：GitHub 直连
wget -O mail-system-main.zip https://github.com/jiujiu123520/mail-system/archive/refs/heads/main.zip

# 方式②：国内加速镜像（GitHub 下载慢时使用）
wget -O mail-system-main.zip https://gh.jasonzeng.dev/https://github.com/jiujiu123520/mail-system/archive/refs/heads/main.zip

unzip mail-system-main.zip
mv mail-system-main mailsystem
cd mailsystem

# 导入数据库
mysql -u mail_user -p mail_system < database/schema.sql
```

**步骤 4：创建配置文件**

```bash
# 生成 APP_KEY
APP_KEY=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)

# 创建 .env 文件
cat > /var/www/mailsystem/.env <<ENVEOF
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=http://localhost

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=mail_system
DB_USER=mail_user
DB_PASS=你的密码

ADMIN_PATH=admin
ADMIN_PORT=8080

MAIL_HOSTNAME=mail.yourdomain.com
ENVEOF

# 设置权限
sudo chmod 600 /var/www/mailsystem/.env

# 创建必要目录
mkdir -p /var/www/mailsystem/storage/cache
mkdir -p /var/www/mailsystem/storage/sessions
mkdir -p /var/www/mailsystem/data/mailboxes
mkdir -p /var/www/mailsystem/logs
```

**步骤 5：设置目录权限**

```bash
# ┌─ CentOS / RHEL ────────────────────────────────┐
sudo chown -R nginx:nginx /var/www/mailsystem
# ┌─ Debian / Ubuntu ──────────────────────────────┐
# sudo chown -R www-data:www-data /var/www/mailsystem

# 通用权限设置
sudo chmod -R 755 /var/www/mailsystem
sudo chmod -R 770 /var/www/mailsystem/storage
sudo chmod -R 770 /var/www/mailsystem/data
sudo chmod -R 770 /var/www/mailsystem/logs
```

**步骤 6：配置 Nginx**

```bash
sudo tee /etc/nginx/conf.d/mailsystem.conf > /dev/null <<'EOF'
server {
    listen 80;
    server_name mail.yourdomain.com;

    root /var/www/mailsystem/public;
    index index.php index.html;

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /(storage|data|logs|\.env|\.git) {
        deny all;
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf)$ {
        expires 30d;
        access_log off;
    }
}
EOF

# 测试配置并重载
sudo nginx -t && sudo systemctl reload nginx
```

**步骤 7：配置 PHP-FPM**

```bash
# 编辑 PHP-FPM 配置
sudo sed -i 's/^user = .*/user = nginx/' /etc/php-fpm.d/www.conf
sudo sed -i 's/^group = .*/group = nginx/' /etc/php-fpm.d/www.conf
sudo sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /etc/php-fpm.d/www.conf

# 启动 PHP-FPM
sudo systemctl enable --now php-fpm
```

**步骤 8：安装系统 + 启动服务**

```bash
cd /var/www/mailsystem

# 运行安装程序（初始化管理员账户等）
php bin/install-cli.php \
    --admin-user=admin \
    --admin-pass=你的管理员密码 \
    --admin-email=admin@yourdomain.com \
    --default-domain=yourdomain.com

# 启动邮件服务
sudo bash scripts/service.sh start

# 验证状态
sudo bash scripts/service.sh status
```

**步骤 9：配置防火墙**

```bash
# ┌─ CentOS (firewalld) ───────────────────────────┐
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-port=25/tcp
sudo firewall-cmd --permanent --add-port=465/tcp
sudo firewall-cmd --permanent --add-port=587/tcp
sudo firewall-cmd --permanent --add-port=110/tcp
sudo firewall-cmd --permanent --add-port=995/tcp
sudo firewall-cmd --permanent --add-port=143/tcp
sudo firewall-cmd --permanent --add-port=993/tcp
sudo firewall-cmd --reload

# ┌─ Debian/Ubuntu (ufw) ─────────────────────────┐
sudo ufw allow http
sudo ufw allow 25/tcp && sudo ufw allow 465/tcp
sudo ufw allow 587/tcp && sudo ufw allow 110/tcp
sudo ufw allow 995/tcp && sudo ufw allow 143/tcp
sudo ufw allow 993/tcp
sudo ufw --force enable
```

---

#### ▎三种安装方式对比

```
┌─────────────────────────────────────────────────────────────────────────┐
│  对比项目      │  宝塔面板安装    │  一键脚本安装    │  手动安装         │
├─────────────────────────────────────────────────────────────────────────┤
│  难度          │  ⭐ 低          │  ⭐⭐ 中低       │  ⭐⭐⭐⭐ 高      │
│  自动化程度    │  部分自动        │  全自动          │  全部手动         │
│  适合用户      │  新手/国内用户   │  中等用户        │  资深用户         │
│  环境要求      │  已装宝塔面板    │  纯净系统        │  任意环境         │
│  依赖安装      │  面板手动装      │  自动安装        │  手动安装         │
│  Nginx配置     │  面板可视化      │  自动配置        │  手动配置         │
│  耗时          │  ~10分钟         │  ~15分钟         │  ~30分钟          │
└─────────────────────────────────────────────────────────────────────────┘
```

## 🎯 端口规划

| 服务 | 端口 | 加密 | 说明 |
|------|------|------|------|
| SMTP | 25 | 无 | 标准 SMTP 发送 |
| SMTP | 465 | SSL | SMTPS（隐式 TLS） |
| SMTP | 587 | STARTTLS | 提交端口（推荐） |
| POP3 | 110 | 无 | 标准 POP3 |
| POP3 | 995 | SSL | POP3S |
| IMAP | 143 | 无 | 标准 IMAP |
| IMAP | 993 | SSL | IMAPS |

所有端口都可以在后台管理界面里**动态启用/禁用/修改**（后台 → 端口管理）。

## 🔐 安全功能

### 用户注册与验证
- **图形验证码**：注册时需输入4位数字验证码，防止机器人注册
- **注册开关控制**：后台可开启/关闭自助注册功能
- **密码加密传输**：前端对密码进行SHA256加密后传输

### 登录安全
- **IP封禁**：管理员可封禁指定IP地址，支持临时封禁（到期自动解封）
- **设备指纹追踪**：记录用户登录设备指纹、IP地址、浏览器UA
- **设备管理**：用户可查看和管理自己的登录设备，管理员可拉黑可疑设备
- **登录失败锁定**：连续失败多次后自动锁定

### 会话安全
- **无操作超时**：30分钟无操作自动退出登录，防止他人使用已登录账号
- **会话续期**：点击"继续使用"按钮可延长会话

### IP封禁与设备管理
后台 → 安全中心：
- IP封禁列表：查看/添加/删除封禁记录
- 设备管理：查看所有用户登录设备，拉黑/信任/删除设备

## 🖥️ 后台管理

访问：`http://your-server:8080/admin/`（路径与端口可在安装时自定义）

### 功能模块
- **仪表盘**：系统状态、流量统计
- **域名管理**：添加/删除/启停域名，DNS 记录指引
- **邮箱管理**：创建/删除/重置密码邮箱，容量限制
- **端口管理**：自定义 SMTP/POP3/IMAP 端口与 SSL 设置
- **邮件列表**：查看所有邮件（按用户/域筛选）
- **API 密钥管理**：生成/吊销 API Key
- **系统设置**：网站名称、Logo、Maildir 路径
- **用户管理**：管理员与普通用户 CRUD
- **系统日志**：操作日志与系统日志
- **系统信息**：PHP 版本、扩展检测、磁盘空间

## 🌐 Web 邮件端

访问：`http://your-server/`（普通端口）

类似 Foxmail 风格的多标签邮件界面，支持：
- 登录 / 退出
- 收件箱 / 草稿 / 发送 / 垃圾箱
- 撰写邮件（支持 HTML）
- 邮件详情查看（text/html 双视图）
- 删除邮件

## 🔌 API 对接

所有 API 端点位于 `/api/v1/`，支持两种认证方式：

### 1. Bearer Token
```bash
curl -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"your_password"}'

# 返回
{"code":0,"data":{"token":"...","expires_at":"..."}}
```

### 2. API Key
```bash
curl http://localhost/api/v1/emails \
  -H "X-API-Key: ms_live_xxxxxxxxxxxxxxxx"
```

### 主要端点
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/v1/auth/login` | 登录获取 Token |
| GET  | `/api/v1/auth/me` | 当前用户信息 |
| GET  | `/api/v1/domains` | 域名列表 |
| POST | `/api/v1/domains` | 添加域名 |
| GET  | `/api/v1/mailboxes` | 邮箱列表 |
| POST | `/api/v1/mailboxes` | 创建邮箱 |
| GET  | `/api/v1/emails` | 邮件列表 |
| POST | `/api/v1/emails` | 发送邮件 |
| GET  | `/api/v1/emails/{id}` | 邮件详情 |
| DELETE | `/api/v1/emails/{id}` | 删除邮件 |

详细 API 文档参见 `docs/API.md`。

## 📂 项目结构

```
mail-system/
├── app/                       # 核心代码
│   ├── Core/                  # 框架核心（Database/Request/Response/Auth/MimeParser...）
│   ├── Controllers/           # HTTP 控制器
│   ├── Models/                # 数据模型
│   └── Services/              # 协议服务端 (SMTP/POP3/IMAP)
├── public/                    # Web 入口
│   ├── admin/                 # 后台管理前端
│   ├── web/                   # Web 邮件前端
│   ├── api.php                # API 路由
│   └── index.php              # 统一入口
├── config/                    # 配置文件
├── database/                  # 数据库 Schema
├── scripts/                   # 部署脚本
│   ├── install.sh             # 一键安装
│   ├── service.sh             # 服务管理
│   └── uninstall.sh           # 卸载
├── bin/                       # CLI 工具
│   ├── install-cli.php        # CLI 安装程序
│   └── services.php           # 邮件服务启停
├── storage/                   # 运行时数据
├── logs/                      # 日志
├── data/                      # 邮件存储（Maildir）
├── tests/                     # 测试
└── install/                   # Web 安装向导
```

## 🔧 服务管理

```bash
# 启动 / 停止 / 重启 / 状态
php bin/services.php start
php bin/services.php stop
php bin/services.php restart
php bin/services.php status

# 或通过 systemd（安装脚本会注册）
sudo systemctl start  mail-system
sudo systemctl stop   mail-system
sudo systemctl status mail-system
sudo journalctl -u mail-system -f
```

## 🧪 测试

```bash
# 单元 + 集成测试
php tests/test.php

# 端到端集成测试（需要 MySQL 已配置）
php tests/integration_test.php
```

## 🔐 安全建议

1. **生产环境务必启用 HTTPS**（后台与 Web 端）
2. **修改默认管理员密码**（`admin/admin123` 仅为演示）
3. **配置防火墙**仅开放必要端口
4. **定期备份** `data/mailboxes/` 与 MySQL
5. **API Key 妥善保管**（泄露后可吊销重发）
6. **配置 SPF/DKIM/DMARC** 提升投递率

## 🌐 域名 DNS 配置

要让其他服务器接收您域名发出的邮件，请配置以下 DNS 记录：

```
A     mail.yourdomain.com     <your-server-ip>
MX    yourdomain.com          mail.yourdomain.com (优先级 10)
TXT   yourdomain.com          v=spf1 mx ~all
TXT   _dmarc.yourdomain.com   v=DMARC1; p=quarantine; rua=mailto:admin@yourdomain.com
```

## 🐛 故障排查

| 现象 | 检查 |
|------|------|
| 端口未监听 | `php bin/services.php status` / `ss -tlnp \| grep 25` |
| 邮件无法发送 | 检查 `logs/smtp-*.log` |
| 邮件无法接收 | 检查 `logs/pop3-*.log` 和 `data/mailboxes/` 权限 |
| 后台无法访问 | 检查 `.env` 中 `ADMIN_PATH`、`ADMIN_PORT` 与 Nginx 配置 |
| 数据库连接失败 | 确认 MariaDB 已启动：`systemctl status mariadb` |

## 📜 许可证

MIT License

## 🤝 贡献

欢迎提交 Issue 与 Pull Request！

## 📮 联系方式

- Issues: [GitHub Issues](https://github.com/yourname/mail-system/issues)
- Email: support@example.com
