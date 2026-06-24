#!/usr/bin/env bash
# ============================================
# MailSystem 一键安装脚本
# 支持: CentOS 7+ / Debian 9+ / Ubuntu 18+ / 宝塔面板
#
# 用法 (一键安装):
#   curl -sSL https://raw.githubusercontent.com/jiujiu123520/mail-system/main/install.sh | bash
#   或
#   wget -qO- https://raw.githubusercontent.com/jiujiu123520/mail-system/main/install.sh | bash
#
# 用法 (带参数):
#   curl -sSL https://raw.githubusercontent.com/jiujiu123520/mail-system/main/install.sh | bash -s -- --domain=mail.example.com --admin-pass=yourpass
# ============================================

set -e

# 颜色
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# 版本与下载地址
GITHUB_REPO="jiujiu123520/mail-system"
GITHUB_BRANCH="main"
DOWNLOAD_URL="https://github.com/${GITHUB_REPO}/archive/refs/heads/${GITHUB_BRANCH}.tar.gz"

# 路径
INSTALL_DIR="/opt/mail-system-tmp"
WEB_ROOT_DEFAULT="/var/www/mailsystem"
WEB_ROOT="${WEB_ROOT:-$WEB_ROOT_DEFAULT}"

# 宝塔环境
BT_PANEL_PATH="/www/server/panel"
BT_WEB_ROOT="/www/wwwroot"
IS_BT_ENV=0
PHP_BIN=""
BT_MYSQL_ROOT_PASS=""

# 标志
SKIP_DEPS=0
SKIP_NGINX=0
SKIP_FIREWALL=0
SKIP_SERVICE=0
SKIP_DB=0
SILENT=0
FORCE=0

# 参数
DB_HOST="127.0.0.1"
DB_PORT=3306
DB_NAME="mail_system"
DB_USER="mail_user"
DB_PASS=""
ADMIN_USER="admin"
ADMIN_PASS=""
ADMIN_EMAIL="admin@localhost.com"
ADMIN_PATH="admin"
ADMIN_PORT=8080
MAIL_HOSTNAME="mail.local"
DOMAIN=""

print_banner() {
cat <<'EOF'
 __  __          _ _           ____            _       _
|  \/  | ___  __| (_) ___ ___ / ___|  ___ _ __(_)_   _| |_
| |\/| |/ _ \/ _` | |/ __/ _ \\___ \ / _ \ '__| | | | | __|
| |  | |  __/ (_| | | (_|  __/ ___) |  __/ |  | | |_| | |_
|_|  |_|\___|\__,_|_|\___\___||____/ \___|_|  |_|\__,_|\__|

        Self-hosted Mail System - 一键安装
EOF
}

log_info()  { echo -e "${BLUE}[INFO]${NC} $*"; }
log_ok()    { echo -e "${GREEN}[ OK ]${NC} $*"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_err()   { echo -e "${RED}[FAIL]${NC} $*"; }

usage() {
cat <<EOF
MailSystem 一键安装脚本

用法:
  curl -sSL <脚本地址> | bash [-- 参数]

选项:
  --domain=DOMAIN         站点域名 (同时作为 mail_hostname)
  --web-root=DIR          Web 部署目录 (默认: /var/www/mailsystem)
  --db-host=HOST          MySQL 主机 (默认: 127.0.0.1)
  --db-port=PORT          MySQL 端口 (默认: 3306)
  --db-name=NAME          数据库名 (默认: mail_system)
  --db-user=USER          数据库用户 (默认: mail_user)
  --db-pass=PASS          数据库密码 (随机生成)
  --admin-user=USER       管理员用户名 (默认: admin)
  --admin-pass=PASS       管理员密码 (随机生成)
  --admin-email=EMAIL     管理员邮箱
  --admin-path=PATH       后台路径 (默认: admin)
  --mail-hostname=HOST    邮件主机名
  --skip-deps             跳过依赖安装
  --skip-nginx            跳过 Nginx 配置
  --skip-firewall         跳过防火墙配置
  --skip-service          跳过服务启动
  --skip-db               跳过数据库初始化
  --silent                静默模式 (全自动，无交互)
  --force                 强制重新安装 (覆盖已存在的安装)
  --help                  显示帮助
EOF
}

parse_args() {
    for arg in "$@"; do
        case $arg in
            --domain=*)        DOMAIN="${arg#*=}" ;;
            --web-root=*)      WEB_ROOT="${arg#*=}" ;;
            --db-host=*)       DB_HOST="${arg#*=}" ;;
            --db-port=*)       DB_PORT="${arg#*=}" ;;
            --db-name=*)       DB_NAME="${arg#*=}" ;;
            --db-user=*)       DB_USER="${arg#*=}" ;;
            --db-pass=*)       DB_PASS="${arg#*=}" ;;
            --admin-user=*)    ADMIN_USER="${arg#*=}" ;;
            --admin-pass=*)    ADMIN_PASS="${arg#*=}" ;;
            --admin-email=*)   ADMIN_EMAIL="${arg#*=}" ;;
            --admin-path=*)    ADMIN_PATH="${arg#*=}" ;;
            --admin-port=*)    ADMIN_PORT="${arg#*=}" ;;
            --mail-hostname=*) MAIL_HOSTNAME="${arg#*=}" ;;
            --skip-deps)       SKIP_DEPS=1 ;;
            --skip-nginx)      SKIP_NGINX=1 ;;
            --skip-firewall)   SKIP_FIREWALL=1 ;;
            --skip-service)    SKIP_SERVICE=1 ;;
            --skip-db)         SKIP_DB=1 ;;
            --silent)          SILENT=1 ;;
            --force)           FORCE=1 ;;
            --help|-h)         usage; exit 0 ;;
            *) log_warn "未知参数: $arg" ;;
        esac
    done

    # 如果设置了 domain，同时作为 mail_hostname
    if [ -n "$DOMAIN" ] && [ "$MAIL_HOSTNAME" = "mail.local" ]; then
        MAIL_HOSTNAME="$DOMAIN"
    fi
}

check_root() {
    if [ "$EUID" -ne 0 ]; then
        log_err "请使用 root 权限运行: sudo $0"
        exit 1
    fi
}

detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS_ID="$ID"
        OS_VER="$VERSION_ID"
        OS_LIKE="$ID_LIKE"
    else
        log_err "无法识别操作系统，请使用 CentOS 7+ / Debian 9+ / Ubuntu 18+"
        exit 1
    fi
    log_info "操作系统: $PRETTY_NAME"
}

detect_bt_panel() {
    if [ -d "$BT_PANEL_PATH" ] && [ -f "$BT_PANEL_PATH/class/common.py" ]; then
        IS_BT_ENV=1
        log_ok "检测到宝塔面板环境"

        # 自动识别 PHP 版本
        for ver in 83 82 81 80 74; do
            if [ -x "/www/server/php/$ver/bin/php" ]; then
                PHP_BIN="/www/server/php/$ver/bin/php"
                log_info "宝塔 PHP 版本: $ver"
                break
            fi
        done

        # 设置默认 Web 目录
        if [ "$WEB_ROOT" = "$WEB_ROOT_DEFAULT" ]; then
            WEB_ROOT="$BT_WEB_ROOT/mailsystem"
            log_info "Web 目录自动设置为: $WEB_ROOT"
        fi

        # 宝塔环境跳过依赖安装和 Nginx 配置
        SKIP_DEPS=1
        SKIP_NGINX=1
        SKIP_FIREWALL=1

        # 尝试获取 MySQL root 密码
        if [ -f "$BT_PANEL_PATH/data/default.db" ] && command -v sqlite3 >/dev/null 2>&1; then
            BT_MYSQL_ROOT_PASS=$(sqlite3 "$BT_PANEL_PATH/data/default.db" "select mysql_root from config" 2>/dev/null || true)
        fi
        if [ -z "$BT_MYSQL_ROOT_PASS" ] && [ -f "$BT_PANEL_PATH/data/default.pl" ]; then
            BT_MYSQL_ROOT_PASS=$(grep -oP 'mysql_root\s*[=:]\s*\K[^,\s]+' "$BT_PANEL_PATH/data/default.pl" 2>/dev/null | head -1 | tr -d "'\" " || true)
        fi
        if [ -n "$BT_MYSQL_ROOT_PASS" ]; then
            log_ok "已获取宝塔 MySQL root 密码"
        fi
    fi

    # 如果还没找到 PHP，用系统的
    if [ -z "$PHP_BIN" ]; then
        PHP_BIN=$(command -v php 2>/dev/null || echo php)
    fi
}

random_password() {
    tr -dc 'A-Za-z0-9' </dev/urandom | head -c 16
}

check_existing_install() {
    if [ -f "$WEB_ROOT/storage/installed.lock" ] && [ "$FORCE" -eq 0 ]; then
        log_err "检测到已安装的 MailSystem: $WEB_ROOT"
        log_err "如需重新安装，请添加 --force 参数"
        exit 1
    fi
}

download_source() {
    log_info "下载最新源码..."

    # 检查是否已经在源码目录中（本地运行脚本的情况）
    local SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
    if [ -f "$SCRIPT_DIR/public/index.php" ] && [ -f "$SCRIPT_DIR/bin/install-cli.php" ]; then
        log_info "检测到本地源码目录，直接使用"
        APP_DIR="$SCRIPT_DIR"
        return 0
    fi

    # 创建临时目录
    rm -rf "$INSTALL_DIR"
    mkdir -p "$INSTALL_DIR"

    # 下载
    if command -v curl >/dev/null 2>&1; then
        curl -sSL -o "$INSTALL_DIR/source.tar.gz" "$DOWNLOAD_URL"
    elif command -v wget >/dev/null 2>&1; then
        wget -q -O "$INSTALL_DIR/source.tar.gz" "$DOWNLOAD_URL"
    else
        log_err "请先安装 curl 或 wget"
        exit 1
    fi

    # 解压
    tar -xzf "$INSTALL_DIR/source.tar.gz" -C "$INSTALL_DIR"

    # 找到解压后的目录
    local extracted_dir=$(find "$INSTALL_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    if [ -z "$extracted_dir" ]; then
        log_err "解压失败"
        exit 1
    fi

    APP_DIR="$extracted_dir"
    log_ok "源码已下载并解压"
}

install_deps_centos() {
    log_info "使用 yum/dnf 安装依赖..."
    local PM="yum"
    command -v dnf >/dev/null 2>&1 && PM="dnf"

    $PM install -y epel-release yum-utils 2>/dev/null || true
    $PM install -y nginx mariadb-server mariadb php php-fpm php-cli \
        php-mysqlnd php-pdo php-mbstring php-iconv php-openssl \
        php-curl php-xml php-zip php-sockets php-pcntl 2>/dev/null || true

    # 安装 firewalld (可选)
    $PM install -y firewalld policycoreutils-python-utils 2>/dev/null || true

    # 启动 MariaDB
    systemctl enable --now mariadb 2>/dev/null || systemctl enable --now mysql 2>/dev/null || true

    log_ok "依赖安装完成"
}

install_deps_debian() {
    log_info "使用 apt 安装依赖..."
    export DEBIAN_FRONTEND=noninteractive

    apt-get update -qq
    apt-get install -y --no-install-recommends \
        nginx mariadb-server mariadb-client \
        php php-fpm php-cli php-mysql php-mbstring \
        php-xml php-curl php-zip php-sockets php-pcntl 2>/dev/null || true

    # 安装 ufw (可选)
    apt-get install -y --no-install-recommends ufw 2>/dev/null || true

    # 启动 MariaDB
    systemctl enable --now mariadb 2>/dev/null || true

    log_ok "依赖安装完成"
}

configure_db() {
    log_info "初始化数据库..."

    sleep 2

    # 等待数据库就绪
    local db_ok=0
    for i in 1 2 3 4 5 6 7 8 9 10; do
        if mysqladmin ping -h "$DB_HOST" -P "$DB_PORT" 2>/dev/null; then
            db_ok=1
            break
        fi
        if [ -n "$BT_MYSQL_ROOT_PASS" ]; then
            mysqladmin -uroot -p"$BT_MYSQL_ROOT_PASS" ping -h "$DB_HOST" -P "$DB_PORT" 2>/dev/null && { db_ok=1; break; }
        fi
        sleep 2
    done

    if [ "$db_ok" -eq 0 ]; then
        log_warn "无法连接 MySQL，请确保数据库已启动"
    fi

    # 构造 root 连接参数
    local ROOT_CONN=("-uroot")
    if [ -n "$BT_MYSQL_ROOT_PASS" ]; then
        ROOT_CONN+=("-p$BT_MYSQL_ROOT_PASS")
    fi

    # 尝试创建数据库与用户
    mysql -h "$DB_HOST" -P "$DB_PORT" "${ROOT_CONN[@]}" 2>/dev/null <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

    log_ok "数据库 $DB_NAME 已就绪"
}

deploy_files() {
    log_info "部署文件到 $WEB_ROOT ..."
    mkdir -p "$WEB_ROOT"

    # 优先使用 rsync，退化为 cp -r
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --delete \
            --exclude='.git' \
            --exclude='.env' \
            --exclude='storage/installed.lock' \
            --exclude='storage/cache' \
            --exclude='storage/sessions' \
            --exclude='logs' \
            --exclude='data' \
            "$APP_DIR/" "$WEB_ROOT/"
    else
        cd "$APP_DIR"
        for item in *; do
            [ "$item" = ".git" ] && continue
            [ "$item" = ".env" ] && continue
            [ "$item" = "storage" ] && continue
            [ "$item" = "logs" ] && continue
            [ "$item" = "data" ] && continue
            cp -r "$item" "$WEB_ROOT/" 2>/dev/null || true
        done
        cd - >/dev/null 2>&1
    fi

    # 重建必要目录
    mkdir -p "$WEB_ROOT/storage/cache" "$WEB_ROOT/storage/sessions" \
             "$WEB_ROOT/data/mailboxes" "$WEB_ROOT/logs"

    # 权限设置
    if [ "$IS_BT_ENV" -eq 1 ]; then
        chown -R www:www "$WEB_ROOT" 2>/dev/null || true
    else
        chown -R nginx:nginx "$WEB_ROOT" 2>/dev/null || true
        if [ ! -w "$WEB_ROOT" ] || ! ls -ld "$WEB_ROOT" | grep -q nginx; then
            chown -R www-data:www-data "$WEB_ROOT" 2>/dev/null || true
        fi
    fi

    chmod -R 755 "$WEB_ROOT" 2>/dev/null || true
    chmod -R 770 "$WEB_ROOT/storage" "$WEB_ROOT/data" "$WEB_ROOT/logs" 2>/dev/null || true

    log_ok "文件已部署"
}

write_env() {
    log_info "生成 .env 配置..."
    local APP_KEY=$(random_password | md5sum | cut -c1-32)

    local APP_URL="http://localhost"
    [ -n "$DOMAIN" ] && APP_URL="http://$DOMAIN"

    cat > "$WEB_ROOT/.env" <<EOF
# MailSystem Config
APP_NAME=MailSystem
APP_ENV=production
APP_DEBUG=false
APP_URL=$APP_URL
APP_TIMEZONE=Asia/Shanghai
APP_KEY=$APP_KEY

DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS
DB_CHARSET=utf8mb4

ADMIN_PATH=$ADMIN_PATH
ADMIN_PORT=$ADMIN_PORT
WEB_PORT=80

MAIL_HOSTNAME=$MAIL_HOSTNAME
LOG_PATH=$WEB_ROOT/logs
MAIL_STORAGE_PATH=$WEB_ROOT/data/mailboxes
EOF
    chmod 600 "$WEB_ROOT/.env"
    log_ok ".env 已生成"
}

run_installer() {
    log_info "运行安装程序..."

    local INSTALL_ARGS=(
        --db-host="$DB_HOST" --db-port="$DB_PORT" --db-name="$DB_NAME"
        --db-user="$DB_USER" --db-pass="$DB_PASS"
        --admin-user="$ADMIN_USER" --admin-pass="$ADMIN_PASS" --admin-email="$ADMIN_EMAIL"
        --admin-path="$ADMIN_PATH" --admin-port="$ADMIN_PORT"
        --mail-hostname="$MAIL_HOSTNAME" --app-url="http://localhost"
    )

    [ -n "$BT_MYSQL_ROOT_PASS" ] && INSTALL_ARGS+=("--db-root-pass=$BT_MYSQL_ROOT_PASS")
    [ -n "$DOMAIN" ] && INSTALL_ARGS+=("--default-domain=$DOMAIN")

    "$PHP_BIN" "$WEB_ROOT/bin/install-cli.php" "${INSTALL_ARGS[@]}"
    log_ok "系统安装完成"
}

configure_php_fpm() {
    [ "$IS_BT_ENV" -eq 1 ] && return 0

    log_info "配置 PHP-FPM..."
    local POOL=""

    # 查找配置文件
    for p in /etc/php-fpm.d/www.conf /etc/php/*/fpm/pool.d/www.conf; do
        if [ -f "$p" ]; then
            POOL="$p"
            break
        fi
    done

    if [ -n "$POOL" ]; then
        sed -i "s/^user = .*/user = nginx/" "$POOL" 2>/dev/null || sed -i "s/^user = .*/user = www-data/" "$POOL"
        sed -i "s/^group = .*/group = nginx/" "$POOL" 2>/dev/null || sed -i "s/^group = .*/group = www-data/" "$POOL"
        sed -i "s|^listen = .*|listen = 127.0.0.1:9000|" "$POOL"
    else
        log_warn "未找到 PHP-FPM 配置文件"
    fi

    systemctl enable --now php-fpm 2>/dev/null || true
    log_ok "PHP-FPM 已配置"
}

configure_nginx() {
    [ "$IS_BT_ENV" -eq 1 ] && return 0
    [ "$SKIP_NGINX" -eq 1 ] && return 0

    log_info "配置 Nginx..."
    local NGINX_CONF="/etc/nginx/conf.d/mailsystem.conf"
    if [ -d /etc/nginx/sites-enabled ]; then
        NGINX_CONF="/etc/nginx/sites-available/mailsystem"
    fi

    local SERVER_NAME="$MAIL_HOSTNAME"
    [ "$SERVER_NAME" = "mail.local" ] && SERVER_NAME="_"

    cat > "$NGINX_CONF" <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name $SERVER_NAME;

    root $WEB_ROOT/public;
    index index.php index.html;

    client_max_body_size 50M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /$ADMIN_PATH/ {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|map)\$ {
        expires 30d;
        access_log off;
    }

    location ~ \.php\$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(git|env) { deny all; }
    location ~ /(storage|data|logs)/ { deny all; }
}
EOF

    if [ -d /etc/nginx/sites-enabled ]; then
        ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/mailsystem
        rm -f /etc/nginx/sites-enabled/default
    fi
    [ -f /etc/nginx/conf.d/default.conf ] && rm -f /etc/nginx/conf.d/default.conf

    nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null || true
    log_ok "Nginx 已配置"
}

configure_firewall() {
    [ "$SKIP_FIREWALL" -eq 1 ] && return 0
    [ "$IS_BT_ENV" -eq 1 ] && return 0

    log_info "配置防火墙..."
    if systemctl is-active --quiet firewalld 2>/dev/null; then
        firewall-cmd --permanent --add-service=http 2>/dev/null || true
        firewall-cmd --permanent --add-service=https 2>/dev/null || true
        firewall-cmd --permanent --add-port=25/tcp 2>/dev/null || true
        firewall-cmd --permanent --add-port=465/tcp 2>/dev/null || true
        firewall-cmd --permanent --add-port=587/tcp 2>/dev/null || true
        firewall-cmd --permanent --add-port=110/tcp 2>/dev/null || true
        firewall-cmd --permanent --add-port=995/tcp 2>/dev/null || true
        firewall-cmd --permanent --add-port=143/tcp 2>/dev/null || true
        firewall-cmd --permanent --add-port=993/tcp 2>/dev/null || true
        firewall-cmd --reload 2>/dev/null || true
        log_ok "firewalld 已配置"
    elif command -v ufw >/dev/null; then
        ufw allow http 2>/dev/null || true
        ufw allow https 2>/dev/null || true
        ufw allow 25/tcp 2>/dev/null || true
        ufw allow 465/tcp 2>/dev/null || true
        ufw allow 587/tcp 2>/dev/null || true
        ufw allow 110/tcp 2>/dev/null || true
        ufw allow 995/tcp 2>/dev/null || true
        ufw allow 143/tcp 2>/dev/null || true
        ufw allow 993/tcp 2>/dev/null || true
        ufw --force enable 2>/dev/null || true
        log_ok "ufw 已配置"
    else
        log_warn "未找到防火墙工具，请手动开放 80/25/465/587/110/995/143/993 端口"
    fi
}

configure_service() {
    [ "$SKIP_SERVICE" -eq 1 ] && return 0

    log_info "配置 systemd 服务..."
    local SVC_FILE="/etc/systemd/system/mailsystem.service"
    cat > "$SVC_FILE" <<EOF
[Unit]
Description=MailSystem Mail Services (SMTP/POP3/IMAP)
After=network.target mysql.service mariadb.service php-fpm.service

[Service]
Type=simple
User=root
WorkingDirectory=$WEB_ROOT
ExecStart=$PHP_BIN $WEB_ROOT/bin/services.php start
ExecStop=$PHP_BIN $WEB_ROOT/bin/services.php stop
Restart=always
RestartSec=5
StandardOutput=append:$WEB_ROOT/logs/services.log
StandardError=append:$WEB_ROOT/logs/services.log

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload 2>/dev/null || true
    systemctl enable mailsystem 2>/dev/null || true
    systemctl restart mailsystem 2>/dev/null || true
    log_ok "服务已配置并启动"
}

print_summary() {
    local ACCESS_URL="http://服务器IP"
    [ -n "$DOMAIN" ] && ACCESS_URL="http://$DOMAIN"

cat <<EOF

${GREEN}=================================================================${NC}
${GREEN}        MailSystem 安装完成！${NC}
${GREEN}=================================================================${NC}

  后台地址:    $ACCESS_URL/$ADMIN_PATH/
  默认账号:    $ADMIN_USER
  默认密码:    $ADMIN_PASS

  邮件协议端口:
    SMTP:    25  (明文) / 465 (SSL) / 587 (STARTTLS)
    POP3:    110 (明文) / 995 (SSL)
    IMAP:    143 (STARTTLS) / 993 (SSL)

  数据库信息:
    主机: $DB_HOST:$DB_PORT
    数据库: $DB_NAME
    用户: $DB_USER
    密码: $DB_PASS

  文件目录:
    项目:  $WEB_ROOT
    邮件:  $WEB_ROOT/data/mailboxes
    日志:  $WEB_ROOT/logs
    配置:  $WEB_ROOT/.env

  服务管理:
    systemctl status mailsystem
    systemctl restart mailsystem
    systemctl stop mailsystem

EOF

    if [ "$IS_BT_ENV" -eq 1 ]; then
cat <<EOF
${YELLOW}宝塔面板后续配置:${NC}
  1. 网站 → 选择站点 → 设置 → 网站目录 → 运行目录选择 /public
  2. 网站 → 选择站点 → 设置 → 伪静态 → 选择 Laravel5 或添加:
       location / { try_files \$uri \$uri/ /index.php?\$query_string; }
  3. 安全 → 放行邮件端口: 25, 465, 587, 110, 995, 143, 993

EOF
    fi

cat <<EOF
${YELLOW}提示: 邮箱域名需要在 DNS 服务商添加 MX / A / TXT 记录才能外网收发${NC}
${YELLOW}     后台 → 域名管理 → 点击 DNS 按钮查看需要添加的记录${NC}

${GREEN}=================================================================${NC}

EOF
}

cleanup() {
    if [ -d "$INSTALL_DIR" ] && [ "$APP_DIR" != "$INSTALL_DIR" ]; then
        rm -rf "$INSTALL_DIR"
    fi
}

# ============================================
# 主流程
# ============================================
main() {
    print_banner
    parse_args "$@"
    check_root
    detect_os
    detect_bt_panel
    check_existing_install

    # 设置默认密码
    [ -z "$DB_PASS" ] && DB_PASS=$(random_password)
    [ -z "$ADMIN_PASS" ] && ADMIN_PASS=$(random_password)
    [ -z "$ADMIN_EMAIL" ] && ADMIN_EMAIL="admin@$(hostname -d 2>/dev/null || echo localhost)"
    [ -z "$DOMAIN" ] && [ "$MAIL_HOSTNAME" != "mail.local" ] && DOMAIN="$MAIL_HOSTNAME"

    # 下载源码
    download_source

    # 安装依赖
    if [ "$SKIP_DEPS" -eq 0 ]; then
        case "$OS_LIKE" in
            *rhel*|*centos*|*fedora*) install_deps_centos ;;
            *debian*|*ubuntu*)       install_deps_debian ;;
            *) log_warn "不支持自动安装依赖的系统: $OS_LIKE，请手动安装依赖后使用 --skip-deps" ;;
        esac
    fi

    # 部署文件
    deploy_files

    # 初始化数据库
    if [ "$SKIP_DB" -eq 0 ]; then
        configure_db
    fi

    # 写入 .env
    write_env

    # 运行安装
    run_installer

    # 配置 PHP-FPM
    configure_php_fpm

    # 配置 Nginx
    configure_nginx

    # 配置防火墙
    configure_firewall

    # 启动服务
    configure_service

    # 清理
    cleanup

    # 输出摘要
    print_summary
}

trap cleanup EXIT

main "$@"
