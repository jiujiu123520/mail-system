<?php

namespace MailSystem\Models;

use MailSystem\Core\Database;

class Captcha
{
    public static function generate(string $key, int $length = 4): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= mt_rand(0, 9);
        }
        // 使用SHA256哈希存储验证码
        $hash = hash('sha256', $code);
        Database::getInstance()->insert('ms_captchas', [
            '`key`' => $key,
            'code' => $hash,
            'expires_at' => date('Y-m-d H:i:s', time() + 300), // 5分钟有效
        ]);
        return $code;
    }

    public static function verify(string $key, string $code): bool
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM ms_captchas WHERE `key` = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
            [$key]
        );
        if (!$row) return false;
        // 使用SHA256验证
        $hash = hash('sha256', strtolower($code));
        if (!hash_equals($row['code'], $hash)) return false;
        // 标记为已使用
        Database::getInstance()->update('ms_captchas', ['used' => 1], 'id = ?', [$row['id']]);
        return true;
    }

    public static function cleanup(): int
    {
        return Database::getInstance()->exec('DELETE FROM ms_captchas WHERE expires_at < NOW() OR used = 1');
    }
}
