<?php

// app/Livewire/Admin/Clients/Show.php

namespace App\Livewire\Admin\Clients;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Client Show Component (parent shell).
 *
 * Receives the client ID from the route and delegates all heavy
 * data loading to the lazy-loaded ClientDetails child component.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    public string $clientId;

    /**
     * Mount the component with the client ID from the route.
     */
    public function mount(string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function render()
    {
        return view('livewire.admin.clients.show');
    }
}
