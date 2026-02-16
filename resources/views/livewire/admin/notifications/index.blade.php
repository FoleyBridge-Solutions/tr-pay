<div>
    <div class="mb-8">
        <flux:heading size="xl">Notifications</flux:heading>
        <flux:subheading>System alerts and notifications</flux:subheading>
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-end gap-4">
        <div>
            <flux:select wire:model.live="filterStatus" label="Status" class="w-40">
                <option value="">All</option>
                <option value="unread">Unread</option>
                <option value="read">Read</option>
            </flux:select>
        </div>

        <div>
            <flux:select wire:model.live="filterCategory" label="Category" class="w-44">
                <option value="">All Categories</option>
                @foreach($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
        </div>

        <div>
            <flux:select wire:model.live="filterSeverity" label="Severity" class="w-40">
                <option value="">All Severities</option>
                @foreach($severities as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
        </div>

        @if($filterStatus || $filterCategory || $filterSeverity)
            <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">
                Clear Filters
            </flux:button>
        @endif

        <div class="ml-auto flex gap-2">
            <flux:button wire:click="markAllAsRead" variant="ghost" size="sm" icon="check">
                Mark All Read
            </flux:button>
            <flux:button wire:click="deleteAllRead" variant="ghost" size="sm" icon="trash"
                wire:confirm="Delete all read notifications?">
                Delete Read
            </flux:button>
        </div>
    </div>

    {{-- Notifications List --}}
    <flux:card>
        @forelse($notifications as $notification)
            @php
                $severity = $notification->data['severity'] ?? 'info';
                $isUnread = is_null($notification->read_at);

                $borderColor = match($severity) {
                    'critical' => 'border-l-red-500',
                    'warning' => 'border-l-amber-500',
                    default => 'border-l-blue-500',
                };

                $bgColor = $isUnread ? 'bg-zinc-50 dark:bg-zinc-800/50' : '';

                $categoryLabel = match($notification->data['category'] ?? '') {
                    'practicecs' => 'PracticeCS',
                    'payment' => 'Payment',
                    'ach' => 'ACH',
                    'plan' => 'Payment Plan',
                    'payment_method' => 'Payment Method',
                    default => ucfirst($notification->data['category'] ?? 'System'),
                };

                $severityColor = match($severity) {
                    'critical' => 'red',
                    'warning' => 'amber',
                    default => 'blue',
                };
            @endphp

            <div class="border-l-4 {{ $borderColor }} {{ $bgColor }} px-4 py-3 {{ !$loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            @if($isUnread)
                                <span class="w-2 h-2 rounded-full bg-blue-500 shrink-0"></span>
                            @endif
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ $notification->data['title'] ?? 'Notification' }}
                            </span>
                            <flux:badge color="{{ $severityColor }}" size="sm">{{ ucfirst($severity) }}</flux:badge>
                            <flux:badge size="sm">{{ $categoryLabel }}</flux:badge>
                        </div>

                        <p class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $notification->data['message'] ?? '' }}
                        </p>

                        <div class="flex items-center gap-4 mt-2">
                            <span class="text-xs text-zinc-400 dark:text-zinc-500">
                                {{ $notification->created_at->diffForHumans() }}
                                &mdash;
                                {{ $notification->created_at->format('M j, Y g:i A') }}
                            </span>

                            @if(isset($notification->data['action_url']))
                                <a href="{{ $notification->data['action_url'] }}"
                                   class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                    {{ $notification->data['action_label'] ?? 'View Details' }}
                                </a>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-1 shrink-0">
                        @if($isUnread)
                            <flux:button wire:click="markAsRead('{{ $notification->id }}')" variant="ghost" size="sm" icon="check" title="Mark as read" />
                        @endif
                        <flux:button wire:click="deleteNotification('{{ $notification->id }}')" variant="ghost" size="sm" icon="trash" title="Delete"
                            wire:confirm="Delete this notification?" />
                    </div>
                </div>
            </div>
        @empty
            <div class="py-12 text-center">
                <flux:icon name="bell-slash" class="w-12 h-12 text-zinc-300 dark:text-zinc-600 mx-auto mb-3" />
                <flux:heading size="lg" class="text-zinc-500">No notifications</flux:heading>
                <flux:subheading>
                    @if($filterStatus || $filterCategory || $filterSeverity)
                        No notifications match your filters.
                    @else
                        You're all caught up! Notifications will appear here when system events occur.
                    @endif
                </flux:subheading>
            </div>
        @endforelse
    </flux:card>

    {{-- Pagination --}}
    @if($notifications->hasPages())
        <div class="mt-4">
            {{ $notifications->links() }}
        </div>
    @endif
</div>
