<?php

namespace MailSystem\Models;

use MailSystem\Core\Database;

class UserGroup
{
    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM ms_user_groups WHERE id = ?', [$id]);
    }

    public static function findByName(string $name): ?array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM ms_user_groups WHERE name = ?', [$name]);
    }

    public static function all(): array
    {
        return Database::getInstance()->fetchAll('SELECT * FROM ms_user_groups ORDER BY id ASC');
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('ms_user_groups', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('ms_user_groups', $data, 'id = :id', ['id' => $id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('ms_user_groups', 'id = :id', ['id' => $id]);
    }
}