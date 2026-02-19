<?php

namespace App\Livewire\Admin\Users;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Users Index Page
 *
 * Layout shell for the admin users management page.
 * Heavy content is lazy-loaded via the UsersTable child component.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.users.index');
    }
}
