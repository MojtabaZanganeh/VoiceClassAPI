<?php
namespace Classes\Base;

/**
 * Database Class
 *
 * This class is responsible for handling database connections and operations
 * using MySQLi, including executing SQL statements, transactions, and managing
 * database interactions.
 * 
 * @package Classes\Base
 */
class Database
{

    /**
     * MySQLi connection object.
     *
     * @var \mysqli
     */
    private $connection;

    /**
     * Database Tables Array
     *
     * This variable contains an array of different database table names that are used for various database 
     * operations such as insert, update, delete, etc. Each entry in this array is a key-value pair where the key 
     * represents the logical name of the table and the value is the actual table name in the database.
     *
     * @var array
     */
    public $table = [
        'book_details' => 'book_details',
        'cart_items' => 'cart_items',
        'categories' => 'categories',
        'chapters' => 'chapters',
        'chapter_lessons' => 'chapter_lessons',
        'course_details' => 'course_details',
        'course_students' => 'course_students',
        'discount_codes' => 'discount_codes',
        'instructors' => 'instructors',
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
     * Database constructor.
     *
     * Initializes a new connection to the MySQL database using MySQLi.
     * Throws an exception if the connection fails.
     *
     * @throws \Exception If the connection fails.
     */
    public function __construct()
    {
        $this->connection = new \mysqli(
            $_ENV['DB_HOST'],
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            $_ENV['DB_NAME'],
            $_ENV['DB_PORT']
        );

        if ($this->connection->connect_error) {
            throw new \Exception('Connection Failed: ' . $this->connection->connect_error);
        }

        $this->connection->set_charset("utf8mb4");
    }

    /**
     * Database destructor.
     *
     * Closes the database connection when the object is destroyed.
     */
    public function __destruct()
    {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    /**
     * Executes a prepared SQL statement.
     *
     * Prepares the provided SQL query and binds the given parameters to it.
     * Executes the query and returns the prepared statement object.
     *
     * @param string $sql The SQL query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @param string $types The types of the parameters (optional).
     * @return \mysqli_stmt The prepared statement object.
     * @throws \Exception If there is an error preparing the statement.
     */
    public function executeStatement($sql, $params = [], $types = '')
    {
        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            throw new \Exception("Error in preparing Statement: " . $this->connection->error);
        }

        if (!empty($params)) {
            if (empty($types)) {
                $types = str_repeat('s', count($params));
            }
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * Returns the ID generated in the last INSERT query.
     *
     * @return int The insert ID of the last executed query.
     */
    public function get_insert_id()
    {
        return $this->connection->insert_id;
    }

    /**
     * Fetches data from the database.
     *
     * Executes a SQL query and returns the result as an associative array.
     * If `$fetch_all` is true, returns all rows; otherwise, returns the first row.
     *
     * @param string $sql The SQL query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @param bool $fetch_all Whether to fetch all rows or just the first row.
     * @return array|bool|null Returns an array of results, a single row, or null if no data is found.
     */
    public function getData(string $sql, array $params, bool $fetch_all = false): array|bool|null
    {
        $stmt = $this->executeStatement($sql, $params);
        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows > 0) {
            return $fetch_all ? $result->fetch_all(MYSQLI_ASSOC) : $result->fetch_assoc();
        }

        return null;
    }

    /**
     * Inserts data into the database.
     *
     * Executes an INSERT query and returns the ID of the newly inserted row.
     *
     * @param string $sql The SQL query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @return int|null Returns the insert ID if successful, otherwise null.
     */
    public function insertData(string $sql, array $params): int|null
    {
        $stmt = $this->executeStatement($sql, $params);
        $result = $stmt->affected_rows !== -1 ? $this->get_insert_id() : null;
        $stmt->close();
        return $result;
    }

    /**
     * Updates data in the database.
     *
     * Executes an UPDATE query and returns true if the update was successful.
     *
     * @param string $sql The SQL query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @return bool Returns true if the update was successful, otherwise false.
     */
    public function updateData(string $sql, array $params): bool
    {
        $stmt = $this->executeStatement($sql, $params);
        $result = $stmt->affected_rows !== -1;
        $stmt->close();
        return $result;
    }

    /**
     * Delete data from the database.
     *
     * Executes an DELETE query and returns true if the delete was successful.
     *
     * @param string $sql The SQL query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @return bool Returns true if the delete was successful, otherwise false.
     */
    public function deleteData(string $sql, array $params): bool
    {
        $stmt = $this->executeStatement($sql, $params);
        $result = $stmt->affected_rows !== -1;
        $stmt->close();

        return $result;
    }

    /**
     * Begins a new transaction.
     *
     * Starts a database transaction.
     */
    public function beginTransaction()
    {
        $this->connection->begin_transaction();
    }

    /**
     * Commits the current transaction.
     *
     * Finalizes the transaction and applies all changes.
     */
    public function commit()
    {
        $this->connection->commit();
    }

    /**
     * Rolls back the current transaction.
     *
     * Reverts any changes made during the transaction.
     */
    public function rollback()
    {
        $this->connection->rollback();
    }
}