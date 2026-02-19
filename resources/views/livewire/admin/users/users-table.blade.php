<div>
    {{-- Users Table --}}
    <flux:card>
        @if($users->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="user-circle" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No users found</flux:heading>
                <flux:text class="text-zinc-500 mb-4">Create your first admin user</flux:text>
                <flux:button wire:click="openCreateModal" variant="primary" icon="plus">
                    Add User
                </flux:button>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Email</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Last Login</flux:table.column>
                    <flux:table.column>Created</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($users as $user)
                        <flux:table.row wire:key="user-{{ $user->id }}">
                            <flux:table.cell>
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                        <span class="text-sm font-medium text-zinc-600 dark:text-zinc-400">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </span>
                                    </div>
                                    <span class="font-medium">{{ $user->name }}</span>
                                    @if($user->id === Auth::id())
                                        <flux:badge size="sm">You</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $user->email }}</flux:table.cell>
                            <flux:table.cell>
                                @if($user->is_active)
                                    <flux:badge color="green" size="sm">Active</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                @if($user->last_login_at)
                                    <local-time datetime="{{ $user->last_login_at->toIso8601String() }}" format="relative"></local-time>
                                @else
                                    Never
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                <local-time datetime="{{ $user->created_at->toIso8601String() }}" format="date"></local-time>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-1">
                                    <flux:button wire:click="editUser({{ $user->id }})" variant="ghost" size="sm" icon="pencil">
                                        Edit
                                    </flux:button>
                                    @if($user->id !== Auth::id())
                                        <flux:button
                                            wire:click="toggleUserStatus({{ $user->id }})"
                                            variant="ghost"
                                            size="sm"
                                            :icon="$user->is_active ? 'x-mark' : 'check'"
                                            class="{{ $user->is_active ? 'text-red-600 hover:text-red-700' : 'text-green-600 hover:text-green-700' }}"
                                        >
                                            {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                        </flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if($users->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                    {{ $users->links() }}
                </div>
            @endif
        @endif
    </flux:card>

    {{-- Create User Modal --}}
    <flux:modal wire:model.self="showCreateModal" class="max-w-md" @close="resetCreateModal">
        <div class="p-6">
            <flux:heading size="lg" class="mb-4">Add New User</flux:heading>

            <form wire:submit="createUser" class="space-y-4">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model="name" placeholder="John Doe" />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Email</flux:label>
                    <flux:input wire:model="email" type="email" placeholder="john@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>Password</flux:label>
                    <flux:input wire:model="password" type="password" placeholder="Minimum 8 characters" />
                    <flux:error name="password" />
                </flux:field>

                <flux:field>
                    <flux:label>Confirm Password</flux:label>
                    <flux:input wire:model="passwordConfirmation" type="password" placeholder="Confirm password" />
                    <flux:error name="passwordConfirmation" />
                </flux:field>

                <div class="flex justify-end gap-2 pt-4">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">
                        Create User
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Edit User Modal --}}
    <flux:modal wire:model.self="showEditModal" class="max-w-md" @close="resetEditModal">
        <div class="p-6">
            <flux:heading size="lg" class="mb-4">Edit User</flux:heading>

            <form wire:submit="updateUser" class="space-y-4">
                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input wire:model="editName" placeholder="John Doe" />
                    <flux:error name="editName" />
                </flux:field>

                <flux:field>
                    <flux:label>Email</flux:label>
                    <flux:input wire:model="editEmail" type="email" placeholder="john@example.com" />
                    <flux:error name="editEmail" />
                </flux:field>

                <flux:field>
                    <flux:label>New Password</flux:label>
                    <flux:input wire:model="editPassword" type="password" placeholder="Leave blank to keep current" />
                    <flux:description>Leave blank to keep the current password</flux:description>
                    <flux:error name="editPassword" />
                </flux:field>

                @if($editingUser && $editingUser->id !== Auth::id())
                    <flux:field>
                        <flux:switch wire:model="editIsActive" label="Active" description="Inactive users cannot log in" />
                    </flux:field>
                @endif

                <div class="flex justify-end gap-2 pt-4">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">
                        Save Changes
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
