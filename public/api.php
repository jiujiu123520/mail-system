<?php
/**
 * API 入口 - 处理所有 /api/* 请求
 *
 * 本文件的第一原则：响应体必须是合法 JSON。
 * 任何 PHP 错误、未捕获异常、警告都由 Bootstrap.php 中注册的
 * 全局处理器接管，绝不应输出 "<br /><b>Fatal error</b>..."
 */

require_once __DIR__ . '/../app/Core/Bootstrap.php';

use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Core\Router;

// Session（仅在未发送 header 时启动）
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$req = new Request();
$path = $req->path();
$method = $req->method();

// 安装检测：已安装时仅允许正常访问，未安装时仅允许返回明确提示
$installed = file_exists(base_path('storage/installed.lock'));
if (!$installed) {
    Response::error('系统未安装，请先完成安装（执行 bin/install-cli.php 或访问 install/install.php）', 503, 503);
}

$router = new Router();

// ===== 公开 API（无需登录） =====
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->get('/api/auth/captcha', [AuthController::class, 'captcha']);
$router->post('/api/webmail/login', [WebmailController::class, 'login']);
$router->post('/api/webmail/logout', [WebmailController::class, 'logout']);
$router->get('/api/webmail/me', [WebmailController::class, 'me']);
$router->get('/api/setting/public', [SettingController::class, 'public']);
$router->get('/api/setting/admin-path', [SettingController::class, 'getAdminPath']);

// ===== 需登录 API =====
$router->group('/api', function (Router $r) {
    $r->get('/auth/me', [AuthController::class, 'me']);
    $r->post('/auth/logout', [AuthController::class, 'logout']);
    $r->post('/auth/change-password', [AuthController::class, 'changePassword']);

    $r->get('/domains', [DomainController::class, 'index']);
    $r->get('/domains/{id}', [DomainController::class, 'show']);
    $r->post('/domains', [DomainController::class, 'create']);
    $r->put('/domains/{id}', [DomainController::class, 'update']);
    $r->delete('/domains/{id}', [DomainController::class, 'delete']);
    $r->get('/domains/{id}/dns', [DomainController::class, 'dnsRecords']);
    $r->post('/domains/{id}/dns-sync', [DomainController::class, 'dnsSync']);

    $r->get('/mailboxes', [MailboxController::class, 'index']);
    $r->get('/mailboxes/{id}', [MailboxController::class, 'show']);
    $r->post('/mailboxes', [MailboxController::class, 'create']);
    $r->put('/mailboxes/{id}', [MailboxController::class, 'update']);
    $r->delete('/mailboxes/{id}', [MailboxController::class, 'delete']);

    $r->get('/ports', [PortController::class, 'index']);
    $r->post('/ports', [PortController::class, 'create']);
    $r->put('/ports/{id}', [PortController::class, 'update']);
    $r->delete('/ports/{id}', [PortController::class, 'delete']);
    $r->post('/ports/{id}/test', [PortController::class, 'test']);

    $r->get('/mailboxes/{id}/emails', [EmailController::class, 'list']);
    $r->post('/emails/send', [EmailController::class, 'send']);
    $r->get('/emails/{id}', [EmailController::class, 'show']);
    $r->delete('/emails/{id}', [EmailController::class, 'delete']);
    $r->post('/emails/{id}/move', [EmailController::class, 'move']);
    $r->post('/emails/{id}/star', [EmailController::class, 'star']);

    $r->get('/api-keys', [ApiKeyController::class, 'index']);
    $r->post('/api-keys', [ApiKeyController::class, 'create']);
    $r->put('/api-keys/{id}', [ApiKeyController::class, 'update']);
    $r->delete('/api-keys/{id}', [ApiKeyController::class, 'delete']);

    $r->get('/settings', [SettingController::class, 'all']);
    $r->post('/settings', [SettingController::class, 'update']);

    $r->get('/users', [UserController::class, 'index']);
    $r->post('/users', [UserController::class, 'create']);
    $r->put('/users/{id}', [UserController::class, 'update']);
    $r->delete('/users/{id}', [UserController::class, 'delete']);
    $r->get('/logs', [UserController::class, 'logs']);

    $r->get('/system/stats', [SystemController::class, 'stats']);
    $r->get('/system/services', [SystemController::class, 'services']);
    $r->get('/system/info', [SystemController::class, 'info']);

    // 安全相关
    $r->get('/security/ip-list', [SecurityController::class, 'ipList']);
    $r->post('/security/ip-block', [SecurityController::class, 'ipBlock']);
    $r->delete('/security/ip/{id}', [SecurityController::class, 'ipUnblock']);
    $r->get('/security/device-list', [SecurityController::class, 'deviceList']);
    $r->post('/security/device/{id}/block', [SecurityController::class, 'deviceBlock']);
    $r->post('/security/device/{id}/unblock', [SecurityController::class, 'deviceUnblock']);
    $r->delete('/security/device/{id}', [SecurityController::class, 'deviceDelete']);
    $r->post('/security/device/{id}/trust', [SecurityController::class, 'deviceTrust']);
    $r->get('/security/my-devices', [SecurityController::class, 'myDevices']);
});

// ===== 对外 API (v1) =====
$router->group('/api/v1', function (Router $r) {
    $r->post('/send', [PublicApiController::class, 'send']);
    $r->get('/inbox', [PublicApiController::class, 'inbox']);
    $r->get('/email/{id}', [PublicApiController::class, 'email']);
    $r->get('/mailboxes', [PublicApiController::class, 'mailboxes']);
});

$router->dispatch($req);
