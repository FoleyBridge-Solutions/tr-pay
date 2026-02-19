<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Activity Log</flux:heading>
            <flux:subheading>Audit trail of admin actions</flux:subheading>
        </div>
    </div>

    {{-- Filters + Table (lazy loaded) --}}
    <livewire:admin.activity-log-table />
</div>
