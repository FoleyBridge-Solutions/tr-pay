<div wire:poll.30s>
    @if($alerts->isNotEmpty())
        <div class="mb-8">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">Active Alerts</flux:heading>
                <flux:button href="{{ route('admin.notifications') }}" variant="ghost" size="sm">
                    View All
                </flux:button>
            </div>

            <div class="space-y-3">
                @foreach($alerts as $alert)
                    @php
                        $severity = $alert->data['severity'] ?? 'info';
                        $alertStyles = match($severity) {
                            'critical' => 'border-red-500 bg-red-50 dark:bg-red-950/30',
                            'warning' => 'border-amber-500 bg-amber-50 dark:bg-amber-950/30',
                            default => 'border-blue-500 bg-blue-50 dark:bg-blue-950/30',
                        };
                        $iconName = match($severity) {
                            'critical' => 'exclamation-circle',
                            'warning' => 'exclamation-triangle',
                            default => 'information-circle',
                        };
                        $iconColor = match($severity) {
                            'critical' => 'text-red-600 dark:text-red-400',
                            'warning' => 'text-amber-600 dark:text-amber-400',
                            default => 'text-blue-600 dark:text-blue-400',
                        };
                    @endphp
                    <div class="border-l-4 {{ $alertStyles }} rounded-r-lg px-4 py-3 flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <flux:icon name="{{ $iconName }}" class="w-5 h-5 {{ $iconColor }} shrink-0 mt-0.5" />
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                                    {{ $alert->data['title'] ?? 'Alert' }}
                                </p>
                                <p class="text-sm text-zinc-600 dark:text-zinc-300 mt-0.5">
                                    {{ $alert->data['message'] ?? '' }}
                                </p>
                                <div class="flex items-center gap-3 mt-1">
                                    <span class="text-xs text-zinc-400">{{ $alert->created_at->diffForHumans() }}</span>
                                    @if(isset($alert->data['action_url']))
                                        <a href="{{ $alert->data['action_url'] }}"
                                           class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400">
                                            {{ $alert->data['action_label'] ?? 'View Details' }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <flux:button wire:click="dismissAlert('{{ $alert->id }}')" variant="ghost" size="sm" icon="x-mark" title="Dismiss" />
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
