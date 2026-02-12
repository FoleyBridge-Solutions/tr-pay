<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Activity Log</flux:heading>
            <flux:subheading>Audit trail of admin actions</flux:subheading>
        </div>
    </div>

    {{-- Filters --}}
    <flux:card class="mb-6">
        <div class="p-4">
            <div class="flex flex-wrap gap-4 items-end">
                <flux:field class="flex-1 min-w-[150px]">
                    <flux:label>Action</flux:label>
                    <flux:select wire:model.live="filterAction" placeholder="All actions">
                        <flux:select.option value="">All actions</flux:select.option>
                        @foreach($actions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field class="flex-1 min-w-[150px]">
                    <flux:label>User</flux:label>
                    <flux:select wire:model.live="filterUser" placeholder="All users">
                        <flux:select.option value="">All users</flux:select.option>
                        @foreach($users as $user)
                            <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field class="flex-1 min-w-[150px]">
                    <flux:label>Model</flux:label>
                    <flux:select wire:model.live="filterModel" placeholder="All models">
                        <flux:select.option value="">All models</flux:select.option>
                        @foreach($modelTypes as $type => $label)
                            <flux:select.option value="{{ $label }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                @if($filterAction || $filterUser || $filterModel)
                    <flux:button wire:click="clearFilters" variant="ghost" icon="x-mark">
                        Clear
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:card>

    {{-- Activity Table --}}
    <flux:card>
        @if($activities->isEmpty())
            <div class="p-12 text-center">
                <flux:icon name="clipboard-document-list" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No activity found</flux:heading>
                <flux:text class="text-zinc-500">Activity will appear here as admins perform actions.</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date/Time</flux:table.column>
                    <flux:table.column>User</flux:table.column>
                    <flux:table.column>Action</flux:table.column>
                    <flux:table.column>Model</flux:table.column>
                    <flux:table.column>Description</flux:table.column>
                    <flux:table.column>IP Address</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($activities as $activity)
                        <flux:table.row wire:key="activity-{{ $activity->id }}">
                            <flux:table.cell class="text-zinc-500 whitespace-nowrap">
                                <div class="text-sm"><local-time datetime="{{ $activity->created_at->toIso8601String() }}" format="date"></local-time></div>
                                <div class="text-xs text-zinc-400"><local-time datetime="{{ $activity->created_at->toIso8601String() }}" format="time"></local-time></div>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($activity->user)
                                    <div class="flex items-center gap-2">
                                        <div class="w-6 h-6 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                                {{ strtoupper(substr($activity->user->name, 0, 1)) }}
                                            </span>
                                        </div>
                                        <span class="text-sm">{{ $activity->user->name }}</span>
                                    </div>
                                @else
                                    <span class="text-zinc-400">System</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @php
                                    $badgeColor = match($activity->action) {
                                        'created' => 'green',
                                        'updated' => 'blue',
                                        'deleted' => 'red',
                                        'cancelled' => 'orange',
                                        'paused' => 'yellow',
                                        'resumed' => 'cyan',
                                        'login' => 'lime',
                                        'logout' => 'zinc',
                                        'imported' => 'purple',
                                        default => 'zinc',
                                    };
                                @endphp
                                <flux:badge color="{{ $badgeColor }}" size="sm">
                                    {{ $activity->action_label }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="text-sm">{{ $activity->model_name }}</span>
                                @if($activity->model_id)
                                    <span class="text-xs text-zinc-400">#{{ $activity->model_id }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-w-xs">
                                <span class="text-sm text-zinc-600 dark:text-zinc-400 truncate block">
                                    {{ $activity->description ?? '-' }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500 text-sm">
                                {{ $activity->ip_address ?? '-' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if($activities->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                    {{ $activities->links() }}
                </div>
            @endif
        @endif
    </flux:card>
</div>
