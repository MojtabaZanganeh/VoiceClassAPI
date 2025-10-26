<?php
namespace Classes\Base;

use PDO;
use PDOException;

/**
 * Database Class using PDO
 *
 * Handles database operations securely and flexibly using PDO.
 */
class Database
{
    /**
     * PDO connection object.
     *
     * @var PDO
     */
    private PDO $connection;

    /**
     * Table name mappings.
     *
     * @var array
     */
    public array $table = [
        'book_details' => 'book_details',
        'cart_items' => 'cart_items',
        'categories' => 'categories',
        'chapters' => 'chapters',
        'chapter_lessons' => 'chapter_lessons',
        'course_details' => 'course_details',
        'course_students' => 'course_students',
        'discount_codes' => 'discount_codes',
        'instructors' => 'instructors',
        'instructor_contracts' => 'instructor_contracts',
        'instructor_earnings' => 'instructor_earnings',
        'join_us_requests' => 'join_us_requests',
        'notifications' => 'notifications',
        'otps' => 'otps',
        'products' => 'products',
        'reports' => 'reports',
        'orders' => 'orders',
        'order_addresses' => 'order_addresses',
        'order_items' => 'order_items',
        'reviews' => 'reviews',
        'support_tickets' => 'support_tickets',
        'support_ticket_messages' => 'support_ticket_messages',
        'transactions' => 'transactions',
        'users' => 'users',
        'user_addresses' => 'user_addresses',
        'user_certificates' => 'user_certificates',
        'user_profiles' => 'user_profiles',
    ];

    /**
     * Constructor: Establishes PDO connection.
     *
     * @throws PDOException
     */
    public function __construct()
    {
        $dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};port={$_ENV['DB_PORT']};charset=utf8mb4";

        try {
            $this->connection = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException("Connection failed: " . $e->getMessage());
        }
    }

    /**
     * Executes a prepared statement.
     *
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public function executeStatement(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetches data from the database.
     *
     * @param string $sql
     * @param array $params
     * @param bool $fetchAll
     * @return array|null
     */
    public function getData(string $sql, array $params = [], bool $fetchAll = false): array|null
    {
        $stmt = $this->executeStatement($sql, $params);
        $result = $fetchAll ? $stmt->fetchAll() : $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Inserts data and returns last insert ID.
     *
     * @param string $sql
     * @param array $params
     * @return int|null
     */
    public function insertData(string $sql, array $params): int|null
    {
        $stmt = $this->executeStatement($sql, $params);
        return $this->connection->lastInsertId() ?: null;
    }

    /**
     * Updates data and returns success status.
     *
     * @param string $sql
     * @param array $params
     * @return bool
     */
    public function updateData(string $sql, array $params): bool
    {
        $stmt = $this->executeStatement($sql, $params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes data and returns success status.
     *
     * @param string $sql
     * @param array $params
     * @return bool
     */
    public function deleteData(string $sql, array $params): bool
    {
        $stmt = $this->executeStatement($sql, $params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Begins a transaction.
     */
    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    /**
     * Commits the current transaction.
     */
    public function commit(): void
    {
        $this->connection->commit();
    }

    /**
     * Rolls back the current transaction.
     */
    public function rollback(): void
    {
        $this->connection->rollBack();
    }
}
