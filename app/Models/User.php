<?php

namespace MailSystem\Models;

use MailSystem\Core\Database;
use MailSystem\Models\UserGroup;

class User
{
    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM ms_users WHERE id = ?', [$id]);
    }
    public static function findByUsername(string $u): ?array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM ms_users WHERE username = ?', [$u]);
    }

    public static function getPermissions(int $userId): array
    {
        $user = self::find($userId);
        if (!$user || empty($user['group_id'])) {
            return []; // No group, no special permissions
        }

        $group = UserGroup::find($user['group_id']);
        if (!$group || empty($group['permissions'])) {
            return [];
        }

        $permissions = json_decode($group['permissions'], true);
        return is_array($permissions) ? $permissions : [];
    }

    public static function all(): array
    {
        return Database::getInstance()->fetchAll('SELECT * FROM ms_users ORDER BY id ASC');
    }
    public static function create(array $data): int
    {
        return Database::getInstance()->insert('ms_users', $data);
    }
    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('ms_users', $data, 'id = :id', ['id' => $id]);
    }
    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('ms_users', 'id = :id', ['id' => $id]);
    }

    public static function getMembershipCards(int $userId): array
    {
        return Database::getInstance()->fetchAll('SELECT * FROM ms_membership_cards WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    }
}
