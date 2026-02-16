<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Clients</flux:heading>
            <flux:subheading>Search and view client information from PracticeCS</flux:subheading>
        </div>
    </div>

    <flux:card>
        <div class="p-4">
            <livewire:admin.client-search mode="browse" :limit="50" />
        </div>
    </flux:card>
</div>
