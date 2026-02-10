<?php

// src/Model/Employee.php

namespace Fbs\trpay\Model;

use PDO;

/**
 * Employee Model
 *
 * Handles all client-related database operations and business logic
 */
class Employee
{
    /** @var PDO */
    private $pdo;

    /**
     * Constructor
     *
     * @param  PDO  $pdo  Database connection instance
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Fetch all employees from the database
     *
     * @return array Array of employee data
     */
    public function getAllEmployees()
    {
        $stmt = $this->pdo->prepare('SELECT * FROM employees');
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single employee by ID
     *
     * @param  int  $id  Employee ID
     * @return array|null Employee data or null if not found
     */
    public function getEmployeeById($id)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM employees WHERE staff_KEY = :id');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Add a new employee to the database
     *
     * @param  array  $data  Employee data
     * @return bool True on success, false on failure
     */
    public function addEmployee($data)
    {
        $stmt = $this->pdo->prepare('INSERT INTO employees (name, position, department) VALUES (:name, :position, :department)');
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':position', $data['position']);
        $stmt->bindParam(':department', $data['department']);

        return $stmt->execute();
    }

    /**
     * Update a specific field for an employee
     *
     * @param  int  $id  Employee ID
     * @param  string  $field  Field name
     * @param  mixed  $value  New value
     * @return bool True on success, false on failure
     */
    public function updateField($id, $field, $value)
    {
        $sql = "UPDATE employees SET $field = :value WHERE staff_KEY = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':value', $value);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Delete an employee from the database
     *
     * @param  int  $id  Employee ID
     * @return bool True on success, false on failure
     */
    public function deleteEmployee($id)
    {
        $stmt = $this->pdo->prepare('DELETE FROM employees WHERE id = :id');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Sync employees with an external system (placeholder)
     *
     * @return array
     */
    public function sync()
    {
        try {
            // MSSQL database connection details
            $serverName = 'practicecs.bpc.local,65454';
            $databaseName = 'CSP_345844_BurkhartPeterson';
            $username = 'graphana';
            $password = 'Tw3nt05!';

            // Connection Options
            $connectionOptions = [
                'Database' => $databaseName,
                'Uid' => $username,
                'PWD' => $password,
                'CharacterSet' => 'UTF-8',
                'ReturnDatesAsStrings' => true,
                'LoginTimeout' => 30,
                'ConnectionPooling' => 0,
                'TrustServerCertificate' => true, // For dev/test with self-signed certs
            ];

            // Establish the MSSQL connection
            $conn = sqlsrv_connect($serverName, $connectionOptions);
            if ($conn === false) {
                throw new \Exception('MSSQL Connection Failed: '.print_r(sqlsrv_errors(), true));
            }

            // --- Option 1:  Fetch department_KEY, office_KEY, staff_status_KEY, and staff_level_KEY (Simplest, but requires matching IDs) ---
            $tsql = 'SELECT staff_KEY, first_name, last_name, staff_status_KEY, staff_level_KEY, office_KEY, department_KEY 
                     FROM dbo.Staff 
                     WHERE staff_status_KEY != 2';  // Changed IS NOT 2 to != 2

            // --- Option 2: Fetch department description (Requires JOIN, more complex) ---
            /*
            $tsql = "SELECT s.staff_KEY, s.first_name, s.last_name, d.description as department_name
                    FROM dbo.Staff s
                    LEFT JOIN dbo.Department d ON s.department_KEY = d.department_KEY"; // Assuming a Department table
            */

            $this->pdo->beginTransaction();

            // Create array to store all valid staff_KEYs from source
            $validStaffKeys = [];

            // Modify the query to store results first
            $getResults = sqlsrv_query($conn, $tsql);
            if ($getResults === false) {
                throw new \Exception('MSSQL Query Failed: '.print_r(sqlsrv_errors(), true));
            }

            // Prepare statements
            $selectStmt = $this->pdo->prepare('SELECT staff_KEY FROM employees WHERE staff_KEY = :staff_key');
            $updateStmt = $this->pdo->prepare('
                UPDATE employees
                SET first_name = :first_name,
                    last_name = :last_name,
                    staff_status_KEY = :staff_status_key,
                    staff_level_KEY = :staff_level_key,
                    office_KEY = :office_key,
                    department_KEY = :department_key
                WHERE staff_KEY = :staff_key
            ');
            $insertStmt = $this->pdo->prepare('
                INSERT INTO employees (staff_KEY, first_name, last_name,  staff_status_KEY, staff_level_KEY, office_KEY, department_KEY)
                VALUES (:staff_key, :first_name, :last_name, :staff_status_key, :staff_level_key, :office_key, :department_key)
            ');

            while ($row = sqlsrv_fetch_array($getResults, SQLSRV_FETCH_ASSOC)) {
                // Store valid staff key
                $validStaffKeys[] = $row['staff_KEY'];

                $selectStmt->bindParam(':staff_key', $row['staff_KEY'], PDO::PARAM_INT); // Use staff_KEY
                $selectStmt->execute();
                $existingEmployee = $selectStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingEmployee) {
                    // Update
                    $updateStmt->bindParam(':staff_key', $row['staff_KEY'], PDO::PARAM_INT);
                    $updateStmt->bindParam(':first_name', $row['first_name']);
                    $updateStmt->bindParam(':last_name', $row['last_name']);
                    $updateStmt->bindParam(':staff_status_key', $row['staff_status_KEY'], PDO::PARAM_INT);
                    $updateStmt->bindParam(':staff_level_key', $row['staff_level_KEY'], PDO::PARAM_INT);
                    $updateStmt->bindParam(':office_key', $row['office_KEY'], PDO::PARAM_INT);
                    $updateStmt->bindParam(':department_key', $row['department_KEY'], PDO::PARAM_INT);

                    $updateStmt->execute();
                } else {
                    // Insert
                    $insertStmt->bindParam(':staff_key', $row['staff_KEY'], PDO::PARAM_INT);
                    $insertStmt->bindParam(':first_name', $row['first_name']);
                    $insertStmt->bindParam(':last_name', $row['last_name']);
                    $insertStmt->bindParam(':staff_status_key', $row['staff_status_KEY'], PDO::PARAM_INT);
                    $insertStmt->bindParam(':staff_level_key', $row['staff_level_KEY'], PDO::PARAM_INT);
                    $insertStmt->bindParam(':office_key', $row['office_KEY'], PDO::PARAM_INT);
                    $insertStmt->bindParam(':department_key', $row['department_KEY'], PDO::PARAM_INT);
                    $insertStmt->execute();
                }
            }

            // Delete employees that don't exist in source anymore
            $placeholders = str_repeat('?,', count($validStaffKeys) - 1).'?';
            $deleteStmt = $this->pdo->prepare("
                DELETE FROM employees 
                WHERE staff_KEY NOT IN ($placeholders)
            ");
            $deleteStmt->execute($validStaffKeys);

            $this->pdo->commit();
            sqlsrv_free_stmt($getResults);
            sqlsrv_close($conn);

            return [
                'status' => 'success',
                'message' => 'Employees synced successfully.',
            ];

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'status' => 'error',
                'message' => 'Failed to sync employees: '.$e->getMessage(),
            ];
        }
    }
}
