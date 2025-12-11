{{-- 
    Payment Step Wrapper Component
    
    Provides consistent styling, transitions, and structure for all payment flow steps.
    
    @props:
    - name: string - The step identifier
    - title: string - The step title (optional, will use Steps::getMeta if not provided)
    - showBack: bool - Whether to show back button (default: true)
    - showNext: bool - Whether to show next/continue button (default: true)  
    - nextLabel: string - Label for next button (default: 'Continue')
    - nextDisabled: bool - Whether next button is disabled
    - onBack: string - Livewire action for back button (default: 'goToPrevious')
    - onNext: string - Livewire action for next button (default: 'goToNext')
--}}

@props([
    'name',
    'title' => null,
    'subtitle' => null,
    'showBack' => true,
    'showNext' => true,
    'nextLabel' => 'Continue',
    'nextDisabled' => false,
    'onBack' => 'goToPrevious',
    'onNext' => 'goToNext',
    'processing' => false,
    'processingLabel' => 'Processing...',
])

@php
    use App\Livewire\PaymentFlow\Steps;
    $meta = Steps::getMeta($name);
    // Use provided title/subtitle, fall back to metadata if not provided
    // Pass empty string to explicitly hide title/subtitle
    $stepTitle = $title !== null ? $title : ($meta['title'] ?? 'Step');
    $stepSubtitle = $subtitle !== null ? $subtitle : ($meta['description'] ?? '');
@endphp

<div 
    x-data="{ show: true }"
    x-show="show"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 scale-95"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-95"
    class="payment-step"
    data-step="{{ $name }}"
>
    <flux:card class="p-8">
        {{-- Step Header --}}
        @if($stepTitle)
            <div class="mb-6">
                <flux:heading size="xl" class="text-center mb-2">{{ $stepTitle }}</flux:heading>
                @if($stepSubtitle)
                    <flux:subheading class="text-center">{{ $stepSubtitle }}</flux:subheading>
                @endif
            </div>
        @endif

        {{-- Step Content --}}
        <div class="step-content">
            {{ $slot }}
        </div>

        {{-- Step Footer with Navigation --}}
        @if($showBack || $showNext)
            <div class="mt-8 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                <div class="flex justify-between items-center gap-4">
                    {{-- Back Button --}}
                    @if($showBack)
                        <flux:button 
                            variant="ghost" 
                            wire:click="{{ $onBack }}"
                            wire:loading.attr="disabled"
                            class="flex items-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                            Back
                        </flux:button>
                    @else
                        <div></div>
                    @endif

                    {{-- Next Button --}}
                    @if($showNext)
                        <flux:button 
                            variant="primary"
                            wire:click="{{ $onNext }}"
                            wire:loading.attr="disabled"
                            :disabled="$nextDisabled"
                            class="flex items-center gap-2"
                        >
                            <span wire:loading.remove wire:target="{{ $onNext }}">
                                {{ $nextLabel }}
                            </span>
                            <span wire:loading wire:target="{{ $onNext }}" class="flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                {{ $processingLabel }}
                            </span>
                            <svg wire:loading.remove wire:target="{{ $onNext }}" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:card>
</div>
