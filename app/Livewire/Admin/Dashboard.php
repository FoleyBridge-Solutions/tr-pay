<?php

// app/Livewire/Admin/Dashboard.php

namespace App\Livewire\Admin;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin Dashboard Component
 *
 * Lightweight shell that renders lazy-loaded child components
 * for stats, alerts, recent payments, and recent plans.
 */
#[Layout('layouts::admin')]
class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.admin.dashboard');
    }
}
