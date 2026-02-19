<?php

// app/Livewire/Admin/PaymentRequests/Index.php

namespace App\Livewire\Admin\PaymentRequests;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin Payment Requests Index Component
 *
 * Provides the page layout shell for payment requests.
 * Heavy content is lazy-loaded via the RequestsTable child component.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.payment-requests.index');
    }
}
