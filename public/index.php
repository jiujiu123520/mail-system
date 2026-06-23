<?php
/**
 * 统一入口
 *
 * 职责：根据 URI 分发到正确的前端页面或 API。
 *   - /api/...          → api.php (JSON API)
 *   - /{admin}/...      → public/admin/index.html
 *   - /assets/... 等    → 直接读取文件
 *   - / (根) 及其他    → web/index.html
 *
 * 关键：不调用 putenv / getenv（宝塔面板常把它们加入 disable_functions）。
 *       全部读取走 config/helpers.php 的 load_env() + env()。
 */

// —— 加载 .env（统一方式：不依赖 putenv / getenv） ——
require_once dirname(__DIR__) . '/config/helpers.php';
load_env();

$adminPath = env('ADMIN_PATH', 'admin');

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = parse_url($uri, PHP_URL_PATH) ?: '/';
$uriPath = '/' . trim($uriPath, '/');

// —— API 路由 ——
if (str_starts_with($uriPath, '/api/')) {
    require __DIR__ . '/api.php';
    exit;
}

// —— 管理后台入口 ——
if ($uriPath === '/' . $adminPath || str_starts_with($uriPath, '/' . $adminPath . '/')) {
    $file = __DIR__ . '/admin/index.html';
    if (file_exists($file)) {
        readfile($file);
        exit;
    }
    http_response_code(404);
    echo 'Admin UI not found.';
    exit;
}

// —— 静态资源 ——
$staticFile = __DIR__ . $uriPath;
if ($uriPath !== '/' && file_exists($staticFile) && !is_dir($staticFile)) {
    $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    $mimeMap = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'html'  => 'text/html; charset=utf-8',
        'txt'   => 'text/plain; charset=utf-8',
        'webp'  => 'image/webp',
        'map'   => 'application/json; charset=utf-8',
    ];
    $mime = $mimeMap[$ext] ?? mime_content_type($staticFile) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    // 静态资源允许浏览器缓存
    $expires = 60 * 60 * 24 * 7; // 7 天
    header('Cache-Control: public, max-age=' . $expires);
    header('Pragma: cache');
    readfile($staticFile);
    exit;
}

// —— 前台首页（默认） ——
$file = __DIR__ . '/web/index.html';
if (file_exists($file)) {
    readfile($file);
    exit;
}

http_response_code(404);
echo 'Page not found.';
