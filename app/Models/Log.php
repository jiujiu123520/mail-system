<?php

namespace MailSystem\Models;

use MailSystem\Core\Database;

class Log
{
    public static function create(array $data): int
    {
        return Database::getInstance()->insert('ms_logs', $data);
    }
    public static function list(int $limit = 100, int $offset = 0, array $filters = []): array
    {
        $sql = 'SELECT l.*, u.username FROM ms_logs l LEFT JOIN ms_users u ON l.user_id = u.id';
        $params = [];
        $sql .= self::buildFilterWhereClause($filters, $params);
        $sql .= ' ORDER BY l.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM ms_logs';
        $params = [];
        $sql .= self::buildFilterWhereClause($filters, $params);
        return (int) Database::getInstance()->fetchValue($sql, $params);
    }

    private static function buildFilterWhereClause(array $filters, array &$params): string
    {
        $where = ' WHERE 1=1';
        if (!empty($filters['action'])) { $where .= ' AND action = ?'; $params[] = $filters['action']; }
        if (!empty($filters['user_id'])) { $where .= ' AND user_id = ?'; $params[] = (int) $filters['user_id']; }
        return $where;
    }
}
