<?php

namespace MailSystem\Models;

use MailSystem\Core\Database;

class Device
{
    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM ms_user_devices WHERE id = ?', [$id]);
    }

    public static function findByFingerprint(string $fingerprint, int $userId): ?array
    {
        return Database::getInstance()->fetchOne(
            'SELECT * FROM ms_user_devices WHERE fingerprint = ? AND user_id = ?',
            [$fingerprint, $userId]
        );
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('ms_user_devices', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('ms_user_devices', $data, 'id = :id', ['id' => $id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('ms_user_devices', 'id = :id', ['id' => $id]);
    }

    public static function allByUser(int $userId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM ms_user_devices WHERE user_id = ? ORDER BY last_login_at DESC',
            [$userId]
        );
    }

    public static function all(int $limit = 100, int $offset = 0): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT d.*, u.username FROM ms_user_devices d LEFT JOIN ms_users u ON d.user_id = u.id ORDER BY d.id DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    public static function count(): int
    {
        $row = Database::getInstance()->fetchOne('SELECT COUNT(*) as c FROM ms_user_devices');
        return (int) ($row['c'] ?? 0);
    }

    public static function recordLogin(int $userId, string $fingerprint, string $ip, string $ua, string $deviceName = ''): int
    {
        $existing = self::findByFingerprint($fingerprint, $userId);
        if ($existing) {
            return Database::getInstance()->update('ms_user_devices', [
                'ip_address' => $ip,
                'user_agent' => substr($ua, 0, 500),
                'device_name' => $deviceName,
                'last_login_at' => date('Y-m-d H:i:s'),
                'login_count' => $existing['login_count'] + 1,
            ], 'id = :id', ['id' => $existing['id']]);
        }
        return self::create([
            'user_id' => $userId,
            'fingerprint' => $fingerprint,
            'ip_address' => $ip,
            'user_agent' => substr($ua, 0, 500),
            'device_name' => $deviceName,
            'is_trusted' => 0,
            'is_blocked' => 0,
            'last_login_at' => date('Y-m-d H:i:s'),
            'login_count' => 1,
        ]);
    }
}
