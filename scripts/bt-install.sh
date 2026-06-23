#!/usr/bin/env bash
# ============================================
# MailSystem 宝塔面板专用安装脚本
# 适用于已安装宝塔面板 (BT-Panel) 的服务器
#
# 用法:
#   cd /opt/mail-system
#   sudo bash scripts/bt-install.sh [选项]
#
# 选项:
#   --web-root=DIR       网站目录 (默认: /www/wwwroot/mailsystem)
#   --db-name=NAME       数据库名 (默认: mail_system)
#   --db-user=USER       数据库用户 (默认: mail_user)
#   --db-pass=PASS       数据库业务用户密码（宝塔面板创建数据库时设置）
#   --db-root-pass=PASS  MySQL root 密码（如能自动获取则免填）
#   --admin-user=USER    管理员用户名 (默认: admin)
#   --admin-pass=PASS    管理员密码 (默认: 随机生成)
#   --admin-email=EMAIL  管理员邮箱
#   --admin-path=PATH    后台访问路径 (默认: admin)
#   --mail-hostname=HOST 邮件主机名 (默认: mail.local)
#   --help               显示帮助
# ============================================

set -e
umask 022

# 颜色
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info()  { echo -e "${BLUE}[INFO]${NC} $*"; }
log_ok()    { echo -e "${GREEN}[ OK ]${NC} $*"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_err()   { echo -e "${RED}[FAIL]${NC} $*"; }

print_banner() {
cat <<'EOF'
 __  __          _ _           ____            _       _
|  \/  | ___  __| (_) ___ ___ / ___|  ___ _ __(_)_   _| |_
| |\/| |/ _ \/ _` | |/ __/ _ \\___ \ / _ \ '__| | | | | __|
| |  | |  __/ (_| | | (_|  __/ ___) |  __/ |  | | |_| | |_
|_|  |_|\___|\__,_|_|\___\___||____/ \___|_|  |_|\__,_|\__|

        Self-hosted Mail System v1.1.0 - 宝塔面板安装向导
EOF
}

# ============ 1. 环境检测 ============
BT_PANEL="/www/server/panel"
BT_WEB_ROOT="/www/wwwroot"
BT_MYSQL_PATH=""
PHP_VER=""
PHP_PATH=""
BT_ROOT_PASS=""

check_bt_env() {
    if [ ! -d "$BT_PANEL" ]; then
        log_err "未检测到宝塔面板"
        echo
        echo "请先安装宝塔:"
        echo "  yum install -y wget && wget -O install.sh \\"
        echo "    http://download.bt.cn/install/install_6.0.sh && sh install.sh"
        exit 1
    fi
    log_ok "宝塔面板已安装"
}

find_php() {
    for ver in 83 82 81 80 74; do
        if [ -f "/www/server/php/$ver/bin/php" ]; then
            PHP_VER="$ver"
            PHP_PATH="/www/server/php/$ver"
            log_ok "PHP 版本: $ver"
            return 0
        fi
    done
    log_err "未找到 PHP，请在宝塔面板 [软件商店] 安装 PHP 7.4+"
    exit 1
}

ensure_mysql() {
    for dir in /www/server/mysql /www/server/mariadb; do
        if [ -d "$dir/bin" ]; then
            BT_MYSQL_PATH="$dir"
            log_ok "MySQL 已安装 ($dir)"
            break
        fi
    done
    if [ -z "$BT_MYSQL_PATH" ]; then
        log_err "未找到 MySQL，请在宝塔面板 [软件商店] 安装 MySQL 5.7+ 或 MariaDB 10.x"
        exit 1
    fi

    # 启动 MySQL（如未运行）
    if ! "$BT_MYSQL_PATH/bin/mysqladmin" ping --silent 2>/dev/null; then
        log_info "MySQL 未启动，正在启动..."
        /etc/init.d/mysqld start 2>/dev/null || true
        sleep 2
        if ! "$BT_MYSQL_PATH/bin/mysqladmin" ping --silent 2>/dev/null; then
            # 手动启动
            if command -v systemctl >/dev/null 2>&1; then
                systemctl start mysqld 2>/dev/null || true
            fi
            # 再等一次
            sleep 2
        fi
        if "$BT_MYSQL_PATH/bin/mysqladmin" ping --silent 2>/dev/null; then
            log_ok "MySQL 已启动"
        else
            log_err "MySQL 启动失败，请在宝塔面板 [软件商店] → MySQL → 设置 → 启动服务"
            exit 1
        fi
    else
        log_ok "MySQL 运行中"
    fi
}

get_root_password() {
    local found=""
    # 方案 1: 宝塔 SQLite 配置数据库
    if command -v sqlite3 >/dev/null 2>&1; then
        for db in "$BT_PANEL/data/default.db" "$BT_PANEL/data/default.pl"; do
            if [ -f "$db" ]; then
                found=$(sqlite3 "$db" "SELECT value FROM config WHERE config_key='mysql_root' LIMIT 1;" 2>/dev/null || true)
                [ -n "$found" ] && break
            fi
        done
    fi
    # 方案 2: 宝塔纯文本配置文件
    if [ -z "$found" ] && [ -f "$BT_PANEL/data/default.pl" ]; then
        found=$(grep -oP 'mysql_root\s*[=:]\s*\K[^,\s]+' "$BT_PANEL/data/default.pl" 2>/dev/null | head -1 | tr -d '"'"'"' ' || true)
    fi
    # 方案 3: /root/.my.cnf
    if [ -z "$found" ] && [ -f /root/.my.cnf ]; then
        found=$(grep -oP 'password\s*=\s*\K.+' /root/.my.cnf 2>/dev/null | head -1 | tr -d '"'"'"' ' || true)
    fi
    # 方案 4: /etc/my.cnf client 段
    if [ -z "$found" ] && [ -f /etc/my.cnf ]; then
        found=$(grep -oP '^password\s*=\s*\K.+' /etc/my.cnf 2>/dev/null | head -1 | tr -d '"'"'"' ' || true)
    fi
    BT_ROOT_PASS="$found"
    if [ -n "$BT_ROOT_PASS" ]; then
        log_ok "已自动获取 MySQL root 密码"
    else
        log_warn "未能自动获取 MySQL root 密码，将使用业务用户权限"
    fi
}

# ============ 2. 参数解析 ============
WEB_ROOT=""
DB_NAME="mail_system"
DB_USER="mail_user"
DB_PASS=""
DB_ROOT_PASS=""
ADMIN_USER="admin"
ADMIN_PASS=""
ADMIN_EMAIL=""
ADMIN_PATH="admin"
MAIL_HOSTNAME=""

for arg in "$@"; do
    case $arg in
        --web-root=*)      WEB_ROOT="${arg#*=}" ;;
        --db-name=*)       DB_NAME="${arg#*=}" ;;
        --db-user=*)       DB_USER="${arg#*=}" ;;
        --db-pass=*)       DB_PASS="${arg#*=}" ;;
        --db-root-pass=*)  DB_ROOT_PASS="${arg#*=}" ;;
        --admin-user=*)    ADMIN_USER="${arg#*=}" ;;
        --admin-pass=*)    ADMIN_PASS="${arg#*=}" ;;
        --admin-email=*)   ADMIN_EMAIL="${arg#*=}" ;;
        --admin-path=*)    ADMIN_PATH="${arg#*=}" ;;
        --mail-hostname=*) MAIL_HOSTNAME="${arg#*=}" ;;
        --help|-h)         echo "用法: $0 [选项]，脚本顶部有完整说明"; exit 0 ;;
        *)                 log_warn "未知参数: $arg" ;;
    esac
done

# ============ 3. 主流程 ============
main() {
    print_banner
    check_bt_env
    find_php
    ensure_mysql
    get_root_password

    # 默认目录
    [ -z "$WEB_ROOT" ] && WEB_ROOT="$BT_WEB_ROOT/mailsystem"

    # 检查源码
    SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
    APP_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"
    if [ ! -f "$APP_DIR/public/index.php" ]; then
        log_err "请在源码根目录执行: cd /opt/mail-system && sudo bash scripts/bt-install.sh"
        exit 1
    fi

    # 安装信息
    echo
    echo "============================================================"
    echo "  部署信息"
    echo "  ----------------------------------------------------------"
    echo "  网站目录:   $WEB_ROOT"
    echo "  PHP 版本:   $PHP_VER ($PHP_PATH/bin/php)"
    echo "  MySQL:      $BT_MYSQL_PATH"
    echo "  数据库:     $DB_USER @ $DB_NAME"
    echo "============================================================"
    echo

    # 提示在宝塔创建数据库
    echo "请在宝塔面板 [数据库] → [添加数据库] 创建如下信息:"
    echo "  数据库名: $DB_NAME"
    echo "  用户名:   $DB_USER"
    echo "  密码:     <自己设置，然后在此粘贴>"
    echo

    if [ -z "$DB_PASS" ]; then
        read -p "请输入数据库密码 ($DB_USER): " input_pass
        [ -z "$input_pass" ] && { log_err "密码不能为空"; exit 1; }
        DB_PASS="$input_pass"
    fi

    # 用业务用户验证数据库连接（可选，失败不退出）
    if "$BT_MYSQL_PATH/bin/mysql" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1;" >/dev/null 2>&1; then
        log_ok "数据库连接验证成功"
    else
        log_warn "数据库连接验证失败，仍将继续（密码可能不正确，请在宝塔面板检查）"
    fi

    # 优先使用自动获取的 root 密码
    [ -n "$BT_ROOT_PASS" ] && [ -z "$DB_ROOT_PASS" ] && DB_ROOT_PASS="$BT_ROOT_PASS"

    echo
    read -p "按 Enter 开始部署，Ctrl+C 取消... " _
    echo

    # ============ 4. 部署文件 ============
    log_info "部署文件到 $WEB_ROOT ..."
    mkdir -p "$WEB_ROOT"

    # 复制源码（排除 git / .env / 已生成的 storage）
    cd "$APP_DIR"
    for item in *; do
        [ -d "$item" ] && [ "$item" = ".git" ] && continue
        [ -f "$item" ] && [ "$item" = ".env" ] && continue
        # storage 目录只保留骨架
        if [ -d "$item" ] && [ "$item" = "storage" ]; then
            mkdir -p "$WEB_ROOT/storage/cache" "$WEB_ROOT/storage/sessions"
            continue
        fi
        cp -r "$item" "$WEB_ROOT/" 2>/dev/null || true
    done
    cd - >/dev/null 2>&1

    # 建立其他必要目录
    mkdir -p "$WEB_ROOT/data/mailboxes" "$WEB_ROOT/logs"

    # 权限设置（跳过宝塔锁定的 .user.ini）
    # 对绝大多数子目录/文件设置 www:www
    if command -v find >/dev/null 2>&1; then
        # 顶层目录（不递归）先改所有者
        for sub in app bin config database public install scripts logs data storage; do
            [ -e "$WEB_ROOT/$sub" ] && chown -R www:www "$WEB_ROOT/$sub" 2>/dev/null || true
        done
    fi
    chmod -R 755 "$WEB_ROOT" 2>/dev/null || true
    # storage / logs / data 需要可写
    for w in storage logs data; do
        [ -d "$WEB_ROOT/$w" ] && chown -R www:www "$WEB_ROOT/$w" && chmod -R 770 "$WEB_ROOT/$w"
    done
    log_ok "文件已部署"

    # ============ 5. 生成 .env ============
    log_info "生成配置文件..."
    APP_KEY=$(tr -dc 'A-Za-z0-9%&*()_+-=' </dev/urandom | head -c 32)
    [ -z "$MAIL_HOSTNAME" ] && MAIL_HOSTNAME="mail.local"

    cat > "$WEB_ROOT/.env" <<ENVEOF
APP_KEY=$APP_KEY
APP_NAME=MailSystem
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Shanghai
APP_URL=http://localhost

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS
DB_CHARSET=utf8mb4

ADMIN_PATH=$ADMIN_PATH
ADMIN_PORT=8080

MAIL_HOSTNAME=$MAIL_HOSTNAME
ENVEOF
    chmod 600 "$WEB_ROOT/.env"
    log_ok ".env 已写入"

    # ============ 6. 执行 PHP 安装程序 ============
    log_info "初始化数据库 schema 与数据..."
    cd "$WEB_ROOT"

    INSTALL_ARGS=()
    [ -n "$DB_ROOT_PASS" ] && INSTALL_ARGS+=("--db-root-pass=$DB_ROOT_PASS")
    INSTALL_ARGS+=("--db-name=$DB_NAME" "--db-user=$DB_USER" "--db-pass=$DB_PASS")
    [ -n "$ADMIN_USER" ]  && INSTALL_ARGS+=("--admin-user=$ADMIN_USER")
    [ -n "$ADMIN_PASS" ]  && INSTALL_ARGS+=("--admin-pass=$ADMIN_PASS")
    [ -n "$ADMIN_EMAIL" ] && INSTALL_ARGS+=("--admin-email=$ADMIN_EMAIL")
    [ -n "$ADMIN_PATH" ]  && INSTALL_ARGS+=("--admin-path=$ADMIN_PATH")
    [ -n "$MAIL_HOSTNAME" ] && INSTALL_ARGS+=("--mail-hostname=$MAIL_HOSTNAME")
    INSTALL_ARGS+=("--default-domain=${MAIL_HOSTNAME#mail.}")

    set +e
    $PHP_PATH/bin/php bin/install-cli.php "${INSTALL_ARGS[@]}"
    local rc=$?
    set -e
    if [ $rc -ne 0 ]; then
        log_err "安装程序执行失败 (exit $rc)"
        echo "常见原因:"
        echo "  1. 数据库密码错误（请在宝塔面板数据库列表查看）"
        echo "  2. 数据库未创建（请先在宝塔面板创建）"
        echo "  3. MySQL 未启动（宝塔面板 → 软件商店 → MySQL → 启动）"
        exit $rc
    fi
    log_ok "数据库初始化完成"

    # ============ 7. 完成提示 ============
    echo
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  安装成功！${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo
    echo "请在宝塔面板完成以下三步配置:"
    echo
    echo "  ① 网站 → 选择站点 → 设置 → 网站目录"
    echo "     运行目录  选择: /public"
    echo
    echo "  ② 网站 → 选择站点 → 设置 → 伪静态"
    echo "     下拉选择 [Laravel5]，或手动粘贴:"
    echo "     location / { try_files \$uri \$uri/ /index.php?\$query_string; }"
    echo
    echo "  ③ 宝塔面板 → 安全 → 放行端口 (TCP):"
    echo "     25 (SMTP) / 465 (SMTPS) / 587 (SMTP STARTTLS)"
    echo "     110 (POP3) / 995 (POP3 SSL)"
    echo "     143 (IMAP) / 993 (IMAP SSL)"
    echo
    echo "  ④ 启动邮件服务 (SSH 执行):"
    echo "     cd $WEB_ROOT && bash scripts/service.sh start"
    echo
    echo "============================================================"
    echo "  后台访问: http://你的域名或IP/$ADMIN_PATH/"
    echo "  管理员:   $ADMIN_USER / ${ADMIN_PASS:-(随机生成，请查看上方输出)}"
    echo "============================================================"
    echo
    echo -e "${YELLOW}安全提示:${NC}"
    echo "  · 登录后请立即修改默认管理员密码"
    echo "  · 正式使用前请在宝塔 [网站设置] 启用 HTTPS/SSL"
    echo "  · 邮件端口需要在云服务商的安全组也放行（阿里云/腾讯云/华为云等）"
    echo
}

main "$@"
