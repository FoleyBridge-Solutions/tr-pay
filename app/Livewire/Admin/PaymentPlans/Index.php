<?php

// app/Livewire/Admin/PaymentPlans/Index.php

namespace App\Livewire\Admin\PaymentPlans;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Payment Plans Index Component
 *
 * Lightweight shell that renders the page heading and action buttons,
 * delegating the heavy table/filters/modals to the lazy-loaded PlansTable child.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.payment-plans.index');
    }
}
