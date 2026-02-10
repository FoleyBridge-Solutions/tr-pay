<?php

// app/Livewire/Admin/Concerns/SearchesClients.php

namespace App\Livewire\Admin\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trait for searching clients in PracticeCS (SQL Server).
 *
 * Provides a configurable client search with support for:
 * - Name search (description, first/last name)
 * - Client ID search
 * - Tax ID search (last 4 digits of SSN/EIN)
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
            if ($this->searchType === 'client_id') {
                $result = DB::connection('sqlsrv')->select("
                    SELECT TOP {$limit}
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE client_id LIKE ?
                    ORDER BY description
                ", ["%{$query}%"]);
            } elseif ($this->searchType === 'tax_id') {
                if ($this->sanitizeTaxIdSearch()) {
                    // Strip non-digits and validate
                    $last4 = preg_replace('/\D/', '', $query);
                    if (strlen($last4) !== 4) {
                        if (property_exists($this, 'errorMessage')) {
                            $this->errorMessage = 'Please enter exactly 4 digits for Tax ID search.';
                        }

                        if (property_exists($this, 'loading')) {
                            $this->loading = false;
                        }

                        return;
                    }

                    $result = DB::connection('sqlsrv')->select("
                        SELECT TOP {$limit}
                            client_KEY,
                            client_id,
                            description AS client_name,
                            individual_first_name,
                            individual_last_name,
                            federal_tin
                        FROM Client
                        WHERE RIGHT(REPLACE(REPLACE(federal_tin, '-', ''), ' ', ''), 4) = ?
                        ORDER BY description
                    ", [$last4]);
                } else {
                    // Simple tax ID search (no sanitization)
                    $result = DB::connection('sqlsrv')->select("
                        SELECT TOP {$limit}
                            client_KEY,
                            client_id,
                            description AS client_name,
                            individual_first_name,
                            individual_last_name,
                            federal_tin
                        FROM Client
                        WHERE RIGHT(federal_tin, 4) = ?
                        ORDER BY description
                    ", [$query]);
                }
            } else {
                $result = DB::connection('sqlsrv')->select("
                    SELECT TOP {$limit}
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE description LIKE ?
                       OR individual_last_name LIKE ?
                       OR individual_first_name LIKE ?
                    ORDER BY description
                ", ["%{$query}%", "%{$query}%", "%{$query}%"]);
            }

            $this->searchResults = array_map(fn ($r) => (array) $r, $result);
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
