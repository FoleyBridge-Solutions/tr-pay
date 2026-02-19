<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Users</flux:heading>
            <flux:subheading>Manage admin users</flux:subheading>
        </div>
    </div>

    {{-- Users table with modals (lazy loaded) --}}
    <livewire:admin.users.users-table />
</div>
