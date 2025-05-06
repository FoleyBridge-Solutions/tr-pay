<?php
// src/Controller/EmployeeController.php

namespace Fbs\trpay\Controller;

use Fbs\trpay\Model\Employee;
use Fbs\trpay\View\View;
use Fbs\trpay\Auth\Auth;
use PDO;

/**
 * EmployeeController handles the main employee functionality
 */
class EmployeeController {
    private PDO $pdo;
    private Employee $employeeModel;

    /**
     * Initialize the EmployeeController with a PDO instance
     * and set up the necessary models
     * 
     * @param PDO $pdo Database connection instance
     */

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->employeeModel = new Employee($pdo);

        if (!Auth::check()) {
            // Redirect to login page or handle unauthorized access
            header('Location: login.php');
            exit;
        }
    }
    /**
     * Display the homepage
     * 
     * Checks for user authentication and renders the home view
     * If user is not authenticated, redirects to login page
     * 
     * @return void
     */
    public function index() {
        // Fetch employees from the database
        $employeeModel = new Employee($this->pdo);
        $employees = $employeeModel->getAllEmployees();
    
        // Prepare data for the simpleTable view
        $data['card']['title'] = 'Employee List';
        $data['table']['header_rows'] = [
            "First Name",
            "Last Name",
            "Benefits",
            "Actions"
        ];
        $data['table']['body_rows'] = [];
    
        foreach ($employees as $employee) {
            $data['table']['body_rows'][] = [
                $employee['first_name'], // Display first_name
                $employee['last_name'],  // Display last_name
                $employee['benefits'],
                '<a href="?page=edit_employee&id=' . $employee['staff_KEY'] . '" class="btn btn-primary btn-sm">Edit</a> '
            ];
        }
    
        // Pass the employee data to the view
        $view = new View();
        $view->render('simpleTable', $data);
    }

    public function onboard_employee() {
        $employeeModel = new Employee($this->pdo);
        
    }

    /**
     * Edit an employee's details
     * 
     * Fetches the employee data by ID and renders the edit view
     * 
     * @param int $id Employee ID
     * @return void
     */
    public function edit_employee($id) {
        // Fetch the employee data by ID
        $employee = $this->employeeModel->getEmployeeById($id);
        if (!$employee) {
            // Handle employee not found (e.g., redirect or show error)
            header('Location: ?page=all_employees');
            exit;
        }

        // Pass the employee data to the view
        $view = new View();
        $data['employee'] = $employee;
        $view->render('employee/edit_employee', $data);
    }

    /**
     * Sync employees with the external practice cs system
     * 
     * This method fetches employees from the database and syncs them
     * with the external practice cs system using MSSQL queries
     * 
     * @return void
     */
    public function sync_employees() {
        // Logic to sync employees with the external practice cs system
        //MSSQL query to fetch employees from the database and sync with the external system

        // This is a placeholder for the actual implementation
        $employeeModel = new Employee($this->pdo);
        $sync = $employeeModel->sync() ?? ['status' => 'error', 'message' => 'Sync failed'];

        referWithAlert($sync['message'], $sync['status'], '?page=all_employees');
    }

    public function updateField($data)
    {
        $id = $data['id'] ?? null;
        $field = $data['field'] ?? null;
        $value = $data['value'] ?? null;

        if ($id === null || $field === null || $value === null) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Missing parameters']);
            return;
        }

        // Whitelist of allowed fields
        $allowedFields = ['salary','hours','benefits']; // Add other allowed fields here

        if (!in_array($field, $allowedFields)) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Invalid field']);
            return;
        }

        $result = $this->employeeModel->updateField($id, $field, $value);
        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Update failed']);
        }
    }

}