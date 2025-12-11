{{-- 
    Payment Progress Indicator Component
    
    Shows the user's progress through the payment flow.
    
    @props:
    - currentStep: string - The current step identifier
--}}

@props([
    'currentStep',
])

@php
    use App\Livewire\PaymentFlow\Steps;
    use App\Livewire\PaymentFlow\Navigator;
    
    $progressSteps = Steps::getProgressSteps();
    $currentIndex = Navigator::getCurrentProgressIndex($currentStep);
    $isLoadingStep = Steps::isLoadingStep($currentStep);
@endphp

<div class="payment-progress mb-8">
    {{-- Mobile: Simple progress bar --}}
    <div class="sm:hidden">
        <div class="flex items-center justify-between mb-2">
            <flux:text class="text-sm font-medium">
                Step {{ $currentIndex + 1 }} of {{ count($progressSteps) }}
            </flux:text>
            <flux:text class="text-sm text-zinc-500">
                {{ Steps::getMeta($progressSteps[$currentIndex])['title'] }}
            </flux:text>
        </div>
        <div class="h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
            <div 
                class="h-full bg-zinc-800 dark:bg-zinc-200 rounded-full transition-all duration-500"
                style="width: {{ Navigator::getProgressPercentage($currentStep) }}%"
            ></div>
        </div>
    </div>

    {{-- Desktop: Step indicators --}}
    <div class="hidden sm:block">
        <nav aria-label="Progress">
            <ol class="flex items-center justify-center">
                @foreach($progressSteps as $index => $step)
                    @php
                        $stepMeta = Steps::getMeta($step);
                        $isCompleted = $index < $currentIndex;
                        $isCurrent = $index === $currentIndex;
                        $isUpcoming = $index > $currentIndex;
                    @endphp
                    
                    <li class="flex items-center {{ $index < count($progressSteps) - 1 ? 'flex-1' : '' }}">
                        {{-- Step circle and label --}}
                        <div class="flex flex-col items-center">
                            {{-- Circle --}}
                            <div class="relative flex items-center justify-center">
                                @if($isCompleted)
                                    {{-- Completed step --}}
                                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-800 dark:bg-zinc-200">
                                        <svg class="h-5 w-5 text-white dark:text-zinc-900" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                @elseif($isCurrent)
                                    {{-- Current step --}}
                                    <span class="flex h-10 w-10 items-center justify-center rounded-full border-2 border-zinc-800 dark:border-zinc-200 {{ $isLoadingStep ? 'animate-pulse' : '' }}">
                                        @if($isLoadingStep)
                                            <svg class="animate-spin h-5 w-5 text-zinc-800 dark:text-zinc-200" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        @else
                                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $index + 1 }}</span>
                                        @endif
                                    </span>
                                @else
                                    {{-- Upcoming step --}}
                                    <span class="flex h-10 w-10 items-center justify-center rounded-full border-2 border-zinc-300 dark:border-zinc-600">
                                        <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $index + 1 }}</span>
                                    </span>
                                @endif
                            </div>

                            {{-- Label --}}
                            <span class="mt-2 text-xs font-medium {{ $isCurrent ? 'text-zinc-900 dark:text-zinc-100' : ($isCompleted ? 'text-zinc-700 dark:text-zinc-300' : 'text-zinc-500 dark:text-zinc-400') }}">
                                {{ $stepMeta['title'] }}
                            </span>
                        </div>

                        {{-- Connector line --}}
                        @if($index < count($progressSteps) - 1)
                            <div class="flex-1 mx-4 hidden sm:block">
                                <div class="h-0.5 {{ $isCompleted ? 'bg-zinc-800 dark:bg-zinc-200' : 'bg-zinc-300 dark:bg-zinc-600' }} transition-colors duration-300"></div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>
</div>
