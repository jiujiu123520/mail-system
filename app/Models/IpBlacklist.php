<?php

namespace MailSystem\Models;

use MailSystem\Core\Database;

class IpBlacklist
{
    public static function isBlocked(string $ip): bool
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT id FROM ms_ip_blacklist WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1',
            [$ip]
        );
        return $row !== null;
    }

    public static function block(string $ip, ?string $reason = null, ?int $createdBy = null, ?int $minutes = null): int
    {
        return Database::getInstance()->insert('ms_ip_blacklist', [
            'ip_address' => $ip,
            'reason' => $reason,
            'created_by' => $createdBy,
            'expires_at' => $minutes ? date('Y-m-d H:i:s', time() + $minutes * 60) : null,
        ]);
    }

    public static function unblock(int $id): int
    {
        return Database::getInstance()->delete('ms_ip_blacklist', 'id = :id', ['id' => $id]);
    }

    public static function all(int $limit = 100, int $offset = 0): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT b.*, u.username as operator FROM ms_ip_blacklist b LEFT JOIN ms_users u ON b.created_by = u.id ORDER BY b.id DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    public static function count(): int
    {
        $row = Database::getInstance()->fetchOne('SELECT COUNT(*) as c FROM ms_ip_blacklist');
        return (int) ($row['c'] ?? 0);
    }
}
