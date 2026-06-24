<?php

namespace MailSystem\Models;

use MailSystem\Core\Database;

class MembershipCard
{
    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM ms_membership_cards WHERE id = ?', [$id]);
    }

    public static function findByCardKey(string $cardKey): ?array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM ms_membership_cards WHERE card_key = ?', [$cardKey]);
    }

    public static function all(): array
    {
        return Database::getInstance()->fetchAll('SELECT * FROM ms_membership_cards ORDER BY id ASC');
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('ms_membership_cards', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('ms_membership_cards', $data, 'id = :id', ['id' => $id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('ms_membership_cards', 'id = :id', ['id' => $id]);
    }

    public static function list(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $sql = 'SELECT * FROM ms_membership_cards';
        $params = [];
        $sql .= self::buildFilterWhereClause($filters, $params);

        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM ms_membership_cards';
        $params = [];
        $sql .= self::buildFilterWhereClause($filters, $params);

        return (int) Database::getInstance()->fetchValue($sql, $params);
    }

    public static function generateUniqueCardKey(): string
    {
        $length = 15;
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        do {
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[rand(0, $charactersLength - 1)];
            }
        } while (self::findByCardKey($randomString)); // Ensure uniqueness

        return $randomString;
    }

    private static function buildFilterWhereClause(array $filters, array &$params): string
    {
        $where = [];
        if (isset($filters['status']) && in_array($filters['status'], ['unused', 'used'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (isset($filters['user_id']) && $filters['user_id'] !== null) {
            $where[] = 'user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }
        if (isset($filters['card_key']) && !empty($filters['card_key'])) {
            $where[] = 'card_key = :card_key';
            $params['card_key'] = $filters['card_key'];
        }
        return !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
    }
}