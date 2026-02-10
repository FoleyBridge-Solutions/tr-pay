<?php

namespace App\Livewire\Admin;

use App\Models\AdminActivity;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Activity Log Component
 *
 * Displays admin activity audit log with filtering.
 */
#[Layout('layouts.admin')]
class ActivityLog extends Component
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
     */
    public function getActivities()
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
     */
    public function getUsers()
    {
        return User::orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * Get model types for filter dropdown.
     */
    public function getModelTypes(): array
    {
        return AdminActivity::select('model_type')
            ->distinct()
            ->pluck('model_type')
            ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.admin.activity-log', [
            'activities' => $this->getActivities(),
            'actions' => $this->getActions(),
            'users' => $this->getUsers(),
            'modelTypes' => $this->getModelTypes(),
        ]);
    }
}
