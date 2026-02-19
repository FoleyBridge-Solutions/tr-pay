{{-- Save Payment Method option (included by payment-method-fields component) --}}
{{-- resources/views/components/payment-method-fields-save.blade.php --}}

<div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 mt-4">
    <label class="flex items-start gap-3 cursor-pointer">
        <input 
            type="checkbox" 
            wire:model.live="{{ $saveModel }}" 
            class="mt-0.5 rounded border-zinc-300 dark:border-zinc-600 text-zinc-800 dark:text-zinc-200 focus:ring-zinc-500"
        >
        <div>
            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                Save this payment method for future purchases
            </span>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                Securely store your payment information for faster checkout next time.
            </p>
        </div>
    </label>
    
    {{-- Nickname field (shown when save is checked) --}}
    @if($this->{$saveModel})
        <div class="mt-3 pl-7">
            <flux:field>
                <flux:label>Nickname (optional)</flux:label>
                <flux:input 
                    wire:model="{{ $nicknameModel }}" 
                    placeholder="{{ $nicknamePlaceholder }}"
                    maxlength="50"
                />
                <flux:description>Give this payment method a name for easy identification</flux:description>
            </flux:field>
        </div>
    @endif
</div>
