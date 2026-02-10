<?php

namespace App\Livewire\Admin\Clients;

use App\Livewire\Admin\Concerns\SearchesClients;
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
    use SearchesClients;

    #[Url(as: 'q')]
    public string $search = '';

    public string $searchType = 'name';

    public array $searchResults = [];

    public bool $loading = false;

    /**
     * Use '$search' property instead of '$searchQuery'.
     */
    protected function searchQueryProperty(): string
    {
        return 'search';
    }

    /**
     * Return more results for the clients index page.
     */
    protected function searchResultsLimit(): int
    {
        return 50;
    }

    /**
     * Use simple tax ID matching without sanitization.
     */
    protected function sanitizeTaxIdSearch(): bool
    {
        return false;
    }

    public function render()
    {
        return view('livewire.admin.clients.index');
    }
}
