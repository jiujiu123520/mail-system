<?php
/**
 * 自动加载 & 启动器
 *
 * 关键安全点：
 *   1. 任何未捕获的异常/致命错误都必须以 JSON 格式返回（前端页面除外）
 *   2. 绝不能让 PHP 默认的 <br /><b>Fatal error</b> HTML 污染响应
 */

// —— 最优先：输出缓冲 + 全局错误兜底 ——
ob_start(function (string $buffer): string {
    // 如果 buffer 里含有 PHP 错误标记但又不是我们期望的 JSON，
    // 则丢弃该缓冲（由 shutdown handler 接管最终输出）
    return $buffer;
});

// 全局异常处理器：未捕获异常 → JSON
set_exception_handler(function (\Throwable $e): void {
    // 不依赖 env()/getenv()（可能被宝塔 disable_functions 禁用）
    $debugFlag = $_ENV['APP_DEBUG'] ?? 'false';
    $debug = filter_var($debugFlag, FILTER_VALIDATE_BOOLEAN);
    $classPath = __DIR__ . '/Response.php';
    if (class_exists('MailSystem\\Core\\Response', false) || file_exists($classPath)) {
        if (!class_exists('MailSystem\\Core\\Response', false)) {
            require_once $classPath;
        }
        \MailSystem\Core\Response::fatal($e, $debug);
        return;
    }
    // 终极兜底：直接 echo JSON
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'code'    => 500,
        'message' => $debug ? $e->getMessage() : '服务暂不可用，请稍后重试',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
});

// 全局错误处理器：Warning/Notice → 静默并记日志，绝不输出到响应体
set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    // 让 @ 抑制的错误继续被抑制
    if (!(error_reporting() & $errno)) {
        return false;
    }
    // 不终止脚本执行，但确保错误不被直接 echo 到响应
    // 如果开启 debug，将错误记到日志目录
    $logDir = dirname(__DIR__) . '/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        $line = sprintf(
            "[%s] [%s] %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            $errno,
            $errstr,
            $errfile,
            $errline
        );
        @file_put_contents($logDir . '/php-errors.log', $line, FILE_APPEND);
    }
    return true; // 不继续 PHP 默认处理（防止 HTML 输出）
});

// Shutdown handler：捕获 E_ERROR / E_PARSE 等致命错误
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $debugFlag = $_ENV['APP_DEBUG'] ?? 'false';
        $debug = filter_var($debugFlag, FILTER_VALIDATE_BOOLEAN);
        $message = $debug ? $err['message'] : '服务暂不可用，请稍后重试';
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'code'    => 500,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

// —— 加载环境变量（统一用 helpers.php 中的 load_env，不依赖 putenv/getenv） ——
require_once __DIR__ . '/../../config/helpers.php';

$envFile = base_path('.env');
if (file_exists($envFile)) {
    load_env($envFile);
}

// —— 基础设置 ——
date_default_timezone_set(config('app.timezone', 'UTC'));

$isDebug = filter_var(config('app.debug', false), FILTER_VALIDATE_BOOLEAN);
error_reporting($isDebug ? E_ALL : E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');            // 无论是否 debug，都禁止直接 echo 到响应
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
$logDir = base_path('logs');
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logDir . '/php-errors.log');
}

// —— 自动加载 ——
spl_autoload_register(function (string $class): void {
    $prefix = 'MailSystem\\';
    if (strpos($class, $prefix) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = base_path('app/' . str_replace('\\', '/', $relative) . '.php');
    if (file_exists($file)) require_once $file;
});

// 预加载核心类（避免自动加载在异常路径中失败）
foreach (['Request', 'Response', 'Router', 'Auth', 'Database', 'Logger'] as $cls) {
    $f = base_path('app/Core/' . $cls . '.php');
    if (file_exists($f)) require_once $f;
}
foreach (glob(base_path('app/Models/*.php')) as $f) {
    require_once $f;
}
