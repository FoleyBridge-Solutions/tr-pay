<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PaymentRepository
 *
 * Handles complex payment-related database queries
 *
 * All PracticeCS queries MUST use DB::connection('sqlsrv') to explicitly
 * target the SQL Server database.
 */
class PaymentRepository
{
    /**
     * Custom field KEY for "Group Name" in PracticeCS Custom_Value table.
     * Used to group related clients together for combined invoice views.
     */
    private const CUSTOM_FIELD_GROUP_NAME = 122;

    /**
     * Resolve a PracticeCS client_KEY from a client_id.
     *
     * Used internally when callers provide the human-readable client_id
     * but the PracticeCS query requires the surrogate client_KEY for joins.
     *
     * @param  string  $clientId  The human-readable client identifier
     * @return int|null The PracticeCS client_KEY, or null if not found
     */
    public function resolveClientKey(string $clientId): ?int
    {
        $client = $this->findClientByClientId($clientId);

        return $client ? (int) $client['client_KEY'] : null;
    }

    /**
     * Standard columns selected for client lookups.
     *
     * All methods that return "a client record" should select these same columns
     * to ensure a consistent shape across the codebase.
     */
    private const CLIENT_SELECT_COLUMNS = '
        client_KEY,
        client_id,
        description AS client_name,
        individual_first_name,
        individual_last_name,
        federal_tin
    ';

    /**
     * Find a single PracticeCS client by their human-readable client_id.
     *
     * Returns the standard 6-column client record as an associative array,
     * or null if the client does not exist.
     *
     * @param  string  $clientId  The human-readable client identifier (e.g. "SMITH001")
     * @return array{client_KEY: int, client_id: string, client_name: string, individual_first_name: ?string, individual_last_name: ?string, federal_tin: ?string}|null
     */
    public function findClientByClientId(string $clientId): ?array
    {
        $result = DB::connection('sqlsrv')->selectOne(
            'SELECT '.self::CLIENT_SELECT_COLUMNS.' FROM Client WHERE client_id = ?',
            [$clientId]
        );

        return $result ? (array) $result : null;
    }

    /**
     * Search for clients in PracticeCS.
     *
     * Supports three search modes:
     *  - 'name'      — matches description, individual_last_name, or individual_first_name
     *  - 'client_id' — matches client_id with LIKE
     *  - 'tax_id'    — matches last 4 digits of federal_tin (optionally sanitized)
     *
     * @param  string  $query  The search term
     * @param  string  $searchType  One of 'name', 'client_id', 'tax_id'
     * @param  int  $limit  Maximum results to return
     * @param  bool  $sanitizeTaxId  Whether to strip non-digits and validate length for tax_id searches
     * @return array{results: array[], error: string|null}
     */
    public function searchClients(string $query, string $searchType = 'name', int $limit = 20, bool $sanitizeTaxId = true): array
    {
        $columns = self::CLIENT_SELECT_COLUMNS;

        if ($searchType === 'client_id') {
            $results = DB::connection('sqlsrv')->select(
                "SELECT TOP {$limit} {$columns} FROM Client WHERE client_id LIKE ? ORDER BY description",
                ["%{$query}%"]
            );
        } elseif ($searchType === 'tax_id') {
            if ($sanitizeTaxId) {
                $last4 = preg_replace('/\D/', '', $query);
                if (strlen($last4) !== 4) {
                    return ['results' => [], 'error' => 'Please enter exactly 4 digits for Tax ID search.'];
                }

                $results = DB::connection('sqlsrv')->select(
                    "SELECT TOP {$limit} {$columns} FROM Client WHERE RIGHT(REPLACE(REPLACE(federal_tin, '-', ''), ' ', ''), 4) = ? ORDER BY description",
                    [$last4]
                );
            } else {
                $results = DB::connection('sqlsrv')->select(
                    "SELECT TOP {$limit} {$columns} FROM Client WHERE RIGHT(federal_tin, 4) = ? ORDER BY description",
                    [$query]
                );
            }
        } else {
            // Default: name search
            $results = DB::connection('sqlsrv')->select(
                "SELECT TOP {$limit} {$columns} FROM Client WHERE description LIKE ? OR individual_last_name LIKE ? OR individual_first_name LIKE ? ORDER BY description",
                ["%{$query}%", "%{$query}%", "%{$query}%"]
            );
        }

        return [
            'results' => array_map(fn ($r) => (array) $r, $results),
            'error' => null,
        ];
    }

    /**
     * Fetch client names from PracticeCS for a batch of client IDs.
     *
     * Used by index/listing pages that need to display the live PracticeCS
     * name alongside locally-stored records.
     *
     * @param  array<string>  $clientIds  Array of human-readable client_id values
     * @return array<string, string> Map of client_id => client_name
     */
    public function getClientNames(array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_filter($clientIds)));

        if (empty($clientIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));

        $results = DB::connection('sqlsrv')->select(
            "SELECT client_id, description AS client_name FROM Client WHERE client_id IN ({$placeholders})",
            $clientIds
        );

        $names = [];
        foreach ($results as $row) {
            $names[$row->client_id] = $row->client_name;
        }

        return $names;
    }

    /**
     * Look up a single client name from PracticeCS by client_id.
     *
     * Convenience wrapper around getClientNames() for single lookups.
     *
     * @param  string  $clientId  The human-readable client identifier
     * @return string|null The client name, or null if not found
     */
    public function getClientName(string $clientId): ?string
    {
        $result = DB::connection('sqlsrv')->selectOne(
            'SELECT description AS client_name FROM Client WHERE client_id = ?',
            [$clientId]
        );

        return $result?->client_name;
    }

    /**
     * Table information KEY for the Client table in PracticeCS.
     * Used with Custom_Value queries to link custom fields to clients.
     */
    private const TABLE_INFO_CLIENT = 93;

    /**
     * Get client data by last 4 of SSN/EIN and last name
     *
     * @param  string  $last4  Last 4 digits of SSN/EIN
     * @param  string  $lastName  Last name on account
     * @return array|false
     */
    public function getClientByTaxIdAndName(string $last4, string $lastName)
    {
        $last4 = trim($last4);
        $lastName = trim($lastName);

        $sql = '
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
        ';

        // Use 'sqlsrv' connection for PracticeCS SQL Server database
        $clients = DB::connection('sqlsrv')->select($sql, [$last4, $lastName]);

        if (count($clients) === 0) {
            return false;
        }

        // Convert stdClass objects to arrays
        $clientsArray = array_map(function ($client) {
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
            'client_name' => count($clientsArray) === 1 ? $clientsArray[0]['client_name'] : null,
        ];
    }

    /**
     * Get the current AR balance and details for a client
     *
     * @param  int|null  $clientKey
     * @param  string|null  $clientId
     */
    public function getClientBalance($clientKey = null, $clientId = null): array
    {
        // First get client info
        $clientInfoSql = 'SELECT client_KEY, client_id, description FROM Client WHERE '.
                        ($clientKey !== null ? 'client_KEY = ?' : 'client_id = ?');
        $params = [$clientKey !== null ? $clientKey : $clientId];

        // Use 'sqlsrv' connection for PracticeCS SQL Server database
        $clientInfo = DB::connection('sqlsrv')->selectOne($clientInfoSql, $params);

        if (! $clientInfo) {
            return [
                'client_id' => $clientId ?? 'Unknown',
                'client_name' => 'Unknown Client',
                'balance' => 0.00,
            ];
        }

        // Use a proper approach with CTEs to calculate balance
        $sql = '
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
        ';

        // Use 'sqlsrv' connection for PracticeCS SQL Server database
        $result = DB::connection('sqlsrv')->selectOne($sql, [$clientInfo->client_KEY]);

        if (! $result) {
            return [
                'client_id' => $clientInfo->client_id,
                'client_name' => $clientInfo->description,
                'balance' => 0.00,
            ];
        }

        return [
            'client_id' => $result->client_id,
            'client_name' => $result->ClientName,
            'balance' => (float) $result->CurrentARBalance,
        ];
    }

    /**
     * Get all open invoices for a client.
     *
     * @param  int|null  $clientKey  The PracticeCS client_KEY (internal surrogate key)
     * @param  string|null  $clientId  The human-readable client_id (used if $clientKey is null)
     */
    public function getClientOpenInvoices(?int $clientKey = null, ?string $clientId = null): array
    {
        if ($clientKey === null && $clientId !== null) {
            $clientKey = $this->resolveClientKey($clientId);
            if ($clientKey === null) {
                return [];
            }
        }

        if ($clientKey === null) {
            return [];
        }
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

        // Use 'sqlsrv' connection for PracticeCS SQL Server database
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
            $invoice['open_amount'] = number_format((float) $invoice['open_amount'], 2, '.', '');

            $invoices[] = $invoice;
        }

        return $invoices;
    }

    /**
     * Get open invoices for a client and their related group members.
     *
     * Optimized to batch queries and avoid N+1 query issues.
     *
     * @param  int|null  $clientKey  The PracticeCS client_KEY (internal surrogate key)
     * @param  array  $clientInfo  The client info array (containing client_name, etc.)
     * @param  string|null  $clientId  The human-readable client_id (used if $clientKey is null)
     * @return array ['openInvoices' => [], 'totalBalance' => float]
     */
    public function getGroupedInvoicesForClient(?int $clientKey, array $clientInfo, ?string $clientId = null): array
    {
        if ($clientKey === null && $clientId !== null) {
            $clientKey = $this->resolveClientKey($clientId);
            if ($clientKey === null) {
                return ['openInvoices' => [], 'totalBalance' => 0];
            }
        }

        if ($clientKey === null) {
            return ['openInvoices' => [], 'totalBalance' => 0];
        }
        $openInvoices = [];
        $totalBalance = 0;

        // Find all clients in the same "Group Name" custom field group
        // Use 'sqlsrv' connection for PracticeCS SQL Server database
        $clientGroup = DB::connection('sqlsrv')->selectOne('
            SELECT cv.custom_value as group_name
            FROM Custom_Value cv
            WHERE cv.custom_field_KEY = '.self::CUSTOM_FIELD_GROUP_NAME.'
            AND cv.table_information_KEY = '.self::TABLE_INFO_CLIENT.'
            AND cv.row_KEY = ?
        ', [$clientKey]);

        if ($clientGroup && ! empty($clientGroup->group_name)) {
            // Find ALL clients with the same group name
            // Use 'sqlsrv' connection for PracticeCS SQL Server database
            $groupClients = DB::connection('sqlsrv')->select('
                SELECT c.client_KEY, c.client_id, c.description as client_name
                FROM Client c
                INNER JOIN Custom_Value cv ON c.client_KEY = cv.row_KEY
                WHERE cv.custom_field_KEY = '.self::CUSTOM_FIELD_GROUP_NAME.'
                AND cv.table_information_KEY = '.self::TABLE_INFO_CLIENT.'
                AND cv.custom_value = ?
                ORDER BY CASE WHEN c.client_KEY = ? THEN 0 ELSE 1 END, c.description ASC
            ', [$clientGroup->group_name, $clientKey]);

            Log::info('Client Group by Custom Field', [
                'groupName' => $clientGroup->group_name,
                'groupClientsCount' => count($groupClients),
                'groupClients' => $groupClients,
            ]);
        } else {
            // No group assigned - only show this client
            // Use 'sqlsrv' connection for PracticeCS SQL Server database
            $groupClients = DB::connection('sqlsrv')->select('
                SELECT c.client_KEY, c.client_id, c.description as client_name
                FROM Client c
                WHERE c.client_KEY = ?
            ', [$clientKey]);

            Log::info('Client has no group assigned', [
                'clientKey' => $clientKey,
                'groupClientsCount' => count($groupClients),
            ]);
        }

        // Collect all client keys for batch queries
        $allClientKeys = array_map(fn ($c) => $c->client_KEY, $groupClients);

        if (empty($allClientKeys)) {
            return ['openInvoices' => [], 'totalBalance' => 0];
        }

        // Batch fetch balances for all clients in one query
        $balances = $this->getClientBalancesBatch($allClientKeys);

        // Batch fetch invoices for all clients in one query
        $allInvoices = $this->getClientOpenInvoicesBatch($allClientKeys);

        // Process results
        $primaryClientName = $clientInfo['client_name'] ?? $clientInfo['clients'][0]['client_name'] ?? 'Unknown';

        foreach ($groupClients as $clientData) {
            $currentClientKey = $clientData->client_KEY;
            $isPrimaryClient = ($currentClientKey === $clientKey);

            // Get balance from batched results
            $balanceInfo = $balances[$currentClientKey] ?? [
                'client_id' => $clientData->client_id,
                'client_name' => $clientData->client_name,
                'balance' => 0.00,
            ];
            $totalBalance += (float) $balanceInfo['balance'];

            // Get invoices from batched results
            $clientInvoices = $allInvoices[$currentClientKey] ?? [];

            // If this client has no invoices but is in the group, add a placeholder
            if (empty($clientInvoices) && ! $isPrimaryClient) {
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
                    'primary_client_name' => $primaryClientName,
                    'is_placeholder' => true,
                ];
            } elseif (! $isPrimaryClient) {
                // Limit other clients to 5 invoices max
                $clientInvoices = array_slice($clientInvoices, 0, 5);
            }

            foreach ($clientInvoices as &$invoice) {
                if (! isset($invoice['is_placeholder'])) {
                    $invoice['client_name'] = $balanceInfo['client_name'];
                    $invoice['client_id'] = $balanceInfo['client_id'];
                    $invoice['client_KEY'] = $currentClientKey;
                    $invoice['is_other_client'] = ! $isPrimaryClient;
                    $invoice['primary_client_name'] = $primaryClientName;
                }
            }
            $openInvoices = array_merge($openInvoices, $clientInvoices);
        }

        return [
            'openInvoices' => $openInvoices,
            'totalBalance' => $totalBalance,
        ];
    }

    /**
     * Get balances for multiple clients in a single query.
     *
     * @param  array  $clientKeys  Array of client_KEY values
     * @return array Associative array keyed by client_KEY
     */
    private function getClientBalancesBatch(array $clientKeys): array
    {
        if (empty($clientKeys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clientKeys), '?'));

        $sql = "
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
            SELECT
                C.client_KEY,
                C.client_id,
                C.description AS client_name,
                SUM((
                    LE.amount + 
                    COALESCE(AT.applied_amount_to, 0.00) - 
                    COALESCE(AF.applied_amount_from, 0.00)
                ) * LET.normal_sign) AS balance
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
                C.client_KEY IN ({$placeholders})
            GROUP BY
                C.client_KEY,
                C.client_id,
                C.description
        ";

        // Use 'sqlsrv' connection for PracticeCS SQL Server database
        $results = DB::connection('sqlsrv')->select($sql, $clientKeys);

        $balances = [];
        foreach ($results as $row) {
            $balances[$row->client_KEY] = [
                'client_id' => $row->client_id,
                'client_name' => $row->client_name,
                'balance' => (float) $row->balance,
            ];
        }

        return $balances;
    }

    /**
     * Get open invoices for multiple clients in a single query.
     *
     * @param  array  $clientKeys  Array of client_KEY values
     * @return array Associative array keyed by client_KEY, each containing array of invoices
     */
    private function getClientOpenInvoicesBatch(array $clientKeys): array
    {
        if (empty($clientKeys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clientKeys), '?'));

        $sql = "
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
            SELECT
                LE.client_KEY,
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
                LE.client_KEY IN ({$placeholders})
                AND LE.posted__staff_KEY IS NOT NULL
                AND (LE.amount + 
                    COALESCE(AT.applied_amount_to, 0.00) - 
                    COALESCE(AF.applied_amount_from, 0.00)) <> 0
            ORDER BY
                LE.client_KEY,
                LE.entry_date DESC
        ";

        // Use 'sqlsrv' connection for PracticeCS SQL Server database
        $results = DB::connection('sqlsrv')->select($sql, $clientKeys);

        $invoicesByClient = [];
        foreach ($results as $row) {
            $clientKey = $row->client_KEY;
            $invoice = (array) $row;
            unset($invoice['client_KEY']); // Remove duplicate key

            // Format dates
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

            $invoice['open_amount'] = number_format((float) $invoice['open_amount'], 2, '.', '');

            if (! isset($invoicesByClient[$clientKey])) {
                $invoicesByClient[$clientKey] = [];
            }
            $invoicesByClient[$clientKey][] = $invoice;
        }

        return $invoicesByClient;
    }

    /**
     * Get pending projects for a client group that need acceptance
     *
     * @param  int  $clientKey  The primary client key
     */
    /**
     * Get pending EXPANSION engagements grouped by engagement for a client group.
     *
     * Returns an array of engagements, each containing its child projects.
     * An engagement may have multiple projects via Schedule_Item -> Project.
     *
     * Return structure:
     * [
     *   [
     *     'engagement_KEY' => int,
     *     'engagement_name' => string,
     *     'engagement_type' => string,
     *     'engagement_type_id' => string,
     *     'client_KEY' => int,
     *     'client_name' => string,
     *     'client_id' => string,
     *     'group_name' => string|null,
     *     'total_budget' => float,
     *     'projects' => [
     *       [
     *         'project_key' => int,
     *         'project_number' => string,
     *         'budget_amount' => float,
     *         'start_date' => string|null,
     *         'end_date' => string|null,
     *         'project_date' => string|null,
     *         'notes' => string|null,
     *       ],
     *       ...
     *     ],
     *   ],
     *   ...
     * ]
     *
     * @param  int|null  $clientKey  The PracticeCS client_KEY (internal surrogate key)
     * @param  string|null  $clientId  The human-readable client_id (used if $clientKey is null)
     * @return array Engagement-grouped pending projects
     */
    public function getPendingProjectsForClientGroup(?int $clientKey = null, ?string $clientId = null): array
    {
        if ($clientKey === null && $clientId !== null) {
            $clientKey = $this->resolveClientKey($clientId);
            if ($clientKey === null) {
                return [];
            }
        }

        if ($clientKey === null) {
            return [];
        }
        // 1. Find client group
        // Use 'sqlsrv' connection for PracticeCS SQL Server database
        $clientGroup = DB::connection('sqlsrv')->selectOne('
            SELECT cv.custom_value as group_name
            FROM Custom_Value cv
            WHERE cv.custom_field_KEY = '.self::CUSTOM_FIELD_GROUP_NAME.'
            AND cv.table_information_KEY = '.self::TABLE_INFO_CLIENT.'
            AND cv.row_KEY = ?
        ', [$clientKey]);

        $groupName = $clientGroup ? $clientGroup->group_name : null;

        // 2. Build query to find EXP* engagements and their projects
        // We need engagements for the client OR their group members.
        // Note: LEFT JOIN on Engagement_Type because some engagements (e.g., EXPADVISORY)
        // have NULL engagement_type_KEY. We also join Engagement_Template to match
        // engagements by template ID (e.g., EXPADVISORY, EXPTAX) in addition to type ID.
        // No DISTINCT — we want all projects per engagement for grouping.
        $sql = '
            SELECT
                E.engagement_KEY,
                E.description AS engagement_name,
                COALESCE(ET.description, ETPL.description) AS engagement_type,
                COALESCE(ET.engagement_type_id, ETPL.engagement_template_id) AS engagement_type_id,
                P.project_KEY as project_key,
                P.project_number as project_number,
                P.budgeted_amount as budget_amount,
                P.actual_start_date as start_date,
                P.original_due_date as end_date,
                COALESCE(P.actual_start_date, P.original_due_date, P.received_date) AS project_date,
                P.long_description as notes,
                C.client_KEY,
                C.description AS client_name,
                C.client_id
            FROM Engagement E
            LEFT JOIN Engagement_Type ET ON E.engagement_type_KEY = ET.engagement_type_KEY
            LEFT JOIN Engagement_Template ETPL ON E.engagement_template_KEY = ETPL.engagement_template_KEY
            JOIN Client C ON E.client_KEY = C.client_KEY
            JOIN Schedule_Item SI ON E.engagement_KEY = SI.engagement_KEY
            JOIN Project P ON SI.schedule_item_KEY = P.schedule_item_KEY
            LEFT JOIN Custom_Value cv ON C.client_KEY = cv.row_KEY 
                AND cv.custom_field_KEY = '.self::CUSTOM_FIELD_GROUP_NAME.' 
                AND cv.table_information_KEY = '.self::TABLE_INFO_CLIENT."
            WHERE 
                (ETPL.engagement_template_id LIKE 'EXP%' OR ET.engagement_type_id = 'EXPANSION')
                AND (E.engagement_type_KEY IS NULL OR E.engagement_type_KEY = 3)
                AND (
                    C.client_KEY = ?
        ";

        $params = [$clientKey];

        if ($groupName) {
            $sql .= ' OR cv.custom_value = ? ';
            $params[] = $groupName;
        }

        $sql .= ') ORDER BY E.engagement_KEY, P.actual_start_date DESC';

        // Use 'sqlsrv' connection for PracticeCS SQL Server database
        $rows = DB::connection('sqlsrv')->select($sql, $params);

        // 3. Filter out already accepted engagements (check SQLite)
        $acceptedEngagementKeys = DB::connection('sqlite')
            ->table('project_acceptances')
            ->where('accepted', true)
            ->pluck('project_engagement_key')
            ->toArray();

        // 4. Group rows by engagement_KEY into nested structure
        $engagementMap = [];

        foreach ($rows as $row) {
            $rowData = (array) $row;

            // Skip already accepted engagements
            if (in_array($rowData['engagement_KEY'], $acceptedEngagementKeys)) {
                continue;
            }

            $engagementKey = $rowData['engagement_KEY'];

            // Format date fields on the project
            foreach (['start_date', 'end_date', 'project_date'] as $dateField) {
                if (isset($rowData[$dateField]) && $rowData[$dateField] instanceof \DateTime) {
                    $rowData[$dateField] = $rowData[$dateField]->format('Y-m-d');
                }
            }

            // Initialize engagement group if not seen yet
            if (! isset($engagementMap[$engagementKey])) {
                $engagementMap[$engagementKey] = [
                    'engagement_KEY' => $engagementKey,
                    'engagement_name' => $rowData['engagement_name'],
                    'engagement_type' => $rowData['engagement_type'],
                    'engagement_type_id' => $rowData['engagement_type_id'],
                    'client_KEY' => $rowData['client_KEY'],
                    'client_name' => $rowData['client_name'],
                    'client_id' => $rowData['client_id'],
                    'group_name' => $groupName,
                    'total_budget' => 0,
                    'projects' => [],
                ];
            }

            // Avoid duplicate projects (same project_KEY under the same engagement)
            $existingProjectKeys = array_column($engagementMap[$engagementKey]['projects'], 'project_key');
            if (in_array($rowData['project_key'], $existingProjectKeys)) {
                continue;
            }

            // Add project to engagement group
            $engagementMap[$engagementKey]['projects'][] = [
                'project_key' => $rowData['project_key'],
                'project_number' => $rowData['project_number'],
                'budget_amount' => (float) $rowData['budget_amount'],
                'start_date' => $rowData['start_date'],
                'end_date' => $rowData['end_date'],
                'project_date' => $rowData['project_date'],
                'notes' => $rowData['notes'],
            ];

            $engagementMap[$engagementKey]['total_budget'] += (float) $rowData['budget_amount'];
        }

        // Return as a numerically-indexed array (reset keys)
        return array_values($engagementMap);
    }

    /**
     * Get the primary email address for a client from PracticeCS.
     *
     * Joins Client → Contact → Contact_Email, filtering by the contact's
     * primary__contact_email_type_KEY to find the preferred email address.
     * Falls back to the first available email if no primary type is set.
     *
     * @param  string  $clientId  The human-readable client identifier
     * @return string|null The primary email address, or null if not found
     */
    public function getClientEmail(string $clientId): ?string
    {
        // First try to get the primary email using the contact's preferred email type
        $result = DB::connection('sqlsrv')->selectOne(
            'SELECT CE.email
             FROM Client C
             JOIN Contact CO ON C.contact_KEY = CO.contact_KEY
             JOIN Contact_Email CE ON CO.contact_KEY = CE.contact_KEY
                 AND CO.primary__contact_email_type_KEY = CE.contact_email_type_KEY
             WHERE C.client_id = ?',
            [$clientId]
        );

        if ($result) {
            return $result->email;
        }

        // Fall back to the first available email for this client's contact
        $fallback = DB::connection('sqlsrv')->selectOne(
            'SELECT TOP 1 CE.email
             FROM Client C
             JOIN Contact CO ON C.contact_KEY = CO.contact_KEY
             JOIN Contact_Email CE ON CO.contact_KEY = CE.contact_KEY
             WHERE C.client_id = ?
             ORDER BY CE.contact_email_KEY',
            [$clientId]
        );

        return $fallback?->email;
    }

    /**
     * Get primary email addresses for multiple clients in a single batch query.
     *
     * Optimized for bulk operations like the email sync command.
     * Returns a map of client_id => email for all clients that have emails.
     *
     * @param  array<string>  $clientIds  Array of human-readable client_id values
     * @return array<string, string> Map of client_id => primary email
     */
    public function getClientEmailsBatch(array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_filter($clientIds)));

        if (empty($clientIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));

        // Get primary emails (matched by contact's preferred email type)
        $primaryResults = DB::connection('sqlsrv')->select(
            "SELECT C.client_id, CE.email
             FROM Client C
             JOIN Contact CO ON C.contact_KEY = CO.contact_KEY
             JOIN Contact_Email CE ON CO.contact_KEY = CE.contact_KEY
                 AND CO.primary__contact_email_type_KEY = CE.contact_email_type_KEY
             WHERE C.client_id IN ({$placeholders})",
            $clientIds
        );

        $emails = [];
        foreach ($primaryResults as $row) {
            $emails[$row->client_id] = $row->email;
        }

        // For clients without a primary email match, fall back to their first email
        $missingClientIds = array_diff($clientIds, array_keys($emails));

        if (! empty($missingClientIds)) {
            $missingPlaceholders = implode(',', array_fill(0, count($missingClientIds), '?'));

            $fallbackResults = DB::connection('sqlsrv')->select(
                "SELECT sub.client_id, sub.email
                 FROM (
                     SELECT C.client_id, CE.email,
                         ROW_NUMBER() OVER (PARTITION BY C.client_id ORDER BY CE.contact_email_KEY) AS rn
                     FROM Client C
                     JOIN Contact CO ON C.contact_KEY = CO.contact_KEY
                     JOIN Contact_Email CE ON CO.contact_KEY = CE.contact_KEY
                     WHERE C.client_id IN ({$missingPlaceholders})
                 ) sub
                 WHERE sub.rn = 1",
                array_values($missingClientIds)
            );

            foreach ($fallbackResults as $row) {
                $emails[$row->client_id] = $row->email;
            }
        }

        return $emails;
    }
}
