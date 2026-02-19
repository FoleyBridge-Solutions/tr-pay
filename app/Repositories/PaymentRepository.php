<?php

namespace App\Repositories;

use FoleyBridgeSolutions\PracticeCsPI\Data\Client;
use FoleyBridgeSolutions\PracticeCsPI\Data\Engagement;
use FoleyBridgeSolutions\PracticeCsPI\Data\Invoice;
use FoleyBridgeSolutions\PracticeCsPI\Exceptions\PracticeCsException;
use FoleyBridgeSolutions\PracticeCsPI\Services\ClientService;
use FoleyBridgeSolutions\PracticeCsPI\Services\EngagementService;
use FoleyBridgeSolutions\PracticeCsPI\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PaymentRepository
 *
 * Handles complex payment-related queries via the PracticeCS API
 * (practicecs-pi package) and local database.
 *
 * All PracticeCS data is fetched through ClientService, InvoiceService,
 * and EngagementService. Local database queries (e.g., SQLite) remain
 * as direct DB calls.
 */
class PaymentRepository
{
    /**
     * Create a new PaymentRepository instance.
     *
     * @param  ClientService  $clientService  PracticeCS client operations
     * @param  InvoiceService  $invoiceService  PracticeCS invoice operations
     * @param  EngagementService  $engagementService  PracticeCS engagement operations
     */
    public function __construct(
        private readonly ClientService $clientService,
        private readonly InvoiceService $invoiceService,
        private readonly EngagementService $engagementService,
    ) {}

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
        try {
            return $this->clientService->resolveClientKey($clientId);
        } catch (PracticeCsException $e) {
            Log::error('Failed to resolve client key', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

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
        try {
            $client = $this->clientService->findByClientId($clientId);

            return $client?->toArray();
        } catch (PracticeCsException $e) {
            Log::error('Failed to find client by client_id', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
        // Validate tax_id input before calling the API
        if ($searchType === 'tax_id' && $sanitizeTaxId) {
            $last4 = preg_replace('/\D/', '', $query);
            if (strlen($last4) !== 4) {
                return ['results' => [], 'error' => 'Please enter exactly 4 digits for Tax ID search.'];
            }
            $query = $last4;
        }

        try {
            $clients = $this->clientService->search($query, $searchType, $limit);

            return [
                'results' => array_map(fn (Client $client) => $client->toArray(), $clients),
                'error' => null,
            ];
        } catch (PracticeCsException $e) {
            Log::error('Client search failed', [
                'query' => $query,
                'search_type' => $searchType,
                'error' => $e->getMessage(),
            ]);

            return ['results' => [], 'error' => 'Search failed. Please try again.'];
        }
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

        try {
            return $this->clientService->getNames($clientIds);
        } catch (PracticeCsException $e) {
            Log::error('Failed to fetch client names', [
                'client_ids' => $clientIds,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Look up a single client name from PracticeCS by client_id.
     *
     * Convenience wrapper for single lookups.
     *
     * @param  string  $clientId  The human-readable client identifier
     * @return string|null The client name, or null if not found
     */
    public function getClientName(string $clientId): ?string
    {
        try {
            return $this->clientService->getName($clientId);
        } catch (PracticeCsException $e) {
            Log::error('Failed to fetch client name', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get client data by last 4 of SSN/EIN and last name.
     *
     * Uses the dedicated lookup endpoint which matches the exact original SQL:
     * RIGHT(C.federal_tin, 4) = ? AND C.individual_last_name = ?
     *
     * @param  string  $last4  Last 4 digits of SSN/EIN
     * @param  string  $lastName  Last name on account
     * @return array{clients: array[], client_KEY: int|null, client_id: string|null, client_name: string|null}|false
     */
    public function getClientByTaxIdAndName(string $last4, string $lastName)
    {
        $last4 = trim($last4);
        $lastName = trim($lastName);

        try {
            $result = $this->clientService->findByTaxIdAndName($last4, $lastName);

            if ($result === null) {
                return false;
            }

            return $result;
        } catch (PracticeCsException $e) {
            Log::error('Failed to look up client by tax ID and name', [
                'last4' => $last4,
                'lastName' => $lastName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the current AR balance and details for a client.
     *
     * @param  int|null  $clientKey
     * @param  string|null  $clientId
     */
    public function getClientBalance($clientKey = null, $clientId = null): array
    {
        try {
            $balance = $this->clientService->getBalance($clientKey, $clientId);

            return $balance->toArray();
        } catch (PracticeCsException $e) {
            Log::error('Failed to fetch client balance', [
                'client_KEY' => $clientKey,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return [
                'client_id' => $clientId ?? 'Unknown',
                'client_name' => 'Unknown Client',
                'balance' => 0.00,
            ];
        }
    }

    /**
     * Get all open invoices for a client.
     *
     * @param  int|null  $clientKey  The PracticeCS client_KEY (internal surrogate key)
     * @param  string|null  $clientId  The human-readable client_id (used if $clientKey is null)
     */
    public function getClientOpenInvoices(?int $clientKey = null, ?string $clientId = null): array
    {
        if ($clientKey === null && $clientId === null) {
            return [];
        }

        try {
            $invoices = $this->invoiceService->getOpenInvoices($clientKey, $clientId);

            return array_map(fn (Invoice $invoice) => $invoice->toArray(), $invoices);
        } catch (PracticeCsException $e) {
            Log::error('Failed to fetch open invoices', [
                'client_KEY' => $clientKey,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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

        try {
            $groupedData = $this->invoiceService->getGroupedInvoices($clientKey, $clientInfo, $clientId);

            return [
                'openInvoices' => $groupedData['openInvoices'] ?? [],
                'totalBalance' => (float) ($groupedData['totalBalance'] ?? 0),
            ];
        } catch (PracticeCsException $e) {
            Log::error('Failed to fetch grouped invoices', [
                'client_KEY' => $clientKey,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return ['openInvoices' => [], 'totalBalance' => 0];
        }
    }

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

        try {
            // Fetch pending projects from the API (includes group resolution)
            $engagements = $this->engagementService->getPendingProjects($clientKey, $clientId);

            // Convert Engagement DTOs to arrays
            $engagementArrays = array_map(
                fn (Engagement $e) => $e->toArray(),
                $engagements
            );

            // Filter out already accepted engagements (check local SQLite database)
            $acceptedEngagementKeys = DB::connection('sqlite')
                ->table('project_acceptances')
                ->where('accepted', true)
                ->pluck('project_engagement_key')
                ->toArray();

            if (! empty($acceptedEngagementKeys)) {
                $engagementArrays = array_values(array_filter(
                    $engagementArrays,
                    fn (array $engagement) => ! in_array($engagement['engagement_KEY'], $acceptedEngagementKeys)
                ));
            }

            return $engagementArrays;
        } catch (PracticeCsException $e) {
            Log::error('Failed to fetch pending projects for client group', [
                'client_KEY' => $clientKey,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get the primary email address for a client from PracticeCS.
     *
     * @param  string  $clientId  The human-readable client identifier
     * @return string|null The primary email address, or null if not found
     */
    public function getClientEmail(string $clientId): ?string
    {
        try {
            return $this->clientService->getEmail($clientId);
        } catch (PracticeCsException $e) {
            Log::error('Failed to fetch client email', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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

        try {
            return $this->clientService->getEmailsBatch($clientIds);
        } catch (PracticeCsException $e) {
            Log::error('Failed to fetch client emails batch', [
                'client_ids_count' => count($clientIds),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
