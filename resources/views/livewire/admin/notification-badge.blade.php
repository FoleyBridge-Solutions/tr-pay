<div wire:poll.30s>
    {{-- Red dot on profile --}}
    @if($this->unreadCount > 0)
        <span class="absolute top-1 right-1 w-2.5 h-2.5 bg-red-500 rounded-full ring-2 ring-zinc-50 dark:ring-zinc-900 pointer-events-none"></span>
    @endif
</div>
