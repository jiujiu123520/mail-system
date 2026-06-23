<?php
/**
 * 数据库 PDO 封装
 */

namespace MailSystem\Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            $config = config('database');
            if (!is_array($config) || empty($config)) {
                throw new Exception('数据库配置缺失，请检查 .env 或 config/app.php');
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function make(array $config): Database
    {
        self::$instance = new self($config);
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    private function connect(): void
    {
        $host    = $this->config['host']     ?? '127.0.0.1';
        $port    = (int) ($this->config['port'] ?? 3306);
        $dbname  = $this->config['database'] ?? '';
        $charset = $this->config['charset']  ?? 'utf8mb4';
        $user    = $this->config['username'] ?? '';
        $pass    = $this->config['password'] ?? '';

        if ($dbname === '') {
            throw new Exception('数据库名称未配置，请在 .env 中设置 DB_DATABASE');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $dbname,
            $charset
        );

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, "Access denied") !== false) {
                throw new Exception("数据库连接失败：用户名或密码错误（检查 .env 中的 DB_USERNAME / DB_PASSWORD）");
            }
            if (strpos($msg, "Unknown database") !== false) {
                throw new Exception("数据库 '{$dbname}' 不存在，请先创建数据库");
            }
            if (strpos($msg, "Connection refused") !== false || strpos($msg, "No such host") !== false) {
                throw new Exception("无法连接数据库服务器 {$host}:{$port}，请检查 MySQL 是否启动、主机地址/端口是否正确");
            }
            throw new Exception('数据库连接失败：' . $msg);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = [])
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', $placeholders)
        );
        $this->query($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = [];
        foreach ($data as $k => $v) {
            $set[] = "`$k` = :$k";
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(',', $set), $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($data, $whereParams));
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function tableExists(string $table): bool
    {
        $row = $this->fetchOne('SHOW TABLES LIKE ?', [$table]);
        return $row !== null;
    }
}
