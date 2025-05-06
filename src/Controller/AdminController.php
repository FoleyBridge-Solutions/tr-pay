<?php
// src/Controller/AdminController.php

namespace Fbs\trpay\Controller;

use Fbs\trpay\View\View;
use Fbs\trpay\Auth\Auth;
use Fbs\trpay\Model\User;
use PDO;

/**
 * AdminController handles the main employee functionality
 */
class AdminController {
    private PDO $pdo;
    private Auth $auth;
    /**
     * Initialize the AdminController with a PDO instance
     * and set up the necessary models
     * 
     * @param PDO $pdo Database connection instance
     */

    public function __construct($pdo) {
        $this->pdo = $pdo;
        if (!Auth::check()) {
            // Redirect to login page or handle unauthorized access
            header('Location: login.php');
            exit;
        }
        $this->auth = new Auth($pdo);
    }
    
    public function users() {
        if (!$this->auth->isRole('admin')) {
            // Redirect to login page or handle unauthorized access
            referWithAlert('User Not Authorized');
            exit;
        }

        $view = new View();
        $users = $this->auth->getUsers();
        $data['card']['title'] = 'PCSRE Users';
        $data['table']['header_rows'] = [
            'User ID',
            'Username',
            'Role'
        ];

        $data['action'] = [
            'title' => 'Add User',
            'page' => 'admin_add_user',
        ];

        foreach ($users as $user) {
            $data['table']['body_rows'][] = [
                $user['user_id'],
                $user['user_name'],
                $user['user_role']
            ];
        }
        $view->render('simpleTable', $data);
    }

    public function add_user() {
        if (!$this->auth->isRole('admin')) {
            // Redirect to login page or handle unauthorized access
            referWithAlert('User Not Authorized');
            exit;
        }
        $view = new View();
        $view->render('admin/user/add_user');
    }
    public function edit_user($user_id = null) {
        if ($user_id === null) {
            $user_id = $_SESSION['user_id'];
        }
        // If user id is not session user id, check if user is admin
        if ($user_id != $_SESSION['user_id'] && !$this->auth->isRole('admin')) {
            referWithAlert('User Not Authorized');
            exit;
        }

        // Fetch the user data by ID
        $user = $this->auth->getUser($user_id);

        $view = new View();
        $view->render('admin/user/edit_user', $user);
    }

    public function addUser() {
        if (!$this->auth->isRole('admin')) {
            // Redirect to login page or handle unauthorized access
            referWithAlert('User Not Authorized');
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $userName = $data['user_name'];
        $email = $data['email'];
        $password = $data['password']; // Pass the plain-text password

        $result = $this->auth->createUser($userName, $email, $password);
        if ($result) {
            echo json_encode(['success' => 'User added successfully']);
        } else {
            http_response_code(500);
            error_log('Failed to add user: ' . json_encode($data));
            echo json_encode(['error' => 'Failed to add user']);
        }
    }

    public function updateUserField() {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        $field = $data['field'] ?? null;
        $value = $data['value'] ?? null;

        if ($id === null || $field === null || $value === null) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Missing parameters']);
            return;
        }

        // Whitelist of allowed fields
        $allowedFields = ['user_name','password','email']; // Add other allowed fields here

        if (!in_array($field, $allowedFields)) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => 'Invalid field']);
            return;
        }
        if ($field == 'password') {
            // Use the auth class to update the password
            $result = $this->auth->updatePassword($id, $value);
            return $result;
        } else {
            // Use the user model to update other fields
            $result = $this->auth->updateUserField($id, $field, $value);
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update user field']);
            }
        }
    }
}