<?php

// app/Livewire/Admin/Concerns/SearchesClients.php

namespace App\Livewire\Admin\Concerns;

use App\Repositories\PaymentRepository;
use Illuminate\Support\Facades\Log;

/**
 * Trait for searching clients in PracticeCS (SQL Server).
 *
 * Delegates all queries to PaymentRepository::searchClients() so that
 * the column list and connection are defined in a single place.
 *
 * Components using this trait must declare these properties:
 * - string $searchType = 'name'
 * - array $searchResults = []
 *
 * And one of:
 * - string $searchQuery (default) — used by Create wizards
 * - string $search — used by Index pages (override via searchQueryProperty())
 *
 * Optionally:
 * - string|null $errorMessage — set on validation/search errors
 * - bool $loading — toggled during search
 */
trait SearchesClients
{
    /**
     * Get the property name that holds the search query.
     *
     * Override this in components that use a different property (e.g., '$search').
     */
    protected function searchQueryProperty(): string
    {
        return 'searchQuery';
    }

    /**
     * Get the maximum number of results to return.
     *
     * Override this in components that need a different limit.
     */
    protected function searchResultsLimit(): int
    {
        return 20;
    }

    /**
     * Whether to sanitize tax ID input (strip non-digits, validate length).
     *
     * Override to return false for simpler tax ID handling.
     */
    protected function sanitizeTaxIdSearch(): bool
    {
        return true;
    }

    /**
     * Search for clients in PracticeCS.
     */
    public function searchClients(): void
    {
        $this->searchResults = [];

        $queryProp = $this->searchQueryProperty();
        $query = $this->{$queryProp};
        $limit = $this->searchResultsLimit();

        // Set loading state if available
        if (property_exists($this, 'loading')) {
            $this->loading = true;
        }

        // Clear error message if available
        if (property_exists($this, 'errorMessage')) {
            $this->errorMessage = null;
        }

        if (strlen($query) < 2) {
            if (property_exists($this, 'loading')) {
                $this->loading = false;
            }

            return;
        }

        try {
            /** @var PaymentRepository $repo */
            $repo = app(PaymentRepository::class);

            $result = $repo->searchClients(
                $query,
                $this->searchType,
                $limit,
                $this->sanitizeTaxIdSearch()
            );

            if ($result['error'] !== null) {
                if (property_exists($this, 'errorMessage')) {
                    $this->errorMessage = $result['error'];
                }

                return;
            }

            $this->searchResults = $result['results'];
        } catch (\Exception $e) {
            Log::error('Client search failed', ['error' => $e->getMessage()]);

            if (property_exists($this, 'errorMessage')) {
                $this->errorMessage = 'Failed to search clients. Please try again.';
            }
        } finally {
            if (property_exists($this, 'loading')) {
                $this->loading = false;
            }
        }
    }
}
