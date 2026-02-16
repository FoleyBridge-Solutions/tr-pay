<?php

// app/Livewire/Admin/ClientSearch.php

namespace App\Livewire\Admin;

use App\Repositories\PaymentRepository;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reusable Client Search Component
 *
 * A self-contained Livewire component for searching and selecting clients
 * from PracticeCS (SQL Server). Used across all admin pages that need
 * client selection.
 *
 * Modes:
 * - 'select': Table with Select buttons (used by payment/plan wizards)
 * - 'browse': Table with View links and extra name detail (used by Clients index)
 * - 'compact': Scrollable button list (used by recurring payments)
 *
 * Dispatches:
 * - 'client-selected' with ['client' => $clientArray]
 * - 'client-cleared'
 *
 * Listens:
 * - 'reset-client-search' to reset all state
 */
class ClientSearch extends Component
{
    // Search state (URL-synced)
    #[Url(as: 'client')]
    public string $searchQuery = '';

    #[Url(as: 'search_type')]
    public string $searchType = 'name';

    public array $searchResults = [];

    public bool $loading = false;

    public ?string $errorMessage = null;

    // Selection state
    public ?array $selectedClient = null;

    // Configuration (set by parent via mount)
    #[Locked]
    public string $mode = 'select';

    #[Locked]
    public bool $showTaxId = true;

    #[Locked]
    public int $limit = 20;

    #[Locked]
    public bool $showSelected = false;

    /**
     * Mount the component with configuration from the parent.
     *
     * @param  string  $mode  Display mode: 'select', 'browse', or 'compact'
     * @param  bool  $showTaxId  Whether to show the Tax ID column in results
     * @param  int  $limit  Maximum number of search results
     * @param  bool  $showSelected  Whether to show the selected client card with Change button
     * @param  array|null  $selectedClient  Pre-selected client data from the parent
     */
    public function mount(
        string $mode = 'select',
        bool $showTaxId = true,
        int $limit = 20,
        bool $showSelected = false,
        ?array $selectedClient = null,
    ): void {
        $this->mode = $mode;
        $this->showTaxId = $showTaxId;
        $this->limit = $limit;
        $this->showSelected = $showSelected;

        if ($selectedClient) {
            $this->selectedClient = $selectedClient;
        }

        // If the URL has a client param that looks like a client ID (not a search query),
        // auto-search and auto-select on mount
        if ($this->searchQuery && ! $this->selectedClient) {
            $this->autoSelectFromUrl();
        }
    }

    /**
     * Attempt to auto-select a client from the URL parameter.
     *
     * If the search query is a plausible client ID (alphanumeric, short),
     * search by client_id and auto-select if exactly one result is found.
     * Otherwise, just trigger a regular search.
     */
    protected function autoSelectFromUrl(): void
    {
        $query = trim($this->searchQuery);

        // If it looks like a client ID (short alphanumeric string), try exact match first
        if (strlen($query) <= 20 && preg_match('/^[A-Za-z0-9._-]+$/', $query)) {
            try {
                /** @var PaymentRepository $repo */
                $repo = app(PaymentRepository::class);
                $result = $repo->searchClients($query, 'client_id', 1, true);

                if ($result['error'] === null && count($result['results']) === 1) {
                    $client = $result['results'][0];
                    if (strcasecmp($client['client_id'], $query) === 0) {
                        $this->selectClient($client['client_id']);

                        return;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Auto-select from URL failed', ['query' => $query, 'error' => $e->getMessage()]);
            }
        }

        // Fall back to regular search
        $this->searchClients();
    }

    /**
     * Search for clients in PracticeCS.
     *
     * Delegates to PaymentRepository::searchClients() for the actual query.
     * Validates minimum query length and sanitizes tax ID input.
     */
    public function searchClients(): void
    {
        $this->searchResults = [];
        $this->loading = true;
        $this->errorMessage = null;

        $query = trim($this->searchQuery);

        if (strlen($query) < 2) {
            $this->loading = false;

            return;
        }

        try {
            /** @var PaymentRepository $repo */
            $repo = app(PaymentRepository::class);

            $sanitizeTaxId = $this->mode !== 'browse';

            $result = $repo->searchClients(
                $query,
                $this->searchType,
                $this->limit,
                $sanitizeTaxId
            );

            if ($result['error'] !== null) {
                $this->errorMessage = $result['error'];

                return;
            }

            $this->searchResults = $result['results'];
        } catch (\Exception $e) {
            Log::error('Client search failed', ['error' => $e->getMessage()]);
            $this->errorMessage = 'Failed to search clients. Please try again.';
        } finally {
            $this->loading = false;
        }
    }

    /**
     * Select a client and dispatch the event to the parent component.
     *
     * In 'browse' mode, this is not used (View links navigate directly).
     * In 'select' and 'compact' modes, dispatches 'client-selected' to parent.
     */
    public function selectClient(string $clientId): void
    {
        // Find the client in search results
        $client = null;
        foreach ($this->searchResults as $result) {
            if ($result['client_id'] == $clientId) {
                $client = $result;
                break;
            }
        }

        if (! $client) {
            // Client not in current results — search for it directly
            try {
                /** @var PaymentRepository $repo */
                $repo = app(PaymentRepository::class);
                $result = $repo->searchClients($clientId, 'client_id', 1, false);

                if ($result['error'] === null && count($result['results']) > 0) {
                    $client = $result['results'][0];
                }
            } catch (\Exception $e) {
                Log::error('Failed to look up client for selection', ['clientId' => $clientId, 'error' => $e->getMessage()]);
            }
        }

        if (! $client) {
            $this->errorMessage = 'Client not found.';

            return;
        }

        $this->selectedClient = $client;
        $this->searchResults = [];

        $this->dispatch('client-selected', client: $client);
    }

    /**
     * Clear the selected client and reset the search.
     *
     * Dispatches 'client-cleared' to the parent component.
     */
    public function clearClient(): void
    {
        $this->selectedClient = null;
        $this->searchQuery = '';
        $this->searchResults = [];
        $this->errorMessage = null;

        $this->dispatch('client-cleared');
    }

    /**
     * Reset all search state.
     *
     * Triggered by parent components when starting over (e.g., wizard reset).
     */
    #[On('reset-client-search')]
    public function resetSearch(): void
    {
        $this->searchQuery = '';
        $this->searchType = 'name';
        $this->searchResults = [];
        $this->selectedClient = null;
        $this->loading = false;
        $this->errorMessage = null;
    }

    public function render()
    {
        return view('livewire.admin.client-search');
    }
}
