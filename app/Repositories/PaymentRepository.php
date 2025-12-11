<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PaymentRepository
 * 
 * Handles complex payment-related database queries
 * 
 * ⚠️⚠️⚠️ CRITICAL: This repository queries the READ-ONLY Microsoft SQL Server
 * database owned by another application. All queries MUST use
 * DB::connection('sqlsrv') to explicitly target the SQL Server database!
 */
class PaymentRepository
{
    /**
     * Get client data by last 4 of SSN/EIN and last name
     * 
     * @param string $last4 Last 4 digits of SSN/EIN
     * @param string $lastName Last name on account
     * @return array|false
     */
    public function getClientByTaxIdAndName(string $last4, string $lastName)
    {
        $last4 = trim($last4);
        $lastName = trim($lastName);

        $sql = "
            SELECT DISTINCT
                C.client_KEY,
                C.client_id,
                C.description AS client_name,
                C.individual_first_name,
                C.individual_last_name,
                C.federal_tin
            FROM
                Client C
            WHERE
                RIGHT(C.federal_tin, 4) = ?
                AND C.individual_last_name = ?
            ORDER BY
                C.description
        ";

        // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
        $clients = DB::connection('sqlsrv')->select($sql, [$last4, $lastName]);

        if (count($clients) === 0) {
            return false;
        }

        // Convert stdClass objects to arrays
        $clientsArray = array_map(function($client) {
            return (array) $client;
        }, $clients);

        // Note: Currently only returns clients that match both last 4 EIN digits AND last name
        // In the future, this could be enhanced to find related clients through business relationships,
        // shared addresses, or other grouping mechanisms if the database supports it

        // Return structure matching original format
        return [
            'clients' => $clientsArray,
            'client_KEY' => count($clientsArray) === 1 ? $clientsArray[0]['client_KEY'] : null,
            'client_id' => count($clientsArray) === 1 ? $clientsArray[0]['client_id'] : null,
            'client_name' => count($clientsArray) === 1 ? $clientsArray[0]['client_name'] : null
        ];
    }

    /**
     * Get the current AR balance and details for a client
     * 
     * @param int|null $clientKey
     * @param string|null $clientId
     * @return array
     */
    public function getClientBalance($clientKey = null, $clientId = null): array
    {
        // First get client info
        $clientInfoSql = "SELECT client_KEY, client_id, description FROM Client WHERE " . 
                        ($clientKey !== null ? "client_KEY = ?" : "client_id = ?");
        $params = [$clientKey !== null ? $clientKey : $clientId];
        
        // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
        $clientInfo = DB::connection('sqlsrv')->selectOne($clientInfoSql, $params);
        
        if (!$clientInfo) {
            return [
                'client_id' => $clientId ?? 'Unknown',
                'client_name' => 'Unknown Client',
                'balance' => 0.00
            ];
        }

        // Use a proper approach with CTEs to calculate balance
        $sql = "
            -- Calculate the sum of applied amounts for each ledger entry
            WITH AppliedTo AS (
                SELECT 
                    to__ledger_entry_KEY,
                    SUM(applied_amount) AS applied_amount_to
                FROM 
                    Ledger_Entry_Application
                GROUP BY 
                    to__ledger_entry_KEY
            ),
            AppliedFrom AS (
                SELECT 
                    from__ledger_entry_KEY,
                    SUM(applied_amount) AS applied_amount_from
                FROM 
                    Ledger_Entry_Application
                GROUP BY 
                    from__ledger_entry_KEY
            )
            -- Main query to calculate open balance
            SELECT
                C.client_id,
                C.description AS ClientName,
                SUM((
                    LE.amount + 
                    COALESCE(AT.applied_amount_to, 0.00) - 
                    COALESCE(AF.applied_amount_from, 0.00)
                ) * LET.normal_sign) AS CurrentARBalance
            FROM
                Client C
            JOIN
                Ledger_Entry LE ON C.client_KEY = LE.client_KEY AND LE.posted__staff_KEY IS NOT NULL
            JOIN
                Ledger_Entry_Type LET ON LE.ledger_entry_type_KEY = LET.ledger_entry_type_KEY
            LEFT JOIN
                AppliedTo AT ON LE.ledger_entry_KEY = AT.to__ledger_entry_KEY
            LEFT JOIN
                AppliedFrom AF ON LE.ledger_entry_KEY = AF.from__ledger_entry_KEY
            WHERE
                C.client_KEY = ?
            GROUP BY
                C.client_id,
                C.description
        ";
        
        // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
        $result = DB::connection('sqlsrv')->selectOne($sql, [$clientInfo->client_KEY]);
        
        if (!$result) {
            return [
                'client_id' => $clientInfo->client_id,
                'client_name' => $clientInfo->description,
                'balance' => 0.00
            ];
        }
        
        return [
            'client_id' => $result->client_id,
            'client_name' => $result->ClientName,
            'balance' => (float)$result->CurrentARBalance
        ];
    }

    /**
     * Get all open invoices for a client
     * 
     * @param int $clientKey
     * @return array
     */
    public function getClientOpenInvoices(int $clientKey): array
    {
        $sql = "
            -- Calculate the sum of applied amounts for each ledger entry
            WITH AppliedTo AS (
                SELECT 
                    to__ledger_entry_KEY,
                    SUM(applied_amount) AS applied_amount_to
                FROM 
                    Ledger_Entry_Application
                GROUP BY 
                    to__ledger_entry_KEY
            ),
            AppliedFrom AS (
                SELECT 
                    from__ledger_entry_KEY,
                    SUM(applied_amount) AS applied_amount_from
                FROM 
                    Ledger_Entry_Application
                GROUP BY 
                    from__ledger_entry_KEY
            )
            -- Main query to get open invoices
            SELECT
                LE.ledger_entry_KEY,
                LE.entry_number AS invoice_number,
                LE.entry_date AS invoice_date,
                I.due_date,
                LETP.description AS type,
                (LE.amount + 
                 COALESCE(AT.applied_amount_to, 0.00) - 
                 COALESCE(AF.applied_amount_from, 0.00)) * LETP.normal_sign AS open_amount,
                CONCAT('Invoice #', LE.entry_number) AS description
            FROM 
                Ledger_Entry AS LE
            JOIN
                Ledger_Entry_Type AS LETP ON LE.ledger_entry_type_KEY = LETP.ledger_entry_type_KEY
            LEFT JOIN
                Invoice AS I ON LE.ledger_entry_KEY = I.ledger_entry_KEY
            LEFT JOIN
                AppliedTo AT ON LE.ledger_entry_KEY = AT.to__ledger_entry_KEY
            LEFT JOIN
                AppliedFrom AF ON LE.ledger_entry_KEY = AF.from__ledger_entry_KEY
            WHERE 
                LE.client_KEY = ?
                AND LE.posted__staff_KEY IS NOT NULL
                AND (LE.amount + 
                    COALESCE(AT.applied_amount_to, 0.00) - 
                    COALESCE(AF.applied_amount_from, 0.00)) <> 0
            ORDER BY
                LE.entry_date DESC
        ";

        // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
        $results = DB::connection('sqlsrv')->select($sql, [$clientKey]);
        
        $invoices = [];
        foreach ($results as $row) {
            // Format dates for display
            $invoice = (array) $row;
            
            if ($invoice['invoice_date'] instanceof \DateTime) {
                $invoice['invoice_date'] = $invoice['invoice_date']->format('m/d/Y');
            } elseif (is_string($invoice['invoice_date'])) {
                $date = date_create($invoice['invoice_date']);
                $invoice['invoice_date'] = $date ? $date->format('m/d/Y') : $invoice['invoice_date'];
            } else {
                $invoice['invoice_date'] = 'N/A';
            }
            
            if ($invoice['due_date'] instanceof \DateTime) {
                $invoice['due_date'] = $invoice['due_date']->format('m/d/Y');
            } elseif (is_string($invoice['due_date'])) {
                $date = date_create($invoice['due_date']);
                $invoice['due_date'] = $date ? $date->format('m/d/Y') : $invoice['due_date'];
            } else {
                $invoice['due_date'] = 'N/A';
            }
            
            // Format amount as number with 2 decimals
            $invoice['open_amount'] = number_format((float)$invoice['open_amount'], 2, '.', '');
            
            $invoices[] = $invoice;
        }
        
        return $invoices;
    }

    /**
     * Get open invoices for a client and their related group members
     * 
     * @param int $clientKey The primary client key
     * @param array $clientInfo The client info array (containing client_name, etc.)
     * @return array ['openInvoices' => [], 'totalBalance' => float]
     */
    public function getGroupedInvoicesForClient(int $clientKey, array $clientInfo): array
    {
        $openInvoices = [];
        $totalBalance = 0;

        // Find all clients in the same "Group Name" custom field group
        // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
        $clientGroup = DB::connection('sqlsrv')->selectOne("
            SELECT cv.custom_value as group_name
            FROM Custom_Value cv
            WHERE cv.custom_field_KEY = 122
            AND cv.table_information_KEY = 93
            AND cv.row_KEY = ?
        ", [$clientKey]);

        if ($clientGroup && !empty($clientGroup->group_name)) {
            // Find ALL clients with the same group name
            // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
            $groupClients = DB::connection('sqlsrv')->select("
                SELECT c.client_KEY, c.client_id, c.description as client_name
                FROM Client c
                INNER JOIN Custom_Value cv ON c.client_KEY = cv.row_KEY
                WHERE cv.custom_field_KEY = 122
                AND cv.table_information_KEY = 93
                AND cv.custom_value = ?
                ORDER BY CASE WHEN c.client_KEY = ? THEN 0 ELSE 1 END, c.description ASC
            ", [$clientGroup->group_name, $clientKey]);

            Log::info('Client Group by Custom Field', [
                'groupName' => $clientGroup->group_name,
                'groupClientsCount' => count($groupClients),
                'groupClients' => $groupClients
            ]);
        } else {
            // No group assigned - only show this client
            // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
            $groupClients = DB::connection('sqlsrv')->select("
                SELECT c.client_KEY, c.client_id, c.description as client_name
                FROM Client c
                WHERE c.client_KEY = ?
            ", [$clientKey]);

            Log::info('Client has no group assigned', [
                'clientKey' => $clientKey,
                'groupClientsCount' => count($groupClients)
            ]);
        }

        // Load invoices for all clients in the group
        foreach ($groupClients as $clientData) {
            $currentClientKey = $clientData->client_KEY;
            $isPrimaryClient = ($currentClientKey === $clientKey);

            $balanceInfo = $this->getClientBalance($currentClientKey);
            $totalBalance += (float)$balanceInfo['balance'];

            try {
                $clientInvoices = $this->getClientOpenInvoices($currentClientKey);

                // If this client has no invoices but is in the group, add a placeholder invoice
                // to show that the client exists in the group
                if (empty($clientInvoices) && !$isPrimaryClient) {
                    $clientInvoices[] = [
                        'ledger_entry_KEY' => 0,
                        'invoice_number' => 'N/A',
                        'invoice_date' => 'N/A',
                        'due_date' => 'N/A',
                        'type' => 'No Invoices',
                        'open_amount' => '0.00',
                        'description' => 'No open invoices for this client',
                        'client_name' => $balanceInfo['client_name'],
                        'client_id' => $balanceInfo['client_id'],
                        'client_KEY' => $currentClientKey,
                        'is_other_client' => true,
                        'primary_client_name' => $clientInfo['client_name'] ?? $clientInfo['clients'][0]['client_name'],
                        'is_placeholder' => true, // Mark as placeholder
                    ];
                } elseif (!$isPrimaryClient) {
                    // Limit other clients to 5 invoices max to avoid overwhelming
                    $clientInvoices = array_slice($clientInvoices, 0, 5);
                }

                foreach ($clientInvoices as &$invoice) {
                    if (!isset($invoice['is_placeholder'])) {
                        $invoice['client_name'] = $balanceInfo['client_name'];
                        $invoice['client_id'] = $balanceInfo['client_id'];
                        $invoice['client_KEY'] = $currentClientKey;
                        $invoice['is_other_client'] = !$isPrimaryClient;
                        $invoice['primary_client_name'] = $clientInfo['client_name'] ?? $clientInfo['clients'][0]['client_name'];
                    }
                }
                $openInvoices = array_merge($openInvoices, $clientInvoices);
            } catch (\Exception $e) {
                Log::error("Error fetching invoices for client {$currentClientKey}: " . $e->getMessage());
            }
        }

        return [
            'openInvoices' => $openInvoices,
            'totalBalance' => $totalBalance
        ];
    }

    /**
     * Get pending projects for a client group that need acceptance
     * 
     * @param int $clientKey The primary client key
     * @return array
     */
    public function getPendingProjectsForClientGroup(int $clientKey): array
    {
        // 1. Find client group
        // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
        $clientGroup = DB::connection('sqlsrv')->selectOne("
            SELECT cv.custom_value as group_name
            FROM Custom_Value cv
            WHERE cv.custom_field_KEY = 122
            AND cv.table_information_KEY = 93
            AND cv.row_KEY = ?
        ", [$clientKey]);

        $groupName = $clientGroup ? $clientGroup->group_name : null;

        // 2. Build query to find EXP* projects
        // We need to find projects for the client OR their group members
        $sql = "
            SELECT DISTINCT
                E.engagement_KEY,
                E.description AS project_name,
                E.engagement_KEY as engagement_id, -- Using key as ID since engagement_id field might be missing/different
                ET.description AS engagement_type,
                ET.engagement_type_id,
                P.budgeted_amount as budget_amount,
                P.actual_start_date as start_date,
                P.original_due_date as end_date,
                P.long_description as notes,
                C.client_KEY,
                C.description AS client_name,
                C.client_id
            FROM Engagement E
            JOIN Engagement_Type ET ON E.engagement_type_KEY = ET.engagement_type_KEY
            JOIN Client C ON E.client_KEY = C.client_KEY
            JOIN Schedule_Item SI ON E.engagement_KEY = SI.engagement_KEY
            JOIN Project P ON SI.schedule_item_KEY = P.schedule_item_KEY
            LEFT JOIN Custom_Value cv ON C.client_KEY = cv.row_KEY 
                AND cv.custom_field_KEY = 122 
                AND cv.table_information_KEY = 93
            WHERE 
                ET.engagement_type_id LIKE 'EXP%'
                AND (
                    C.client_KEY = ?
        ";
        
        $params = [$clientKey];

        if ($groupName) {
            $sql .= " OR cv.custom_value = ? ";
            $params[] = $groupName;
        }

        $sql .= ") ORDER BY P.actual_start_date DESC";

        // ⚠️ CRITICAL: Must use 'sqlsrv' connection for READ-ONLY SQL Server database
        $projects = DB::connection('sqlsrv')->select($sql, $params);
        
        // 3. Filter out already accepted projects (check SQLite)
        $pendingProjects = [];
        
        // Get IDs of accepted projects
        $acceptedEngagementKeys = DB::connection('sqlite')
            ->table('project_acceptances')
            ->where('accepted', true)
            ->pluck('project_engagement_key')
            ->toArray();

        foreach ($projects as $project) {
            // Cast to array
            $projectData = (array) $project;
            
            // Check if already accepted
            if (!in_array($projectData['engagement_KEY'], $acceptedEngagementKeys)) {
                // Add group name for reference
                $projectData['group_name'] = $groupName;
                
                // Format dates
                if ($projectData['start_date'] instanceof \DateTime) {
                    $projectData['start_date'] = $projectData['start_date']->format('Y-m-d');
                }
                if ($projectData['end_date'] instanceof \DateTime) {
                    $projectData['end_date'] = $projectData['end_date']->format('Y-m-d');
                }
                
                $pendingProjects[] = $projectData;
            }
        }

        return $pendingProjects;
    }
}
