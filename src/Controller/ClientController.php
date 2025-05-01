<?php
// src/Controller/ClientController.php

namespace Twetech\Nestogy\Controller;

use Twetech\Nestogy\Model\Client;
use Twetech\Nestogy\Model\Contact;
use Twetech\Nestogy\Model\Accounting;
use Twetech\Nestogy\View\View;
use Twetech\Nestogy\Auth\Auth;

/**
 * Controller handling client-related operations
 */
class ClientController {
    private $pdo;
    private $clientModel;
    private $accountingModel;

    /**
     * Initialize the ClientController with database connection
     *
     * @param \PDO $pdo Database connection instance
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        $this->clientModel = new Client($this->pdo);
        $this->accountingModel = new Accounting($this->pdo);

        if (!Auth::check()) {
            // Redirect to login page or handle unauthorized access
            header('Location: login.php');
            exit;
        }
    }

    /**
     * Display list of all clients with their financial information
     *
     * @return void
     */
    public function index() {
        // Check if this is a DataTables AJAX request
        if (isset($_GET['draw'])) {
            $this->getClientsJson();
            return;
        }

        $view = new View();
        $view->render('clients', []);
    }

    /**
     * Handle DataTables AJAX request for clients data
     * 
     * @return void
     */
    private function getClientsJson() {
        // Get DataTables parameters
        $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
        $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
        $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
        $search = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
        $order_column = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 0;
        $order_dir = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'DESC';

        // Get total count and filtered data from model
        $total = $this->clientModel->getTotalClients();
        $filtered_count = $this->clientModel->getFilteredClientsCount($search);
        $clients = $this->clientModel->getFilteredClients($start, $length, $search, $order_column, $order_dir);

        // Add financial data
        foreach ($clients as &$client) {
            $client['client_past_due_amount'] = $this->accountingModel->getPastDueAmount($client['client_id']);
            $client['client_payments'] = $this->accountingModel->getClientPaidAmount($client['client_id']);
            $client['client_recurring_monthly'] = $this->accountingModel->getMonthlySubscriptionAmount($client['client_id']);
        }

        // Format response for DataTables
        $response = [
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered_count,
            'data' => $clients
        ];

        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * Display detailed information for a specific client
     *
     * @param int $client_id The ID of the client to display
     * @return void
     */
    public function show($client_id) {
        $view = new View();
        $auth = new Auth($this->pdo);

        $this->clientAccessed($client_id);
        
        // If client_id is not an integer, display an error message
        if (!is_numeric($client_id)) {
            $view->error([
                'title' => 'Invalid Client ID',
                'message' => 'The client ID must be an integer.'
            ]);
            return;
        }


        // Get information for client overview screen
        $clientModel = new Client($this->pdo);
        $client = $clientModel->getClientOverview($client_id);

        $contactModel = new Contact($this->pdo);
        $client['client_contacts'] = $contactModel->getContacts($client_id);

        $data = [
            'client' => $client,
            'client_header' => $clientModel->getClientHeader($client_id)['client_header'],
            'return_page' => [
                'name' => 'Clients',
                'link' => 'clients'
            ]
        ];

        $view->render('client', $data, true);
    }

    /**
     * Display contacts for a specific client
     *
     * @param int $client_id The ID of the client whose contacts to display
     * @return void
     */
    public function showContacts($client_id) {
        $contactModel = new Contact($this->pdo);
        $clientModel = new Client($this->pdo);
        $auth = new Auth($this->pdo);
        $view = new View();

        $rawContacts = $contactModel->getContacts($client_id);

        $contacts = [];
        foreach ($rawContacts as $contact) {
            $contacts[] = [
                '<a href="#" class="dropdown-item loadModalContentBtn" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="client_contact_edit_modal.php?contact_id=' . $contact['contact_id'] . '">
                    ' . $contact['contact_name'] . '
                </a>',
                $contact['contact_email'],
                $contact['contact_phone'],
                $contact['contact_mobile'],
                $contact['contact_primary'] ? 'Yes' : 'No',
                '<a href="#" class="dropdown-item loadModalContentBtn" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="client_contact_edit_modal.php?contact_id=' . $contact['contact_id'] . '">
                    <i class="fa fa-pencil"></i>
                </a>'
            ];
        }
        $data = [
            'card' => [
                'title' => 'Contacts'
            ],
            'client_header' => $clientModel->getClientHeader($client_id)['client_header'],
            'table' => [
                'header_rows' => ['Name', 'Email', 'Phone', 'Mobile', 'Primary', 'Actions'],
                'body_rows' => $contacts
            ],
            'return_page' => [
                'name' => 'Clients',
                'link' => 'clients'
            ],
            'action' => [
                'title' => 'Add Contact',
                'modal' => 'client_contact_add_modal.php?client_id='.$client_id
            ]
        ];
        $view->render('simpleTable', $data, true);
    }

    /**
     * Display locations for a specific client
     *
     * @param int $client_id The ID of the client whose locations to display
     * @return void
     */
    public function showLocations($client_id) {
        $clientModel = new Client($this->pdo);
        $auth = new Auth($this->pdo);
        $view = new View();

        $rawLocations = $clientModel->getClientLocations($client_id);

        $locations = [];
        foreach ($rawLocations as $location) {
            $locationAdress = $location['location_address'] . ', ' . $location['location_city'] . ', ' . $location['location_state'] . ' ' . $location['location_zip'];
            $locations[] = [
                $location['location_name'],
                $locationAdress,
                $location['location_phone'],
                $location['location_hours'],
                '<a href="#" class="dropdown-item loadModalContentBtn" data-bs-toggle="modal" data-bs-target="#dynamicModal" data-modal-file="client_location_edit_modal.php?location_id=' . $location['location_id'] . '">
                    <i class="fa fa-pencil"></i>
                </a>'
            ];
        }
        
        $data = [
            'card' => [
                'title' => 'Locations'
            ],
            'client_header' => $clientModel->getClientHeader($client_id)['client_header'],
            'table' => [
                'header_rows' => ['Location Name', 'Address', 'Phone', 'Hours', 'Actions'],
                'body_rows' => $locations
            ],
            'return_page' => [
                'name' => 'Clients',
                'link' => 'clients'
            ],
            'action' => [
                'title' => 'Add Location',
                'modal' => 'client_location_add_modal.php?client_id='.$client_id
            ]
        ];

        $view->render('simpleTable', $data, true);
    }

    /**
     * Record that a client's information was accessed
     *
     * @param int $client_id The ID of the client being accessed
     * @return void
     */
    public function clientAccessed($client_id) {
        $clientModel = new Client($this->pdo);
        $clientModel->clientAccessed($client_id);
    }
}
