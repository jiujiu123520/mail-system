<?php
use PDO;
use PDOException;

/**
 * CLI 安装脚本 - 一键安装脚本 install.sh 调用
 *
 * 用法:
 *   php bin/install-cli.php --db-name=mail_system --db-user=mail_user --db-pass=xxx
 *                          [--db-host=127.0.0.1] [--db-port=3306] [--db-root-pass=ROOTPASS]
 *                          [--admin-user=admin] [--admin-pass=xxx] [--admin-email=xxx]
 *                          [--admin-path=admin] [--admin-port=8080]
 *                          [--mail-hostname=mail.example.com] [--default-domain=example.com]
 */

$base = dirname(__DIR__);

// -------- 1. 先从 .env 读取已有值（作为默认） --------
$envFile = $base . '/.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
}

// -------- 2. 解析命令行参数（优先于 .env） --------
$opts = getopt('', [
    'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-pass:', 'db-root-pass:',
    'admin-user:', 'admin-pass:', 'admin-email:',
    'admin-path:', 'admin-port:', 'mail-hostname:', 'app-url:', 'default-domain:',
]);

function installerOpt(string $key, $default = null) {
    global $opts;
    return $opts[$key] ?? $default;
}

function installerEnvOrOpt(string $envKey, string $optKey, $default = null) {
    global $env, $opts;
    if (isset($opts[$optKey]) && $opts[$optKey] !== '') return $opts[$optKey];
    if (isset($env[$envKey]) && $env[$envKey] !== '') return $env[$envKey];
    return $default;
}

// 数据库连接参数
$dbHost = installerEnvOrOpt('DB_HOST',    'db-host',    '127.0.0.1');
$dbPort = (int) installerEnvOrOpt('DB_PORT',   'db-port',   3306);
$dbName = installerEnvOrOpt('DB_DATABASE', 'db-name',   'mail_system');
$dbUser = installerEnvOrOpt('DB_USERNAME', 'db-user',   'mail_user');
$dbPass = installerEnvOrOpt('DB_PASSWORD', 'db-pass',   '');
$dbRootPass = installerOpt('db-root-pass', '');

// -------- 3. 连接 MySQL（多级回退策略） --------
$dsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
$errs = [];
$pdo = null;
$connectedAsRoot = false;

// 策略 1: root + 传入的 root 密码
if ($dbRootPass !== '') {
    try {
        $pdo = new PDO($dsn, 'root', $dbRootPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connectedAsRoot = true;
    } catch (PDOException $e) { $errs[] = "root/$dbRootPass: " . $e->getMessage(); }
}

// 策略 2: root + 空密码（本地默认环境）
if (!$pdo) {
    try {
        $pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connectedAsRoot = true;
    } catch (PDOException $e) { $errs[] = "root/'' : " . $e->getMessage(); }
}

// 策略 3: 直接使用业务用户（假设数据库已存在）
if (!$pdo && $dbUser !== '' && $dbPass !== '') {
    try {
        $pdo = new PDO("$dsn;dbname=$dbName", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connectedAsRoot = false;
    } catch (PDOException $e) { $errs[] = "$dbUser/$dbPass: " . $e->getMessage(); }
}

if (!$pdo) {
    fwrite(STDERR, "[错误] 无法连接 MySQL，所有尝试均失败：\n");
    foreach ($errs as $e) fwrite(STDERR, "  - $e\n");
    fwrite(STDERR, "\n请检查：\n  1. MySQL 是否启动\n  2. 密码是否正确（宝塔请在面板中查看）\n  3. 如需指定 root 密码，请使用 --db-root-pass=你的密码\n");
    exit(1);
}

echo "[OK] MySQL 连接成功（身份: " . ($connectedAsRoot ? 'root' : $dbUser) . ")\n";

// -------- 4. 创建数据库（非 root 时跳过，依赖用户已准备好） --------
if ($connectedAsRoot) {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // 确保业务用户存在并有访问权限
    try {
        $pdo->exec("CREATE USER IF NOT EXISTS '$dbUser'@'localhost' IDENTIFIED BY '$dbPass'");
        $pdo->exec("CREATE USER IF NOT EXISTS '$dbUser'@'127.0.0.1' IDENTIFIED BY '$dbPass'");
    } catch (PDOException $e) { /* 用户可能已存在，忽略 */ }
    $pdo->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'localhost'");
    $pdo->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'127.0.0.1'");
    $pdo->exec("FLUSH PRIVILEGES");
    echo "[OK] 数据库 $dbName 已就绪\n";
}
$pdo->exec("USE `$dbName`");

// 2. 导入 schema
$sqlFile = $base . '/database/schema.sql';
if (!file_exists($sqlFile)) {
    fwrite(STDERR, "[错误] schema.sql 不存在: $sqlFile\n");
    exit(1);
}
$sql = file_get_contents($sqlFile);
$sql = preg_replace('/SET FOREIGN_KEY_CHECKS = [01];/i', '', $sql);

// 按分号拆分执行（忽略空语句）
$statements = array_filter(array_map('trim', explode(";\n", $sql)));
$total = count($statements);
$done = 0;
foreach ($statements as $stmt) {
    if ($stmt === '') continue;
    try {
        $pdo->exec($stmt);
        $done++;
    } catch (PDOException $e) {
        // 忽略 DROP TABLE 时的 "Unknown table" 等无害错误；报告其他错误
        if (strpos($e->getMessage(), 'Unknown') === false
            && strpos($e->getMessage(), 'Duplicate') === false) {
            echo "[警告] SQL 警告: " . substr($e->getMessage(), 0, 80) . "\n";
        }
    }
}
echo "[OK] 数据库 schema 已导入（$done/$total 条语句）\n";

// 3. 管理员与域名设置（$env 已在顶部读取，此处读取配置）
$adminUser  = installerOpt('admin-user', 'admin');
$adminPass  = installerOpt('admin-pass', '');
$adminEmail = installerOpt('admin-email', 'admin@localhost.com');
$adminPath  = installerOpt('admin-path', 'admin');
$adminPort  = installerOpt('admin-port', 8080);
$mailHost   = installerOpt('mail-hostname', 'mail.local');
$appUrl     = installerOpt('app-url', 'http://localhost');
$domain     = installerOpt('default-domain', '');

// 管理员密码为空时随机生成
if ($adminPass === '' || $adminPass === null) {
    $adminPass = substr(bin2hex(random_bytes(10)), 0, 12);
}

// 更新 admin 账号
$stmt = $pdo->prepare("UPDATE ms_users SET username = ?, password = ?, email = ?, display_name = ? WHERE username = 'admin'");
$stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminEmail, $adminUser]);
if ($stmt->rowCount() === 0) {
    // admin 用户不存在（schema 被截断），直接插入
    $pdo->prepare("INSERT INTO ms_users (username, password, email, display_name, role, status) VALUES (?, ?, ?, ?, 'admin', 1)")
        ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminEmail, $adminUser]);
}
echo "[OK] 管理员账号已设置 ($adminUser)\n";

// 创建默认域名
if ($domain) {
    $adminRow = $pdo->query("SELECT id FROM ms_users WHERE username = '$adminUser'")->fetch();
    $adminId = (int) ($adminRow['id'] ?? 0);
    if ($adminId > 0) {
        $pdo->prepare("INSERT IGNORE INTO ms_domains (domain, owner_id, status, is_default) VALUES (?, ?, 1, 1)")
            ->execute([$domain, $adminId]);

        $dRow = $pdo->query("SELECT id FROM ms_domains WHERE domain = '$domain'")->fetch();
        $dId = (int) ($dRow['id'] ?? 0);
        if ($dId > 0) {
            $local = explode('@', $adminEmail)[0] ?: 'admin';
            $fullAddr = (strpos($adminEmail, '@') !== false) ? $adminEmail : "$local@$domain";
            $pdo->prepare("INSERT IGNORE INTO ms_mailboxes (user_id, domain_id, local_part, full_address, password, display_name, status) VALUES (?, ?, ?, ?, ?, ?, 1)")
                ->execute([$adminId, $dId, $local, $fullAddr, password_hash($adminPass, PASSWORD_DEFAULT), $adminUser]);
            echo "[OK] 默认域名与邮箱已创建 ($domain)\n";
        }
    }
}

// 4. 更新设置
$pdo->prepare("UPDATE ms_settings SET `value`=? WHERE key_name='admin_path'")->execute([$adminPath]);
$pdo->prepare("UPDATE ms_settings SET `value`=? WHERE key_name='admin_port'")->execute([(string)$adminPort]);
$pdo->prepare("UPDATE ms_settings SET `value`=? WHERE key_name='mail_hostname'")->execute([$mailHost]);
$pdo->prepare("UPDATE ms_settings SET `value`=? WHERE key_name='site_name'")->execute(['MailSystem']);

// 5. 写 .env（覆盖）
if (!file_exists($envFile) || empty($env['APP_KEY'])) {
    $env['APP_KEY'] = bin2hex(random_bytes(16));
}
$env['APP_NAME']     = $env['APP_NAME'] ?? 'MailSystem';
$env['APP_ENV']      = $env['APP_ENV'] ?? 'production';
$env['APP_DEBUG']    = $env['APP_DEBUG'] ?? 'false';
$env['APP_URL']      = $appUrl;
$env['APP_TIMEZONE'] = $env['APP_TIMEZONE'] ?? 'Asia/Shanghai';
$env['DB_HOST']      = $dbHost;
$env['DB_PORT']      = $dbPort;
$env['DB_DATABASE']  = $dbName;
$env['DB_USERNAME']  = $dbUser;
$env['DB_PASSWORD']  = $dbPass;
$env['DB_CHARSET']   = 'utf8mb4';
$env['ADMIN_PATH']   = $adminPath;
$env['ADMIN_PORT']   = $adminPort;
$env['MAIL_HOSTNAME'] = $mailHost;

$lines = ["# MailSystem Configuration - generated by installer at " . date('Y-m-d H:i:s')];
foreach ($env as $k => $v) {
    if ($k === '' || $v === null) continue;
    $v = (string)$v;
    if (strpos($v, ' ') !== false && !str_starts_with($v, '"')) $v = '"' . $v . '"';
    $lines[] = "$k=$v";
}
file_put_contents($envFile, implode("\n", $lines) . "\n");
@chmod($envFile, 0600);
echo "[OK] 配置文件已写入: $envFile\n";

// 6. 创建必要目录
@mkdir($base . '/data/mailboxes', 0755, true);
@mkdir($base . '/storage/cache', 0755, true);
@mkdir($base . '/storage/sessions', 0755, true);
@mkdir($base . '/logs', 0755, true);

// 7. 锁定
file_put_contents($base . '/storage/installed.lock', json_encode([
    'installed_at' => date('Y-m-d H:i:s'),
    'installer'    => 'cli',
    'version'      => '1.1.0',
]));

echo "\n========================================\n";
echo "✓ 系统已安装\n";
echo "  后台地址: $appUrl/$adminPath/\n";
echo "  管理员:   $adminUser / $adminPass\n";
echo "========================================\n";
