{{--
    Step: Saved Payment Methods
    
    Allows customers to select from previously saved payment methods
    or proceed to enter new payment details.
--}}

<x-payment.step 
    name="saved-payment-methods"
    title="Select Payment Method"
    subtitle="Choose a saved payment method or add a new one"
    :show-next="false"
    :show-back="false"
>
    {{-- Payment Summary --}}
    <div class="mb-6 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
        <div class="flex justify-between items-center">
            <div>
                <div class="text-sm text-zinc-700 dark:text-zinc-300 font-medium">Payment Amount:</div>
                <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($paymentAmount, 2) }}</div>
            </div>
            @if($paymentMethod === 'credit_card' && $creditCardFee > 0)
                <div class="text-right">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300">Non-Cash Adjustment:</div>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">+${{ number_format($creditCardFee, 2) }}</div>
                    <div class="text-xs text-zinc-800 dark:text-zinc-200 mt-1">Total: ${{ number_format($paymentAmount + $creditCardFee, 2) }}</div>
                </div>
            @endif
        </div>
    </div>

    {{-- Error Display --}}
    @error('savedMethod')
        <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
            </div>
        </div>
    @enderror

    {{-- Saved Payment Methods List --}}
    @php
        $methodsForType = $this->getSavedMethodsForCurrentType();
    @endphp

    @if($methodsForType->isNotEmpty())
        <div class="space-y-3 mb-6">
            <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                Your Saved {{ $paymentMethod === 'credit_card' ? 'Cards' : 'Bank Accounts' }}
            </h3>

            @foreach($methodsForType as $method)
                <div class="relative group">
                    <button
                        wire:click="selectSavedPaymentMethod({{ $method->id }})"
                        type="button"
                        class="w-full p-4 rounded-lg border-2 transition-all text-left flex items-center gap-4
                            {{ $selectedSavedMethodId === $method->id 
                                ? 'border-zinc-800 dark:border-zinc-200 bg-zinc-50 dark:bg-zinc-800' 
                                : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500' }}
                            {{ $method->isExpired() ? 'opacity-50 cursor-not-allowed' : '' }}"
                        @if($method->isExpired()) disabled @endif
                    >
                        {{-- Card/Bank Icon --}}
                        <div class="flex-shrink-0">
                            @if($method->type === 'card')
                                @switch($method->brand)
                                    @case('Visa')
                                        <div class="w-12 h-8 bg-blue-600 rounded flex items-center justify-center text-white font-bold text-xs">VISA</div>
                                        @break
                                    @case('Mastercard')
                                        <div class="w-12 h-8 bg-orange-500 rounded flex items-center justify-center text-white font-bold text-xs">MC</div>
                                        @break
                                    @case('American Express')
                                        <div class="w-12 h-8 bg-blue-800 rounded flex items-center justify-center text-white font-bold text-xs">AMEX</div>
                                        @break
                                    @case('Discover')
                                        <div class="w-12 h-8 bg-orange-600 rounded flex items-center justify-center text-white font-bold text-xs">DISC</div>
                                        @break
                                    @default
                                        <svg class="w-12 h-8 text-zinc-600 dark:text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                        </svg>
                                @endswitch
                            @else
                                <svg class="w-12 h-8 text-zinc-600 dark:text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                                </svg>
                            @endif
                        </div>

                        {{-- Card/Bank Details --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-zinc-100 truncate">
                                    {{ $method->display_name }}
                                </span>
                                @if($method->is_default)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                        Default
                                    </span>
                                @endif
                                @if($method->isExpired())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">
                                        Expired
                                    </span>
                                @elseif($method->expiresWithinDays(30))
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">
                                        Expiring Soon
                                    </span>
                                @endif
                            </div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                @if($method->type === 'card' && $method->expiration_display)
                                    Expires {{ $method->expiration_display }}
                                @elseif($method->bank_name)
                                    {{ $method->bank_name }}
                                @endif
                            </div>
                        </div>

                        {{-- Selection Indicator --}}
                        @if($selectedSavedMethodId === $method->id)
                            <svg class="w-6 h-6 text-green-600 dark:text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        @endif
                    </button>

                    {{-- Action Menu (Delete, Set Default) --}}
                    <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <div class="flex items-center gap-1">
                            @if(!$method->is_default && !$method->isExpired())
                                <button
                                    wire:click.stop="setDefaultPaymentMethod({{ $method->id }})"
                                    type="button"
                                    class="p-1.5 rounded text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                    title="Set as default"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                                    </svg>
                                </button>
                            @endif
                            <button
                                wire:click.stop="deleteSavedPaymentMethod({{ $method->id }})"
                                type="button"
                                class="p-1.5 rounded text-zinc-500 hover:text-red-600 dark:text-zinc-400 dark:hover:text-red-400 hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                title="Delete"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Add New Payment Method Button --}}
    <div class="mb-6">
        <button
            wire:click="proceedWithNewPaymentMethod"
            type="button"
            class="w-full p-4 rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-600 hover:border-zinc-500 dark:hover:border-zinc-400 transition-colors flex items-center justify-center gap-3 text-zinc-600 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            <span class="font-medium">Add New {{ $paymentMethod === 'credit_card' ? 'Card' : 'Bank Account' }}</span>
        </button>
    </div>

    {{-- Security Notice --}}
    <div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 text-sm text-zinc-700 dark:text-zinc-300 mb-6">
        <div class="flex items-start gap-2">
            <svg class="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
            </svg>
            <div>
                <strong>Secure & Encrypted:</strong> Your payment methods are securely stored using bank-level encryption. We never store your full card number or CVV.
            </div>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="flex gap-3">
        <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-zinc-100">
            Back
        </button>
    </div>

    {{-- Reassignment Modal --}}
    <flux:modal wire:model.self="showReassignmentModal" class="max-w-lg" :dismissible="false" @close="closeReassignmentModal">
        <div class="space-y-6">
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 dark:bg-yellow-900/30">
                    <flux:icon name="exclamation-triangle" class="h-6 w-6 text-yellow-600 dark:text-yellow-400" />
                </div>
                <flux:heading size="lg" class="mt-4">Payment Method In Use</flux:heading>
                <flux:text class="mt-2">
                    This payment method is linked to active payment plans or recurring payments. Please select a different payment method to use for these:
                </flux:text>
            </div>

            {{-- List linked items --}}
            <div class="text-left">
                @if(count($linkedPlansToReassign) > 0)
                    <div class="mb-3">
                        <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Payment Plans:</h4>
                        <ul class="mt-1 text-sm text-zinc-600 dark:text-zinc-400 list-disc list-inside">
                            @foreach($linkedPlansToReassign as $plan)
                                <li>{{ $plan['plan_id'] }} - ${{ number_format($plan['amount_remaining'], 2) }} remaining</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(count($linkedRecurringToReassign) > 0)
                    <div class="mb-3">
                        <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Recurring Payments:</h4>
                        <ul class="mt-1 text-sm text-zinc-600 dark:text-zinc-400 list-disc list-inside">
                            @foreach($linkedRecurringToReassign as $recurring)
                                <li>{{ $recurring['description'] ?? 'Recurring payment' }} - ${{ number_format($recurring['amount'], 2) }}/{{ $recurring['frequency'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- Select new method --}}
            @php
                $otherMethods = $savedPaymentMethods->filter(fn($m) => $m->id !== $methodToDelete && !$m->isExpired());
            @endphp

            @if($otherMethods->isNotEmpty())
                <flux:field>
                    <flux:label>Reassign to:</flux:label>
                    <flux:select wire:model="reassignToMethodId">
                        <flux:select.option value="">Select a payment method...</flux:select.option>
                        @foreach($otherMethods as $method)
                            <flux:select.option value="{{ $method->id }}">{{ $method->display_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="reassignment" />
                </flux:field>
            @else
                <div class="p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                    <p class="text-sm text-yellow-700 dark:text-yellow-300">
                        You don't have any other payment methods saved. Please add a new payment method before deleting this one.
                    </p>
                </div>
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                @if($otherMethods->isNotEmpty())
                    <flux:button wire:click="reassignAndDelete" variant="danger" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="reassignAndDelete">Reassign & Delete</span>
                        <span wire:loading wire:target="reassignAndDelete">Processing...</span>
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</x-payment.step>
