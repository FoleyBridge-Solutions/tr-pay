<?php

namespace App\Livewire\Admin\Payments;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Payments Index page shell.
 *
 * Renders the page heading and delegates heavy content
 * to the lazy-loaded PaymentsTable child component.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    /**
     * Render the component.
     */
    public function render()
    {
        return view('livewire.admin.payments.index');
    }
}
