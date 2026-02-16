<div wire:poll.60s>
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="ghost" size="sm" class="relative">
            <flux:icon name="bell" class="w-5 h-5" />
            @if($this->unreadCount > 0)
                <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold text-white bg-red-500 rounded-full">
                    {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
                </span>
            @endif
        </flux:button>

        <flux:menu class="w-80">
            <div class="px-3 py-2 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                <span class="text-sm font-semibold text-zinc-900 dark:text-white">Notifications</span>
                @if($this->unreadCount > 0)
                    <button wire:click="markAllAsRead" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        Mark all read
                    </button>
                @endif
            </div>

            @forelse($this->latestNotifications as $notification)
                <flux:menu.item wire:click="markAsRead('{{ $notification->id }}')">
                    <div class="flex items-start gap-2 py-1">
                        @php
                            $severity = $notification->data['severity'] ?? 'info';
                            $dotColor = match($severity) {
                                'critical' => 'bg-red-500',
                                'warning' => 'bg-amber-500',
                                default => 'bg-blue-500',
                            };
                        @endphp
                        <span class="mt-1.5 w-2 h-2 rounded-full shrink-0 {{ $dotColor }}"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white truncate">
                                {{ $notification->data['title'] ?? 'Notification' }}
                            </p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">
                                {{ $notification->data['message'] ?? '' }}
                            </p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-0.5">
                                {{ $notification->created_at->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                </flux:menu.item>
            @empty
                <div class="px-3 py-6 text-center">
                    <flux:icon name="bell-slash" class="w-8 h-8 text-zinc-300 dark:text-zinc-600 mx-auto mb-2" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">No unread notifications</p>
                </div>
            @endforelse

            <div class="border-t border-zinc-200 dark:border-zinc-700">
                <flux:menu.item href="{{ route('admin.notifications') }}">
                    <span class="text-sm text-blue-600 dark:text-blue-400 w-full text-center">View all notifications</span>
                </flux:menu.item>
            </div>
        </flux:menu>
    </flux:dropdown>
</div>
