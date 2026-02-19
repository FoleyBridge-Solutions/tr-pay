<?php

// app/Livewire/Admin/ActivityLog.php

namespace App\Livewire\Admin;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Activity Log page shell.
 *
 * Renders the page heading and delegates heavy content
 * to the lazy-loaded ActivityLogTable child component.
 */
#[Layout('layouts::admin')]
class ActivityLog extends Component
{
    public function render()
    {
        return view('livewire.admin.activity-log');
    }
}
