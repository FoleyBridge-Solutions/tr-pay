<?php

// app/Livewire/Admin/Ach/Batches/Show.php

namespace App\Livewire\Admin\Ach\Batches;

use App\Models\Ach\AchBatch;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * ACH Batch show page.
 *
 * Handles route-model binding and delegates heavy content
 * to the lazy-loaded BatchDetails child component.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    public AchBatch $batch;

    /**
     * Mount the component with route-model binding.
     */
    public function mount(AchBatch $batch): void
    {
        $this->batch = $batch;
    }

    public function render()
    {
        return view('livewire.admin.ach.batches.show');
    }
}
