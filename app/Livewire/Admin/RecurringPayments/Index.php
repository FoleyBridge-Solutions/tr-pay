<?php

// app/Livewire/Admin/RecurringPayments/Index.php

namespace App\Livewire\Admin\RecurringPayments;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Recurring Payments Index Page
 *
 * Thin parent shell that renders the page layout and heading.
 * All table logic, filters, sorting, and modals are handled
 * by the lazy-loaded RecurringTable child component.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.recurring-payments.index');
    }
}
