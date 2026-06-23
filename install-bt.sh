#!/bin/bash
# ==============================================================================
# MailSystem - 宝塔面板一键安装脚本
# 适用环境：CentOS / Debian / Ubuntu + 宝塔面板（BT Panel）
# 功能：
#   1. 自动识别宝塔 PHP 版本（82 / 81 / 80 / 74 / 73）
#   2. 自动读取宝塔 MySQL root 密码并创建数据库
#   3. 部署代码到 /www/wwwroot/mailsystem
#   4. 避开宝塔 .user.ini 文件锁定
#   5. 写入正确格式的 .env（DB_USERNAME / DB_PASSWORD）
#   6. 导入数据库 schema + 创建管理员账号
#   7. 启动所有邮件服务端口
# 使用方法：
#   方式一：bash install-bt.sh
#   方式二：bash install-bt.sh --domain=mail.yourdomain.com
#   方式三：bash install-bt.sh --domain=mail.yourdomain.com --admin-pass=yourpass
# ==============================================================================

set -e

# --- 参数解析 ---
INSTALL_DOMAIN="124.222.43.74"
INSTALL_ADMIN_PASS="admin123456"
INSTALL_ADMIN_USER="admin"
INSTALL_ADMIN_EMAIL="admin@localhost"
INSTALL_DB_NAME="mail_system"
INSTALL_DB_USER="mail_user"
INSTALL_DB_PASS="wYJRKZJywbn6CNpB"

for arg in "$@"; do
  case "$arg" in
    --domain=*)         INSTALL_DOMAIN="${arg#*=}" ;;
    --admin-user=*)     INSTALL_ADMIN_USER="${arg#*=}" ;;
    --admin-pass=*)     INSTALL_ADMIN_PASS="${arg#*=}" ;;
    --admin-email=*)    INSTALL_ADMIN_EMAIL="${arg#*=}" ;;
    --db-name=*)        INSTALL_DB_NAME="${arg#*=}" ;;
    --db-user=*)        INSTALL_DB_USER="${arg#*=}" ;;
    --db-pass=*)        INSTALL_DB_PASS="${arg#*=}" ;;
    -h|--help)
      echo "用法: bash install-bt.sh [选项]"
      echo ""
      echo "  --domain=域名         站点域名 (默认 $INSTALL_DOMAIN)"
      echo "  --admin-user=用户名   管理员用户名 (默认 $INSTALL_ADMIN_USER)"
      echo "  --admin-pass=密码     管理员密码 (默认 $INSTALL_ADMIN_PASS)"
      echo "  --admin-email=邮箱    管理员邮箱 (默认 $INSTALL_ADMIN_EMAIL)"
      echo "  --db-name=库名        数据库名 (默认 $INSTALL_DB_NAME)"
      echo "  --db-user=用户名      数据库用户名 (默认 $INSTALL_DB_USER)"
      echo "  --db-pass=密码        数据库密码 (默认 $INSTALL_DB_PASS)"
      echo ""
      exit 0
      ;;
  esac
done

SCRIPT_DIR="$( cd "$( dirname "$0" )" && pwd )"
APP_DIR="$( cd "$SCRIPT_DIR" && pwd )"
WEB_DIR="/www/wwwroot/mailsystem"
BT_PANEL_PATH="/www/server/panel"

echo ""
echo "========================================"
echo "  MailSystem - 宝塔环境一键安装"
echo "========================================"
echo "  站点目录：$WEB_DIR"
echo "  访问域名：$INSTALL_DOMAIN"
echo "  管理员  ：$INSTALL_ADMIN_USER / $INSTALL_ADMIN_PASS"
echo "========================================"
echo ""

# --- 1. 清理旧的源码下载（仅清理临时文件） ---
echo "[1/9] 准备安装环境..."
cd "$APP_DIR"

# --- 2. 检测宝塔环境 ---
echo "[2/9] 检测宝塔环境..."
if [ ! -d "$BT_PANEL_PATH" ]; then
  echo "  警告：未检测到宝塔面板，仍尝试继续安装"
fi

# 自动识别宝塔 PHP 版本
BT_PHP=""
for ver in 82 81 80 74 73; do
  if [ -f "/www/server/php/$ver/bin/php" ]; then
    BT_PHP="/www/server/php/$ver/bin/php"
    echo "  找到宝塔 PHP $ver ：$BT_PHP"
    break
  fi
done
if [ -z "$BT_PHP" ]; then
  echo "  警告：未在宝塔目录找到 PHP，尝试使用系统 php"
  BT_PHP=$( which php 2>/dev/null || echo "php" )
fi

# 检测 MySQL
BT_MYSQL=""
if [ -f "/www/server/mysql/bin/mysql" ]; then
  BT_MYSQL="/www/server/mysql/bin/mysql"
else
  BT_MYSQL=$( which mysql 2>/dev/null || echo "mysql" )
fi
echo "  MySQL 路径：$BT_MYSQL"

# 自动读取宝塔 MySQL root 密码
BT_ROOT_PASS=""
for f in "$BT_PANEL_PATH/data/default.pl" "$BT_PANEL_PATH/default.pl"; do
  if [ -f "$f" ]; then
    val=$( grep -oE "mysql_root.?=.?[^ ]+" "$f" 2>/dev/null | head -1 | cut -d= -f2 )
    if [ -n "$val" ]; then
      BT_ROOT_PASS="$val"
      break
    fi
  fi
done
if [ -n "$BT_ROOT_PASS" ]; then
  echo "  已读取宝塔 MySQL root 密码"
else
  echo "  未读取到宝塔 MySQL root 密码（仍尝试使用业务账号）"
fi

# --- 3. 部署代码 ---
echo "[3/9] 部署代码到 $WEB_DIR ..."
mkdir -p "$WEB_DIR/storage/cache" "$WEB_DIR/storage/sessions" "$WEB_DIR/data" "$WEB_DIR/logs"

# 复制核心代码目录（避开宝塔锁定文件）
for d in app bin config database public install; do
  if [ -d "$APP_DIR/$d" ]; then
    rm -rf "$WEB_DIR/$d" 2>/dev/null
    cp -r "$APP_DIR/$d" "$WEB_DIR/"
  fi
done
# 复制根目录文件
for f in README.md CHANGELOG.md LICENSE; do
  [ -f "$APP_DIR/$f" ] && cp -f "$APP_DIR/$f" "$WEB_DIR/" 2>/dev/null
done
echo "  代码部署完成"

# --- 4. 设置权限（避开 .user.ini 锁定文件） ---
echo "[4/9] 设置文件权限..."
for d in app bin config database public install; do
  [ -d "$WEB_DIR/$d" ] && chown -R www:www "$WEB_DIR/$d" 2>/dev/null
done
chown -R www:www "$WEB_DIR/storage" "$WEB_DIR/data" "$WEB_DIR/logs" 2>/dev/null
chmod -R 755 "$WEB_DIR/app" "$WEB_DIR/bin" "$WEB_DIR/config" "$WEB_DIR/database" "$WEB_DIR/public" "$WEB_DIR/install" 2>/dev/null
chmod -R 770 "$WEB_DIR/storage" "$WEB_DIR/data" "$WEB_DIR/logs" 2>/dev/null
chmod +x "$WEB_DIR/bin"/*.php 2>/dev/null

# --- 5. 写入正确的 .env ---
echo "[5/9] 写入配置文件 .env ..."
APP_KEY_RAND=$( head -c 32 /dev/urandom | base64 | tr -d '+/=' | head -c 32 )
cat > "$WEB_DIR/.env" << DOTENV
APP_NAME=MailSystem
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Shanghai
APP_URL=http://${INSTALL_DOMAIN}
APP_KEY=${APP_KEY_RAND}

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${INSTALL_DB_NAME}
DB_USERNAME=${INSTALL_DB_USER}
DB_PASSWORD=${INSTALL_DB_PASS}
DB_CHARSET=utf8mb4

ADMIN_PATH=admin
ADMIN_PORT=8080
WEB_PORT=80

MAIL_HOSTNAME=${INSTALL_DOMAIN}
LOG_PATH=${WEB_DIR}/logs
DOTENV
chown www:www "$WEB_DIR/.env" 2>/dev/null || true
chmod 600 "$WEB_DIR/.env"
echo "  .env 已写入"

# --- 6. 创建数据库与业务账户 ---
echo "[6/9] 准备数据库..."
if [ -n "$BT_ROOT_PASS" ]; then
  $BT_MYSQL -uroot -p"$BT_ROOT_PASS" -e \
    "CREATE DATABASE IF NOT EXISTS ${INSTALL_DB_NAME} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true
  $BT_MYSQL -uroot -p"$BT_ROOT_PASS" -e \
    "CREATE USER IF NOT EXISTS ${INSTALL_DB_USER}@127.0.0.1 IDENTIFIED BY '${INSTALL_DB_PASS}';" 2>/dev/null || true
  $BT_MYSQL -uroot -p"$BT_ROOT_PASS" -e \
    "GRANT ALL PRIVILEGES ON ${INSTALL_DB_NAME}.* TO ${INSTALL_DB_USER}@127.0.0.1; FLUSH PRIVILEGES;" 2>/dev/null || true
  echo "  数据库创建完成（root 方式）"
else
  echo "  跳过数据库自动创建，使用已存在的业务账号"
fi

# --- 7. 导入 schema ---
echo "[7/9] 导入数据库 schema..."
$BT_MYSQL -u"$INSTALL_DB_USER" -p"$INSTALL_DB_PASS" "$INSTALL_DB_NAME" < "$WEB_DIR/database/schema.sql"
echo "  schema 导入完成"

# --- 8. 创建管理员 ---
echo "[8/9] 初始化管理员账号..."
cd "$WEB_DIR"
rm -f storage/installed.lock

$BT_PHP bin/install-cli.php \
    --db-host=127.0.0.1 --db-port=3306 --db-name="$INSTALL_DB_NAME" \
    --db-user="$INSTALL_DB_USER" --db-pass="$INSTALL_DB_PASS" \
    --admin-user="$INSTALL_ADMIN_USER" --admin-pass="$INSTALL_ADMIN_PASS" \
    --admin-email="$INSTALL_ADMIN_EMAIL" --admin-path=admin \
    --mail-hostname="$INSTALL_DOMAIN"

# --- 9. 启动邮件服务 ---
echo ""
echo "[9/9] 启动邮件服务..."
$BT_PHP bin/services.php stop 2>/dev/null || true
$BT_PHP bin/services.php start
sleep 2

# --- 验证与汇总 ---
echo ""
echo "========================================"
echo "  安装完成 - 状态验证"
echo "========================================"
echo ""
echo "--- 邮件服务端口监听 ---"
netstat -tlnp 2>/dev/null | grep -E ":25|:465|:587|:110|:995|:143|:993" || \
  ss -tlnp 2>/dev/null | grep -E ":25|:465|:587|:110|:995|:143|:993"

echo ""
echo "--- API 登录接口测试 ---"
curl -s -X POST "http://${INSTALL_DOMAIN}/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"${INSTALL_ADMIN_USER}\",\"password\":\"${INSTALL_ADMIN_PASS}\"}"
echo ""

echo ""
echo "========================================"
echo "  ✅ 安装完成！"
echo "  后台地址  ：http://${INSTALL_DOMAIN}/admin/"
echo "  用户名    ：${INSTALL_ADMIN_USER}"
echo "  密码      ：${INSTALL_ADMIN_PASS}"
echo "========================================"
echo ""
echo "后续宝塔面板操作："
echo "  1. 进入宝塔面板 -> 网站 -> 添加站点（若还未创建）"
echo "  2. 站点设置 -> 网站目录 -> 运行目录选择 /public"
echo "  3. 站点设置 -> 伪静态 -> 选择 laravel5 模板"
echo "  4. 安全 -> 放行端口：25, 465, 587, 110, 995, 143, 993"
echo ""
echo "登录后请第一时间修改管理员密码。"
