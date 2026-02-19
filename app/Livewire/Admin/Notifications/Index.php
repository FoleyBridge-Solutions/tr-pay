<?php

// app/Livewire/Admin/Notifications/Index.php

namespace App\Livewire\Admin\Notifications;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Notifications Index Page.
 *
 * Parent shell that renders the page heading and lazy-loads
 * the NotificationsList child component.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.notifications.index');
    }
}
