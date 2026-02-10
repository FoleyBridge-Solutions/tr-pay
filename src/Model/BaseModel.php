<?php

// src/Model/BaseModel.php

namespace Fbs\trpay\Model; // Adjust namespace as needed

use PDO;
use PDOException; // Import PDOException for error handling

/**
 * Abstract Base Model
 *
 * Provides common CRUD database operations using PDO.
 * Subclasses must define the table name and optionally the primary key.
 */
abstract class BaseModel
{
    /** @var PDO PDO database connection instance. */
    protected $pdo;

    /** @var string The database table name. Must be defined in subclasses. */
    protected $tableName;

    /** @var string The primary key column name for the table. Defaults to 'id'. */
    protected $primaryKey = 'id';

    /**
     * Constructor
     *
     * @param  PDO  $pdo  Database connection instance.
     *
     * @throws \InvalidArgumentException If tableName is not set in the subclass and isTableRequired is true.
     */
    public function __construct($pdo)
    {
        $this->pdo = $pdo;

        // Only check for tableName if the class actually needs it for CRUD operations
        if (empty($this->tableName) && $this->isTableRequired()) {
            throw new \InvalidArgumentException(get_class($this).' must have a $tableName specified.');
        }
    }

    /**
     * Determines if this model requires a table name.
     * Override in child classes that don't need a specific table.
     *
     * @return bool True if table name is required, false otherwise
     */
    protected function isTableRequired(): bool
    {
        return true;
    }

    /**
     * Fetch all records from the table.
     *
     * @param  array  $columns  Columns to select (default: '*').
     * @param  string|null  $orderBy  Optional ORDER BY clause (e.g., 'name ASC').
     * @return array Array of associative arrays representing table rows.
     *
     * @throws PDOException on database error.
     */
    public function findAll(array $columns = ['*'], ?string $orderBy = null): array
    {
        $cols = implode(', ', $columns);
        $sql = "SELECT {$cols} FROM {$this->tableName}";
        if ($orderBy) {
            // Basic validation/sanitization could be added here for $orderBy
            $sql .= " ORDER BY {$orderBy}";
        }
        $stmt = $this->pdo->query($sql); // Use query for simple SELECT without params

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single record by its primary key.
     *
     * @param  mixed  $id  The primary key value.
     * @param  array  $columns  Columns to select (default: '*').
     * @return array|false Associative array of the record data or false if not found.
     *
     * @throws PDOException on database error.
     */
    public function findById($id, array $columns = ['*'])
    {
        $cols = implode(', ', $columns);
        $sql = "SELECT {$cols} FROM {$this->tableName} WHERE {$this->primaryKey} = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':id', $id, $this->getPDOParamType($id));
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC); // fetch returns false if no row found
    }

    /**
     * Create a new record in the table.
     *
     * @param  array  $data  Associative array of column => value pairs.
     * @return string|false The last insert ID on success, false on failure.
     *
     * @throws PDOException on database error.
     */
    public function create(array $data)
    {
        if (empty($data)) {
            return false;
        }
        $columns = implode(', ', array_keys($data));
        $placeholders = ':'.implode(', :', array_keys($data));
        $sql = "INSERT INTO {$this->tableName} ({$columns}) VALUES ({$placeholders})";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, $data); // Use helper to bind values

        if ($stmt->execute()) {
            return $this->pdo->lastInsertId(); // Return the new ID
        }

        return false;
    }

    /**
     * Update an existing record by its primary key.
     *
     * @param  mixed  $id  The primary key value of the record to update.
     * @param  array  $data  Associative array of column => value pairs to update.
     * @return bool True on success (if rows were affected), false otherwise.
     *
     * @throws PDOException on database error.
     */
    public function update($id, array $data): bool
    {
        if (empty($data)) {
            return false; // Nothing to update
        }
        $setParts = [];
        foreach (array_keys($data) as $key) {
            $setParts[] = "{$key} = :{$key}";
        }
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE {$this->tableName} SET {$setClause} WHERE {$this->primaryKey} = :primary_key_id";

        $stmt = $this->pdo->prepare($sql);

        // Bind data values first
        $this->bindValues($stmt, $data);
        // Then bind the primary key
        $stmt->bindValue(':primary_key_id', $id, $this->getPDOParamType($id));

        $success = $stmt->execute();

        // Return true only if execute succeeded AND rows were changed
        return $success && ($stmt->rowCount() > 0);
    }

    /**
     * Delete a record by its primary key.
     *
     * @param  mixed  $id  The primary key value.
     * @return bool True on success (if a row was deleted), false otherwise.
     *
     * @throws PDOException on database error.
     */
    public function delete($id): bool
    {
        $sql = "DELETE FROM {$this->tableName} WHERE {$this->primaryKey} = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':id', $id, $this->getPDOParamType($id));
        $success = $stmt->execute();

        // Return true only if execute succeeded AND a row was deleted
        return $success && ($stmt->rowCount() > 0);
    }

    /**
     * Executes a raw SQL query with parameter binding (primarily for SELECT).
     * Use with caution, prefer specific methods when possible.
     *
     * @param  string  $sql  The SQL query string with placeholders.
     * @param  array  $params  Associative array of parameters to bind.
     * @param  int  $fetchMode  PDO Fetch mode (e.g., PDO::FETCH_ASSOC).
     * @return array|false Array of results or false on failure.
     *
     * @throws PDOException on database error.
     */
    public function query(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC)
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll($fetchMode);
    }

    /**
     * Executes a raw SQL statement (INSERT, UPDATE, DELETE).
     * Use with caution, prefer specific methods when possible.
     *
     * @param  string  $sql  The SQL statement string with placeholders.
     * @param  array  $params  Associative array of parameters to bind.
     * @return int|false Number of affected rows or false on failure.
     *
     * @throws PDOException on database error.
     */
    public function execute(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, $params);
        if ($stmt->execute()) {
            return $stmt->rowCount();
        }

        return false;
    }

    /**
     * Executes a SQL query and returns a single result row.
     *
     * @param  string  $sql  The SQL query string with placeholders.
     * @param  array  $params  Associative array of parameters to bind.
     * @param  int  $fetchMode  PDO Fetch mode (default: PDO::FETCH_ASSOC)
     * @return array|false Associative array of the record data or false if not found.
     *
     * @throws PDOException on database error.
     */
    public function fetch(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC)
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, $params);
        $stmt->execute();

        return $stmt->fetch($fetchMode);
    }

    /**
     * Helper method to determine PDO::PARAM_* type based on value.
     *
     * @param  mixed  $value
     * @return int PDO::PARAM_* constant
     */
    protected function getPDOParamType($value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        if (is_null($value)) {
            return PDO::PARAM_NULL;
        }

        // Default to string
        return PDO::PARAM_STR;
    }

    /**
     * Helper method to bind multiple values to a prepared statement.
     *
     * @param  \PDOStatement  $stmt  The prepared statement.
     * @param  array  $data  Associative array of placeholder => value.
     */
    protected function bindValues(\PDOStatement $stmt, array $data): void
    {
        foreach ($data as $key => $value) {
            // Prefix with colon if not already present for named placeholders
            $placeholder = (strpos($key, ':') === 0) ? $key : ':'.$key;
            $stmt->bindValue($placeholder, $value, $this->getPDOParamType($value));
        }
    }
}
