{{-- 
    Payment Skeleton Loading Component
    
    Shows a skeleton loading state between step transitions.
    Auto-advances to next step after duration or when data is ready.
    
    @props:
    - type: string - Type of skeleton: 'form', 'table', 'cards', 'processing', 'generic'
    - title: string - Title to show during loading
    - subtitle: string - Subtitle/description
    - duration: int - Minimum display time in ms (default: 800)
    - onComplete: string - Livewire method to call when skeleton completes
--}}

@props([
    'type' => 'generic',
    'title' => 'Loading...',
    'subtitle' => 'Please wait while we prepare your information',
    'duration' => 800,
    'onComplete' => 'onSkeletonComplete',
])

<div 
    wire:key="skeleton-{{ $type }}"
    x-data="{ 
        visible: true,
        init() {
            @if($duration > 0)
            setTimeout(() => {
                $wire.{{ $onComplete }}();
            }, {{ $duration }});
            @endif
        }
    }"
    x-show="visible"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-95"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-95"
    class="payment-skeleton"
>
    <flux:card class="p-8">
        {{-- Header --}}
        <div class="mb-6 text-center">
            <flux:heading size="xl" class="mb-2">{{ $title }}</flux:heading>
            <flux:subheading>{{ $subtitle }}</flux:subheading>
        </div>

        {{-- Skeleton Content based on type --}}
        @switch($type)
            @case('form')
                {{-- Form skeleton (used after account type selection for verify step) --}}
                <flux:skeleton.group animate="shimmer">
                    <div class="space-y-6 max-w-md mx-auto">
                        <div>
                            <flux:skeleton.line class="w-1/4 mb-2" />
                            <flux:skeleton class="h-10 w-full rounded-lg" />
                            <flux:skeleton.line class="w-3/4 mt-2" />
                        </div>
                        <div>
                            <flux:skeleton.line class="w-1/3 mb-2" />
                            <flux:skeleton class="h-10 w-full rounded-lg" />
                            <flux:skeleton.line class="w-1/2 mt-2" />
                        </div>
                        <div class="flex gap-3 pt-4">
                            <flux:skeleton class="h-10 w-20 rounded-lg" />
                            <flux:skeleton class="h-10 flex-1 rounded-lg" />
                        </div>
                    </div>
                </flux:skeleton.group>
                @break

            @case('table')
                {{-- Invoice selection skeleton - matches actual invoice-selection step layout --}}
                <flux:skeleton.group animate="shimmer">
                    <div class="space-y-6">
                        {{-- Account info header --}}
                        <div class="bg-zinc-100 dark:bg-zinc-800 p-4 rounded-lg">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <flux:subheading>Account</flux:subheading>
                                    <flux:skeleton.line class="w-32 mt-1" />
                                </div>
                                <div class="text-right">
                                    <flux:subheading>Client ID</flux:subheading>
                                    <flux:skeleton.line class="w-20 mt-1 ml-auto" />
                                </div>
                            </div>
                        </div>

                        {{-- Section heading with invoice count --}}
                        <div class="flex justify-between items-center">
                            <div>
                                <flux:skeleton.line class="w-40 mb-2" />
                                <flux:skeleton.line class="w-56" />
                            </div>
                        </div>

                        {{-- Client invoice card (primary client style) --}}
                        <div class="bg-white dark:bg-zinc-900 border-2 border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                            {{-- Client header --}}
                            <div class="px-6 py-4 bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-200 dark:border-zinc-700">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <flux:skeleton class="size-8 rounded-full" />
                                        <div>
                                            <flux:skeleton.line class="w-32 mb-1" />
                                            <flux:skeleton.line class="w-24" />
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <flux:skeleton.line class="w-24 mb-1" />
                                        <flux:skeleton.line class="w-20 ml-auto" />
                                    </div>
                                </div>
                            </div>

                            {{-- Invoice table (matches actual column structure) --}}
                            <div class="p-6">
                                <flux:table>
                                    <flux:table.columns>
                                        <flux:table.column></flux:table.column>
                                        <flux:table.column>Invoice #</flux:table.column>
                                        <flux:table.column>Date</flux:table.column>
                                        <flux:table.column>Due Date</flux:table.column>
                                        <flux:table.column align="end">Amount</flux:table.column>
                                    </flux:table.columns>
                                    <flux:table.rows>
                                        <flux:table.row>
                                            <flux:table.cell>
                                                <flux:skeleton class="rounded size-5" />
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                <flux:skeleton.line class="w-16" />
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                <flux:skeleton.line class="w-20" />
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                <flux:skeleton.line class="w-20" />
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                <div class="flex justify-end">
                                                    <flux:skeleton.line class="w-16" />
                                                </div>
                                            </flux:table.cell>
                                        </flux:table.row>
                                    </flux:table.rows>
                                </flux:table>
                            </div>
                        </div>

                        {{-- Total Account Balance --}}
                        <div class="bg-zinc-100 dark:bg-zinc-800 p-4 rounded-lg">
                            <div class="flex justify-between items-center">
                                <flux:subheading>Total Account Balance:</flux:subheading>
                                <flux:skeleton.line class="w-20" />
                            </div>
                        </div>

                        {{-- Selected Invoices Total --}}
                        <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-lg">
                            <div class="flex justify-between items-center">
                                <flux:skeleton.line class="w-44" />
                                <flux:skeleton.line class="w-20" />
                            </div>
                            <flux:skeleton.line class="w-48 mt-2" />
                        </div>

                        {{-- Payment Amount field --}}
                        <div class="space-y-6">
                            <div>
                                <flux:skeleton.line class="w-28 mb-2" />
                                <flux:skeleton class="h-10 w-full rounded-lg" />
                                <flux:skeleton.line class="w-64 mt-2" />
                            </div>

                            {{-- Payment Notes field --}}
                            <div>
                                <flux:skeleton.line class="w-40 mb-2" />
                                <flux:skeleton class="h-20 w-full rounded-lg" />
                            </div>
                        </div>

                        {{-- Back and Continue buttons --}}
                        <div class="flex gap-3 pt-4">
                            <flux:skeleton class="h-10 w-20 rounded-lg" />
                            <flux:skeleton class="h-10 flex-1 rounded-lg" />
                        </div>
                    </div>
                </flux:skeleton.group>
                @break

            @case('cards')
                {{-- Payment method selection skeleton - matches 2x2 grid of payment options --}}
                <flux:skeleton.group animate="shimmer">
                    <div class="space-y-8 max-w-3xl mx-auto">
                        {{-- Title and amount --}}
                        <div class="text-center space-y-4">
                            <flux:skeleton.line class="w-64 mx-auto" />
                            <div class="space-y-2">
                                <flux:skeleton.line class="w-32 mx-auto" />
                                <flux:skeleton.line class="w-40 mx-auto" />
                            </div>
                        </div>

                        {{-- 2x2 Grid of payment method cards --}}
                        <div class="grid md:grid-cols-2 gap-6">
                            @foreach(range(1, 4) as $i)
                                <div class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-200 dark:border-zinc-700 p-6">
                                    <flux:skeleton class="size-12 rounded-lg" />
                                    <div class="text-center space-y-2 w-full">
                                        <flux:skeleton.line class="w-32 mx-auto" />
                                        <flux:skeleton.line class="w-24 mx-auto" />
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Back button --}}
                        <div class="text-center pt-4">
                            <flux:skeleton class="h-10 w-20 rounded-lg mx-auto" />
                        </div>
                    </div>
                </flux:skeleton.group>
                @break

            @case('processing')
                {{-- Processing skeleton (used during payment processing) --}}
                <div class="text-center py-12 space-y-6">
                    <div class="flex justify-center">
                        <svg class="animate-spin h-16 w-16 text-zinc-400" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                    <flux:skeleton.group animate="shimmer">
                        <div class="space-y-2">
                            <flux:skeleton.line class="w-48 mx-auto" />
                            <flux:skeleton.line class="w-64 mx-auto" />
                        </div>
                    </flux:skeleton.group>
                    <flux:text class="text-zinc-500">Please do not close this window</flux:text>
                </div>
                @break

            @default
                {{-- Generic skeleton --}}
                <flux:skeleton.group animate="shimmer">
                    <div class="space-y-4 max-w-lg mx-auto">
                        <flux:skeleton.line class="w-3/4" />
                        <flux:skeleton.line />
                        <flux:skeleton.line class="w-5/6" />
                        <flux:skeleton.line class="w-2/3" />
                        <div class="pt-4">
                            <flux:skeleton class="h-10 w-full rounded-lg" />
                        </div>
                    </div>
                </flux:skeleton.group>
        @endswitch
    </flux:card>
</div>
