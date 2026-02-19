<?php

// app/Livewire/Admin/Ach/Returns/Index.php

namespace App\Livewire\Admin\Ach\Returns;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * ACH Returns index page shell.
 *
 * Renders the page heading and delegates the heavy content
 * to the lazy-loaded ReturnsTable child component.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.ach.returns.index');
    }
}
