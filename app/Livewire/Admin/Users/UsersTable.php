<?php

// app/Livewire/Admin/Users/UsersTable.php

namespace App\Livewire\Admin\Users;

use App\Models\AdminActivity;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lazy-loaded users table with create/edit modals.
 *
 * Displays paginated admin users with inline status toggling,
 * and provides modals for creating and editing users.
 */
#[Lazy]
class UsersTable extends Component
{
    use WithPagination;

    public bool $showCreateModal = false;

    public bool $showEditModal = false;

    public ?User $editingUser = null;

    // Create form
    #[Validate('required|min:2')]
    public string $name = '';

    #[Validate('required|email|unique:users,email')]
    public string $email = '';

    #[Validate('required|min:8')]
    public string $password = '';

    #[Validate('required|same:password')]
    public string $passwordConfirmation = '';

    // Edit form
    #[Validate('required|min:2')]
    public string $editName = '';

    #[Validate('required|email')]
    public string $editEmail = '';

    public string $editPassword = '';

    public bool $editIsActive = true;

    /**
     * Open create modal.
     */
    public function openCreateModal(): void
    {
        $this->reset(['name', 'email', 'password', 'passwordConfirmation']);
        $this->resetValidation();
        $this->showCreateModal = true;
    }

    /**
     * Reset create modal state when closed.
     */
    public function resetCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->reset(['name', 'email', 'password', 'passwordConfirmation']);
        $this->resetValidation();
    }

    /**
     * Create a new user.
     */
    public function createUser(): void
    {
        $this->validate([
            'name' => 'required|min:2',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'passwordConfirmation' => 'required|same:password',
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'is_active' => true,
        ]);

        AdminActivity::logCreated($user, "Created admin user: {$user->email}");

        $this->showCreateModal = false;
        $this->reset(['name', 'email', 'password', 'passwordConfirmation']);

        Flux::toast('User created successfully.');
    }

    /**
     * Open edit modal for a user.
     *
     * @param  int  $userId  The user's database ID
     */
    public function editUser(int $userId): void
    {
        $this->editingUser = User::find($userId);

        if (! $this->editingUser) {
            return;
        }

        $this->editName = $this->editingUser->name;
        $this->editEmail = $this->editingUser->email;
        $this->editPassword = '';
        $this->editIsActive = $this->editingUser->is_active;

        $this->resetValidation();
        $this->showEditModal = true;
    }

    /**
     * Reset edit modal state when closed.
     */
    public function resetEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingUser = null;
        $this->reset(['editName', 'editEmail', 'editPassword']);
        $this->editIsActive = true;
        $this->resetValidation();
    }

    /**
     * Update the user.
     */
    public function updateUser(): void
    {
        if (! $this->editingUser) {
            return;
        }

        $rules = [
            'editName' => 'required|min:2',
            'editEmail' => 'required|email|unique:users,email,'.$this->editingUser->id,
        ];

        if ($this->editPassword) {
            $rules['editPassword'] = 'min:8';
        }

        $this->validate($rules);

        $oldValues = $this->editingUser->only(['name', 'email', 'is_active']);

        $this->editingUser->name = $this->editName;
        $this->editingUser->email = $this->editEmail;
        $this->editingUser->is_active = $this->editIsActive;

        if ($this->editPassword) {
            $this->editingUser->password = $this->editPassword;
        }

        $this->editingUser->save();

        AdminActivity::logUpdated(
            $this->editingUser,
            "Updated admin user: {$this->editingUser->email}",
            $oldValues,
            $this->editingUser->only(['name', 'email', 'is_active'])
        );

        $this->showEditModal = false;
        $this->editingUser = null;

        Flux::toast('User updated successfully.');
    }

    /**
     * Toggle user active status.
     *
     * @param  int  $userId  The user's database ID
     */
    public function toggleUserStatus(int $userId): void
    {
        $user = User::find($userId);

        if (! $user) {
            return;
        }

        // Don't allow deactivating yourself
        if ($user->id === Auth::id()) {
            Flux::toast('You cannot deactivate your own account.', variant: 'danger');

            return;
        }

        $wasActive = $user->is_active;
        $user->is_active = ! $user->is_active;
        $user->save();

        AdminActivity::logUpdated(
            $user,
            $user->is_active ? "Activated admin user: {$user->email}" : "Deactivated admin user: {$user->email}",
            ['is_active' => $wasActive],
            ['is_active' => $user->is_active]
        );

        Flux::toast($user->is_active ? 'User activated.' : 'User deactivated.');
    }

    /**
     * Get all users paginated.
     */
    public function getUsers(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return User::orderBy('name')->paginate(20);
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <flux:card>
                <flux:skeleton.group animate="shimmer">
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        {{-- Table header --}}
                        <div class="px-4 py-3 flex items-center gap-4">
                            <flux:skeleton.line class="w-32" />
                            <flux:skeleton.line class="w-40" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-20" />
                        </div>
                        {{-- Table rows --}}
                        @for ($i = 0; $i < 5; $i++)
                            <div class="px-4 py-3 flex items-center gap-4">
                                <div class="flex items-center gap-3">
                                    <flux:skeleton class="size-8 rounded-full" />
                                    <flux:skeleton.line class="w-28" />
                                </div>
                                <flux:skeleton.line class="w-40" />
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton.line class="w-20" />
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
        return view('livewire.admin.users.users-table', [
            'users' => $this->getUsers(),
        ]);
    }
}
