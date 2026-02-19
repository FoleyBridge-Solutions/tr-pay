<?php

// app/Livewire/Admin/ActivityLogTable.php

namespace App\Livewire\Admin;

use App\Models\AdminActivity;
use App\Models\User;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lazy-loaded activity log table with filters.
 *
 * Displays the admin activity audit log with action, user, and model filtering.
 */
#[Lazy]
class ActivityLogTable extends Component
{
    use WithPagination;

    #[Url(as: 'action')]
    public string $filterAction = '';

    #[Url(as: 'user')]
    public string $filterUser = '';

    #[Url(as: 'model')]
    public string $filterModel = '';

    /**
     * Reset pagination when filters change.
     */
    public function updatedFilterAction(): void
    {
        $this->resetPage();
    }

    public function updatedFilterUser(): void
    {
        $this->resetPage();
    }

    public function updatedFilterModel(): void
    {
        $this->resetPage();
    }

    /**
     * Clear all filters.
     */
    public function clearFilters(): void
    {
        $this->reset(['filterAction', 'filterUser', 'filterModel']);
        $this->resetPage();
    }

    /**
     * Get filtered activities.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getActivities(): mixed
    {
        $query = AdminActivity::with('user')
            ->orderBy('created_at', 'desc');

        if ($this->filterAction) {
            $query->where('action', $this->filterAction);
        }

        if ($this->filterUser) {
            $query->where('user_id', $this->filterUser);
        }

        if ($this->filterModel) {
            $query->where('model_type', 'like', "%{$this->filterModel}%");
        }

        return $query->paginate(50);
    }

    /**
     * Get available actions for filter dropdown.
     *
     * @return array<string, string>
     */
    public function getActions(): array
    {
        return [
            AdminActivity::ACTION_CREATED => 'Created',
            AdminActivity::ACTION_UPDATED => 'Updated',
            AdminActivity::ACTION_DELETED => 'Deleted',
            AdminActivity::ACTION_CANCELLED => 'Cancelled',
            AdminActivity::ACTION_PAUSED => 'Paused',
            AdminActivity::ACTION_RESUMED => 'Resumed',
            AdminActivity::ACTION_LOGIN => 'Login',
            AdminActivity::ACTION_LOGOUT => 'Logout',
            AdminActivity::ACTION_IMPORTED => 'Imported',
        ];
    }

    /**
     * Get users for filter dropdown.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUsers(): mixed
    {
        return User::orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * Get model types for filter dropdown.
     *
     * @return array<string, string>
     */
    public function getModelTypes(): array
    {
        return AdminActivity::select('model_type')
            ->distinct()
            ->pluck('model_type')
            ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
            ->toArray();
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <flux:card class="mb-6">
                <div class="p-4">
                    <flux:skeleton.group animate="shimmer">
                        <div class="flex flex-wrap gap-4 items-end">
                            <div class="flex-1 min-w-[150px] space-y-2">
                                <flux:skeleton.line class="w-12" />
                                <flux:skeleton class="h-9 w-full rounded" />
                            </div>
                            <div class="flex-1 min-w-[150px] space-y-2">
                                <flux:skeleton.line class="w-10" />
                                <flux:skeleton class="h-9 w-full rounded" />
                            </div>
                            <div class="flex-1 min-w-[150px] space-y-2">
                                <flux:skeleton.line class="w-14" />
                                <flux:skeleton class="h-9 w-full rounded" />
                            </div>
                        </div>
                    </flux:skeleton.group>
                </div>
            </flux:card>
            <flux:card>
                <flux:skeleton.group animate="shimmer">
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <div class="px-4 py-3 flex items-center gap-4">
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-32" />
                            <flux:skeleton.line class="w-24" />
                        </div>
                        @for ($i = 0; $i < 5; $i++)
                            <div class="px-4 py-3 flex items-center gap-4">
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton.line class="w-32" />
                                <flux:skeleton.line class="w-24" />
                            </div>
                        @endfor
                    </div>
                </flux:skeleton.group>
            </flux:card>
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.admin.activity-log-table', [
            'activities' => $this->getActivities(),
            'actions' => $this->getActions(),
            'users' => $this->getUsers(),
            'modelTypes' => $this->getModelTypes(),
        ]);
    }
}
