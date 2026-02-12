<?php

namespace App\Livewire\Admin\Ach\Batches;

use App\Models\Ach\AchBatch;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::admin')]
class Show extends Component
{
    public AchBatch $batch;

    public function mount(AchBatch $batch): void
    {
        $this->batch = $batch->load(['entries']);
    }

    public function render()
    {
        return view('livewire.admin.ach.batches.show');
    }
}
