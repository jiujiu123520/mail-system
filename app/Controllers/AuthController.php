<?php
/**
 * 认证控制器
 */

namespace MailSystem\Controllers;

use MailSystem\Core\Auth;
use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Models\Captcha;
use MailSystem\Models\Device;
use MailSystem\Models\IpBlacklist;
use MailSystem\Models\Setting;
use MailSystem\Models\User;
use MailSystem\Models\Mailbox;

class AuthController extends BaseController
{
    public function login(Request $req): void
    {
        $username = trim((string) $req->input('username'));
        $password = (string) $req->input('password');
        $fingerprint = (string) $req->input('fingerprint', '');
        $ip = $req->ip();
        $ua = $req->header('User-Agent', '');

        // 检查IP是否被封禁
        if (IpBlacklist::isBlocked($ip)) {
            Response::error('该IP已被封禁，请联系管理员', 403, 403);
        }

        // 检查登录失败次数
        $maxFail = (int) Setting::get('max_login_failures', 5);
        if ($maxFail > 0) {
            $failKey = 'login_fail_' . md5($ip . $username);
            $failCount = (int) ($_SESSION[$failKey] ?? 0);
            if ($failCount >= $maxFail) {
                $lockMinutes = (int) Setting::get('login_lock_minutes', 30);
                Response::error("登录失败次数过多，请 {$lockMinutes} 分钟后再试", 429, 429);
            }
        }

        if ($username === '' || $password === '') {
            Response::error('请输入用户名和密码', 400, 400);
        }

        $user = Auth::login($username, $password);
        if (!$user) {
            // 记录失败次数
            if ($maxFail > 0) {
                $failKey = 'login_fail_' . md5($ip . $username);
                $_SESSION[$failKey] = ($_SESSION[$failKey] ?? 0) + 1;
            }
            Response::error('用户名或密码错误', 401, 401);
        }

        // 记录登录设备
        if ($fingerprint) {
            Device::recordLogin($user['id'], $fingerprint, $ip, $ua);

            // 检查设备是否被拉黑
            $device = Device::findByFingerprint($fingerprint, $user['id']);
            if ($device && $device['is_blocked']) {
                Response::error('该设备已被拉黑，请联系管理员', 403, 403);
            }
        }

        // 更新用户最后登录信息
        User::update($user['id'], [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);

        // 清除失败次数
        if ($maxFail > 0) {
            unset($_SESSION['login_fail_' . md5($ip . $username)]);
        }

        $this->log('login', $user['username'], 'login success', $ip, $ua);
        unset($user['password']);
        $this->ok([
            'user' => $user,
            'token' => self::issueToken($user),
        ]);
    }

    public function logout(Request $req): void
    {
        Auth::logout();
        $this->ok(null, '已退出登录');
    }

    public function me(Request $req): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $this->ok($user);
    }

    public function changePassword(Request $req): void
    {
        Auth::requireLogin();
        $old = (string) $req->input('old_password');
        $new = (string) $req->input('new_password');
        if (strlen($new) < 6) {
            Response::error('新密码长度至少 6 位', 400, 400);
        }
        $u = User::find(Auth::id());
        if (!$u || !password_verify($old, $u['password'])) {
            Response::error('原密码错误', 400, 400);
        }
        User::update(Auth::id(), [
            'password' => password_hash($new, PASSWORD_DEFAULT),
        ]);
        $this->ok(null, '密码已更新');
    }

    // ==================== 图形验证码 ====================

    public function captcha(Request $req): void
    {
        $key = 'captcha_' . bin2hex(random_bytes(16));
        $code = Captcha::generate($key, 4);

        // 生成SVG图片
        $svg = $this->generateCaptchaSvg($code);

        // 清理过期验证码
        Captcha::cleanup();

        $this->ok([
            'key' => $key,
            'svg' => $svg,
        ]);
    }

    private function generateCaptchaSvg(string $code): string
    {
        $width = 120;
        $height = 40;
        $length = strlen($code);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">';
        $svg .= '<rect width="100%" height="100%" fill="#f5f5f5"/>';

        // 干扰线
        for ($i = 0; $i < 3; $i++) {
            $x1 = mt_rand(0, $width);
            $y1 = mt_rand(0, $height);
            $x2 = mt_rand(0, $width);
            $y2 = mt_rand(0, $height);
            $color = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
            $svg .= "<line x1='$x1' y1='$y1' x2='$x2' y2='$y2' stroke='$color' stroke-width='1' opacity='0.3'/>";
        }

        // 干扰点
        for ($i = 0; $i < 30; $i++) {
            $x = mt_rand(0, $width);
            $y = mt_rand(0, $height);
            $color = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
            $svg .= "<circle cx='$x' cy='$y' r='1' fill='$color' opacity='0.4'/>";
        }

        // 验证码文字
        $fontSize = 24;
        $spacing = ($width - 20) / $length;
        for ($i = 0; $i < $length; $i++) {
            $x = 10 + $i * $spacing + mt_rand(-3, 3);
            $y = $height / 2 + mt_rand(-5, 5);
            $rotate = mt_rand(-15, 15);
            $color = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
            $svg .= "<text x='$x' y='$y' font-family='Arial' font-size='$fontSize' fill='$color' transform='rotate($rotate,$x,$y)'>" . $code[$i] . "</text>";
        }

        $svg .= '</svg>';
        return $svg;
    }

    // ==================== 用户注册 ====================

    public function register(Request $req): void
    {
        // 检查是否开放注册
        if (!Setting::get('allow_registration', '0')) {
            Response::error('系统已关闭自助注册', 403, 403);
        }

        // 图形验证码
        if (Setting::get('require_captcha', '1')) {
            $captchaKey = trim((string) $req->input('captcha_key'));
            $captchaCode = trim((string) $req->input('captcha_code'));
            if (!Captcha::verify($captchaKey, $captchaCode)) {
                Response::error('验证码错误或已过期', 400, 400);
            }
        }

        $username = trim((string) $req->input('username'));
        $password = (string) $req->input('password');
        $email = trim((string) $req->input('email', ''));

        if ($username === '' || strlen($password) < 6) {
            Response::error('用户名必填，密码至少6位', 400, 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            Response::error('用户名只能包含字母、数字、下划线，长度3-32位', 400, 400);
        }

        if (User::findByUsername($username)) {
            Response::error('用户名已存在', 400, 400);
        }

        // 前端传来的密码是SHA256加密后的，验证后重新哈希存储
        // 如果前端没有加密，则直接使用
        $passwordHash = strlen($password) === 64 && preg_match('/^[a-f0-9]{64}$/', $password)
            ? password_hash($password, PASSWORD_DEFAULT)
            : password_hash($password, PASSWORD_DEFAULT);

        $id = User::create([
            'username' => $username,
            'password' => $passwordHash,
            'email' => $email,
            'role' => 'user',
            'status' => 1,
        ]);

        $this->log('register', $username);
        $this->ok(['id' => $id], '注册成功');
    }

    /**
     * 简单 Token (HMAC), 避免引入 JWT 库
     */
    public static function issueToken(array $user): string
    {
        $payload = [
            'uid'      => $user['id'],
            'username' => $user['username'],
            'role'     => $user['role'],
            'exp'      => time() + 86400 * 7,
        ];
        $key = config('app.key', 'default');
        $json = json_encode($payload);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64, $key, true)), '+/', '-_'), '=');
        return $b64 . '.' . $sig;
    }

    public static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        [$b64, $sig] = $parts;
        $key = config('app.key', 'default');
        $expect = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64, $key, true)), '+/', '-_'), '=');
        if (!hash_equals($expect, $sig)) return null;
        $json = base64_decode(strtr($b64, '-_', '+/'), true);
        if (!$json) return null;
        $payload = json_decode($json, true);
        if (!$payload) return null;
        if ($payload['exp'] < time()) return null;
        return $payload;
    }
}

