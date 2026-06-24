<?php

namespace MailSystem\Models;

use MailSystem\Core\Database;

class Email
{
    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetchOne('SELECT * FROM ms_emails WHERE id = ?', [$id]);
    }
    public static function listByMailbox(int $mailboxId, string $folder = 'INBOX', int $limit = 50, int $offset = 0, string $keyword = ''): array
    {
        $sql = 'SELECT id, message_id, from_address, from_name, to_addresses, subject, folder, is_read, is_starred, direction, status, size_bytes, maildir_filename, created_at FROM ms_emails WHERE mailbox_id = ? AND folder = ?';
        $params = [$mailboxId, $folder];
        $sql .= self::buildKeywordWhereClause($keyword, $params);
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return Database::getInstance()->fetchAll($sql, $params);
    }
    public static function countByMailbox(int $mailboxId, string $folder = 'INBOX', string $keyword = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM ms_emails WHERE mailbox_id = ? AND folder = ?';
        $params = [$mailboxId, $folder];
        $sql .= self::buildKeywordWhereClause($keyword, $params);
        return (int) Database::getInstance()->fetchValue($sql, $params);
    }

    public static function listConversationsByMailbox(int $mailboxId, string $folder = 'INBOX', int $limit = 50, int $offset = 0, string $keyword = ''): array
    {
        $db = Database::getInstance();
        $sql = 'SELECT e1.* FROM ms_emails e1
                JOIN (
                    SELECT MAX(id) as max_id FROM ms_emails
                    WHERE mailbox_id = ? AND folder = ?';
        $params = [$mailboxId, $folder];

        $sql .= self::buildKeywordWhereClause($keyword, $params);

        $sql .= ' GROUP BY conversation_id
                ) AS e2 ON e1.id = e2.max_id
                ORDER BY e1.created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        return $db->fetchAll($sql, $params);
    }

    public static function countConversationsByMailbox(int $mailboxId, string $folder = 'INBOX', string $keyword = ''): int
    {
        $db = Database::getInstance();
        $sql = 'SELECT COUNT(DISTINCT conversation_id) FROM ms_emails
                WHERE mailbox_id = ? AND folder = ?';
        $params = [$mailboxId, $folder];

        $sql .= self::buildKeywordWhereClause($keyword, $params);

        return (int) $db->fetchValue($sql, $params);
    }

    public static function getConversation(string $conversationId, int $mailboxId): array
    {
        $sql = 'SELECT * FROM ms_emails WHERE conversation_id = ? AND mailbox_id = ? ORDER BY created_at ASC';
        return Database::getInstance()->fetchAll($sql, [$conversationId, $mailboxId]);
    }
    public static function create(array $data): int
    {
        return Database::getInstance()->insert('ms_emails', $data);
    }
    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('ms_emails', $data, 'id = :id', ['id' => $id]);
    }
    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('ms_emails', 'id = :id', ['id' => $id]);
    }

    private static function buildKeywordWhereClause(string $keyword, array &$params): string
    {
        $where = '';
        if ($keyword !== '') {
            $where .= ' AND (subject LIKE ? OR from_address LIKE ? OR body_text LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw; $params[] = $kw; $params[] = $kw;
        }
        return $where;
    }
}
