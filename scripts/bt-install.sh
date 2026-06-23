#!/usr/bin/env bash
# ============================================
# MailSystem 宝塔面板专用安装脚本
# 适用于已安装宝塔面板的服务器
# ============================================

set -e

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

        Self-hosted Mail System - 宝塔面板安装
EOF
}

# 检测宝塔环境
BT_PANEL="/www/server/panel"
BT_WEB_ROOT="/www/wwwroot"

check_bt_env() {
    if [ ! -d "$BT_PANEL" ]; then
        log_err "未检测到宝塔面板，请先安装宝塔"
        log_info "宝塔安装命令: yum install -y wget && wget -O install.sh http://download.bt.cn/install/install_6.0.sh && sh install.sh"
        exit 1
    fi
    log_ok "宝塔面板已安装"
}

# 查找 PHP 版本
find_php() {
    for ver in 82 81 80 74 73; do
        if [ -d "/www/server/php/$ver" ]; then
            PHP_VER="$ver"
            PHP_PATH="/www/server/php/$ver"
            log_ok "PHP 版本: $ver"
            return 0
        fi
    done
    log_err "未找到 PHP，请在宝塔面板安装 PHP 7.4+"
    exit 1
}

# 查找 MySQL
check_mysql() {
    if [ ! -d "/www/server/mysql" ]; then
        log_err "未找到 MySQL，请在宝塔面板安装 MySQL/MariaDB"
        exit 1
    fi
    log_ok "MySQL 已安装"
}

# 参数
WEB_ROOT=""
DB_NAME="mail_system"
DB_USER="mail_user"
DB_PASS=""
ADMIN_USER="admin"
ADMIN_PASS=""
ADMIN_EMAIL=""
ADMIN_PATH="admin"
MAIL_HOSTNAME=""

usage() {
cat <<EOF
用法: $0 [选项]

选项:
  --web-root=DIR       网站目录 (默认: /www/wwwroot/mailsystem)
  --db-name=NAME       数据库名 (默认: mail_system)
  --db-user=USER       数据库用户 (默认: mail_user)
  --db-pass=PASS       数据库密码 (必须指定或在宝塔创建)
  --admin-user=USER    管理员用户名 (默认: admin)
  --admin-pass=PASS    管理员密码 (随机生成)
  --admin-email=EMAIL  管理员邮箱
  --admin-path=PATH    后台路径 (默认: admin)
  --mail-hostname=HOST 邮件主机名 (默认: mail.域名)
  --help               显示帮助

示例:
  # 交互式安装
  sudo bash bt-install.sh

  # 指定参数
  sudo bash bt-install.sh --db-pass=YourDbPass --mail-hostname=mail.example.com
EOF
}

# 解析参数
for arg in "$@"; do
    case $arg in
        --web-root=*)     WEB_ROOT="${arg#*=}" ;;
        --db-name=*)      DB_NAME="${arg#*=}" ;;
        --db-user=*)      DB_USER="${arg#*=}" ;;
        --db-pass=*)      DB_PASS="${arg#*=}" ;;
        --admin-user=*)   ADMIN_USER="${arg#*=}" ;;
        --admin-pass=*)   ADMIN_PASS="${arg#*=}" ;;
        --admin-email=*)  ADMIN_EMAIL="${arg#*=}" ;;
        --admin-path=*)   ADMIN_PATH="${arg#*=}" ;;
        --mail-hostname=*) MAIL_HOSTNAME="${arg#*=}" ;;
        --help|-h)        usage; exit 0 ;;
        *)                log_warn "未知参数: $arg" ;;
    esac
done

# 主流程
main() {
    print_banner
    check_bt_env
    find_php
    check_mysql

    # 默认目录
    [ -z "$WEB_ROOT" ] && WEB_ROOT="$BT_WEB_ROOT/mailsystem"

    # 检查源码
    SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
    if [ ! -f "$SCRIPT_DIR/../public/index.php" ]; then
        log_err "请在源码目录执行: cd /opt/mail-system && sudo bash scripts/bt-install.sh"
        exit 1
    fi
    APP_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"

    # 提示用户在宝塔创建站点和数据库
    echo
    echo -e "${YELLOW}========================================${NC}"
    echo -e "${YELLOW}请在宝塔面板完成以下操作：${NC}"
    echo
    echo "1. 网站 → 添加站点"
    echo "   - 填写域名（如 mail.yourdomain.com）"
    echo "   - 根目录: $WEB_ROOT"
    echo "   - PHP 版本: ${PHP_VER}"
    echo
    echo "2. 创建数据库"
    echo "   - 名称: $DB_NAME"
    echo "   - 用户: $DB_USER"
    echo "   - 记录密码"
    echo
    echo "3. 完成后，输入数据库密码继续安装"
    echo -e "${YELLOW}========================================${NC}"
    echo

    # 获取数据库密码
    if [ -z "$DB_PASS" ]; then
        read -p "请输入数据库密码: " DB_PASS
        if [ -z "$DB_PASS" ]; then
            log_err "数据库密码不能为空"
            exit 1
        fi
    fi

    # 生成其他默认值
    [ -z "$ADMIN_PASS" ] && ADMIN_PASS=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 16)
    [ -z "$ADMIN_EMAIL" ] && ADMIN_EMAIL="admin@${MAIL_HOSTNAME#mail.}"

    echo
    echo "安装配置："
    echo "  网站目录:   $WEB_ROOT"
    echo "  数据库:     $DB_USER@$DB_NAME"
    echo "  管理员:     $ADMIN_USER / $ADMIN_PASS"
    echo "  后台路径:   /$ADMIN_PATH/"
    echo "  邮件主机名: ${MAIL_HOSTNAME:-请在安装后配置}"
    echo
    read -p "按 Enter 继续..."

    # 部署文件
    log_info "部署文件到 $WEB_ROOT ..."
    mkdir -p "$WEB_ROOT"
    cd "$APP_DIR"
    for item in *; do
        [ "$item" = ".git" ] && continue
        [ "$item" = ".env" ] && continue
        cp -r "$item" "$WEB_ROOT/"
    done
    cd - >/dev/null

    # 清理
    rm -rf "$WEB_ROOT/storage/installed.lock" 2>/dev/null || true
    mkdir -p "$WEB_ROOT/storage/cache" "$WEB_ROOT/storage/sessions" \
             "$WEB_ROOT/data/mailboxes" "$WEB_ROOT/logs"

    # 权限
    chown -R www:www "$WEB_ROOT"
    chmod -R 755 "$WEB_ROOT"
    chmod -R 770 "$WEB_ROOT/storage" "$WEB_ROOT/data" "$WEB_ROOT/logs"
    log_ok "文件已部署"

    # 写入 .env
    log_info "生成配置文件..."
    APP_KEY=$(tr -dc 'A-Za-z0-9%&*()_+-=' </dev/urandom | head -c 32)
    [ -z "$MAIL_HOSTNAME" ] && MAIL_HOSTNAME="mail.local"

    cat > "$WEB_ROOT/.env" <<EOF
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=http://localhost

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS

ADMIN_PATH=$ADMIN_PATH
ADMIN_PORT=8080

MAIL_HOSTNAME=$MAIL_HOSTNAME
EOF
    log_ok ".env 已生成"

    # 运行安装程序
    log_info "初始化数据库..."
    cd "$WEB_ROOT"
    "$PHP_PATH/bin/php" bin/install-cli.php \
        --admin-user="$ADMIN_USER" \
        --admin-pass="$ADMIN_PASS" \
        --admin-email="$ADMIN_EMAIL" \
        --default-domain="${MAIL_HOSTNAME#mail.}" 2>&1 || {
        log_warn "安装程序执行失败，请检查数据库连接"
    }
    log_ok "数据库已初始化"

    # 完成提示
    echo
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}安装完成！${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo
    echo "后续步骤："
    echo
    echo "1. 宝塔面板 → 网站 → 选择站点 → 设置 → 网站目录"
    echo "   运行目录设置为: /public"
    echo
    echo "2. 宝塔面板 → 网站 → 选择站点 → 设置 → 伪静态"
    echo "   选择 laravel 或添加："
    echo "   location / { try_files \$uri \$uri/ /index.php?\$query_string; }"
    echo
    echo "3. 宝塔面板 → 安全 → 放行端口："
    echo "   25, 465, 587, 110, 995, 143, 993"
    echo
    echo "4. 访问后台："
    echo "   http://你的域名/$ADMIN_PATH"
    echo "   用户: $ADMIN_USER"
    echo "   密码: $ADMIN_PASS"
    echo
    echo "5. 启动邮件服务："
    echo "   cd $WEB_ROOT && sudo bash scripts/service.sh start"
    echo
}

main "$@"