<?php

// app/Livewire/Admin/Clients/Index.php

namespace App\Livewire\Admin\Clients;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Clients Index Component
 *
 * Search and view client information from PracticeCS.
 * Delegates all search functionality to the ClientSearch component.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.clients.index');
    }
}
