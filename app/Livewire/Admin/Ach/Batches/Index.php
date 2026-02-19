<?php

// app/Livewire/Admin/Ach/Batches/Index.php

namespace App\Livewire\Admin\Ach\Batches;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * ACH Batches index page.
 *
 * Renders the page layout and heading; the heavy table content
 * is delegated to the lazy-loaded BatchesTable child component.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    /**
     * Render the component.
     */
    public function render()
    {
        return view('livewire.admin.ach.batches.index');
    }
}
