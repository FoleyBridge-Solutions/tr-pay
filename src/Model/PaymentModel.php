<?php

namespace Fbs\trpay\Model;

class PaymentModel extends BaseModel
{
    /**
     * This model doesn't map directly to a single table
     */
    protected function isTableRequired(): bool
    {
        return false;
    }

    /**
     * Get company and client data by email
     */
    public function getCompanyByEmail(string $email)
    {
        $email = strtolower(trim($email));

        // Corrected SQL query
        $sql = '
            SELECT DISTINCT
                C.client_KEY,
                C.client_id,
                C.description AS client_name
            FROM
                Client C
            INNER JOIN
                Contact Con ON C.contact_KEY = Con.contact_KEY
            INNER JOIN
                Contact_Email CE ON Con.contact_KEY = CE.contact_KEY
            WHERE
                LOWER(CE.email) = ?
            ORDER BY
                C.description
        ';

        // Execute the query to get multiple results
        $stmt = sqlsrv_query($this->pdo, $sql, [$email]); // Assuming $this->pdo is your SQL Server connection
        if ($stmt === false) {
            error_log('SQL error: '.print_r(sqlsrv_errors(), true));

            return false;
        }

        $clients = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clients[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        if (count($clients) === 0) {
            error_log('No clients found for email: '.$email); // Optional: suppress if false is sufficient

            return false;
        }

        error_log('Found '.count($clients).' clients for email: '.$email); // Optional logging

        // Return multiple clients
        // The rest of your return structure is fine and handles single vs. multiple clients well.
        return [
            'email' => $email,
            'clients' => $clients,
            'client_KEY' => count($clients) === 1 ? $clients[0]['client_KEY'] : null,
            'client_id' => count($clients) === 1 ? $clients[0]['client_id'] : null,
            'client_name' => count($clients) === 1 ? $clients[0]['client_name'] : null,
        ];
    }

    /**
     * Get the current AR balance and details for a client
     */
    public function getClientBalance($clientKey = null, $clientId = null): array
    {
        // First get client info
        $clientInfoSql = 'SELECT client_KEY, client_id, description FROM Client WHERE '.
                        ($clientKey !== null ? 'client_KEY = ?' : 'client_id = ?');
        $params = [$clientKey !== null ? $clientKey : $clientId];
        $clientInfo = $this->fetch($clientInfoSql, $params);

        if (! $clientInfo) {
            return [
                'client_id' => $clientId ?? 'Unknown',
                'client_name' => 'Unknown Client',
                'balance' => 0.00,
            ];
        }

        // Use a proper approach with temp tables to calculate balance
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

        $result = $this->fetch($sql, [$clientInfo['client_KEY']]);

        if (! $result) {
            return [
                'client_id' => $clientInfo['client_id'],
                'client_name' => $clientInfo['description'],
                'balance' => 0.00,
            ];
        }

        return [
            'client_id' => $result['client_id'],
            'client_name' => $result['ClientName'],
            'balance' => (float) $result['CurrentARBalance'],
        ];
    }

    /**
     * Get all open invoices for a client
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
                -- Replace LE.description with an appropriate alternative or a concatenated value
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
                -- Only include entries with open amounts
                AND (LE.amount + 
                    COALESCE(AT.applied_amount_to, 0.00) - 
                    COALESCE(AF.applied_amount_from, 0.00)) <> 0
                -- Include all entries with outstanding balances regardless of normal_sign
                -- (commented out condition that was too restrictive)
                -- AND LETP.normal_sign = 1
            ORDER BY
                LE.entry_date DESC
        ";

        // Debug the client key before executing
        error_log('Fetching invoices for client_KEY: '.$clientKey);

        // Execute query and return results
        $stmt = sqlsrv_query($this->pdo, $sql, [$clientKey]);
        if ($stmt === false) {
            throw new \Exception(print_r(sqlsrv_errors(), true));
        }

        $invoices = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Format dates for display, checking type first
            if ($row['invoice_date'] instanceof \DateTime) {
                $row['invoice_date'] = $row['invoice_date']->format('m/d/Y');
            } elseif (is_string($row['invoice_date'])) {
                // Already a string, make sure it's properly formatted
                $date = date_create($row['invoice_date']);
                $row['invoice_date'] = $date ? date_format($date, 'm/d/Y') : $row['invoice_date'];
            } else {
                $row['invoice_date'] = 'N/A';
            }

            if ($row['due_date'] instanceof \DateTime) {
                $row['due_date'] = $row['due_date']->format('m/d/Y');
            } elseif (is_string($row['due_date'])) {
                $date = date_create($row['due_date']);
                $row['due_date'] = $date ? date_format($date, 'm/d/Y') : $row['due_date'];
            } else {
                $row['due_date'] = 'N/A';
            }

            // Format amount as number with 2 decimals
            $row['open_amount'] = number_format((float) $row['open_amount'], 2, '.', '');

            $invoices[] = $row;

            // Debug each invoice found
            error_log('Found invoice: #'.($row['invoice_number'] ?? 'N/A').' - Amount: $'.$row['open_amount']);
        }
        sqlsrv_free_stmt($stmt);

        error_log('Total invoices found: '.count($invoices));

        return $invoices;
    }
}
