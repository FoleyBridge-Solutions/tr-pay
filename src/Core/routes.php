<?php
// src/Core/routes.php

// Main routes for the application
$this->add('home', 'HomeController');
$this->add('all_employees', 'EmployeeController');
$this->add('sync_employees', 'EmployeeController', 'sync_employees');
$this->add('edit_employee', 'EmployeeController', 'edit_employee', ['id']);
$this->add('onboard_employee', 'EmployeeController', 'onboard_employee');

// Admin routes
$this->add('admin_users', 'AdminController', 'users');
$this->add('admin_add_user','AdminController','add_user', ['id']);
$this->add('edit_user','AdminController','edit_user', ['id']);
$this->add('user_settings','AdminController','edit_user');


// API Endpoints
$this->addApi('update_employee_field', 'EmployeeController', 'updateField');
$this->addApi('update_user_field','AdminController','updateUserField');
$this->addApi('add_user','AdminController','addUser');
