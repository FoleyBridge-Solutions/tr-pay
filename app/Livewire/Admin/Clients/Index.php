<?php

namespace App\Livewire\Admin\Clients;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Clients Index Component
 *
 * Search and view client information from PracticeCS.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public string $searchType = 'name';

    public array $searchResults = [];

    public bool $loading = false;

    /**
     * Search for clients.
     */
    public function searchClients(): void
    {
        $this->searchResults = [];
        $this->loading = true;

        if (strlen($this->search) < 2) {
            $this->loading = false;

            return;
        }

        try {
            if ($this->searchType === 'client_id') {
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 50
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE client_id LIKE ?
                    ORDER BY description
                ', ["%{$this->search}%"]);
            } elseif ($this->searchType === 'tax_id') {
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 50
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE RIGHT(federal_tin, 4) = ?
                    ORDER BY description
                ', [$this->search]);
            } else {
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 50
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
                ', ["%{$this->search}%", "%{$this->search}%", "%{$this->search}%"]);
            }

            $this->searchResults = array_map(fn ($r) => (array) $r, $result);
        } catch (\Exception $e) {
            Log::error('Client search failed', ['error' => $e->getMessage()]);
        } finally {
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('livewire.admin.clients.index');
    }
}
