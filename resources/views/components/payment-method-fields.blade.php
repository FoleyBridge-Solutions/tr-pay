{{-- Reusable payment method entry fields (credit card OR ACH) --}}
{{-- resources/views/components/payment-method-fields.blade.php --}}
{{--
    Usage:
      <x-payment-method-fields type="card" ... />
      <x-payment-method-fields type="ach" ... />

    The parent view handles card-vs-ACH conditional rendering with @if/@elseif.
    Each invocation renders only one section (card or ACH).

    Save option (default: shown & checked):
      By default, a "Save this payment method" checkbox is rendered after the
      fields. The parent Livewire component must declare a bool $savePaymentMethod
      and a ?string $paymentMethodNickname property. Pass :show-save-option="false"
      to suppress the save UI (e.g. when the flow always saves, like managing
      saved payment methods or creating recurring payments).
--}}

@props([
    'type',                                             {{-- Required: 'card' or 'ach' --}}

    {{-- HTML attributes --}}
    'required' => false,                                {{-- Add required/maxlength HTML attributes --}}

    {{-- Card-specific options --}}
    'showCardName' => false,                            {{-- Show "Name on Card" field --}}
    'showDescriptions' => false,                        {{-- Show flux:description helper text --}}
    'optionalLabels' => false,                          {{-- Append "(Optional)" to CVV/card name labels --}}

    {{-- ACH-specific options --}}
    'showAccountName' => false,                         {{-- Show "Account Holder Name" field --}}
    'showBankName' => false,                            {{-- Show "Bank Name" field --}}
    'showIsBusiness' => false,                          {{-- Show business/personal classification --}}
    'showAchAuth' => false,                             {{-- Show ACH authorization checkbox + terms --}}
    'bankNameOptional' => false,                        {{-- Whether bank name is optional --}}

    {{-- ACH wire model names (vary across components) --}}
    'accountTypeModel' => 'bankAccountType',            {{-- Wire model for account type select --}}
    'isBusinessModel' => 'isBusiness',                  {{-- Wire model for business toggle --}}
    'achAuthModel' => 'achAuthorization',               {{-- Wire model for ACH auth checkbox --}}

    {{-- ACH auth customization --}}
    'accountTypeValue' => 'checking',                   {{-- Current account type value (passed from parent for auth text) --}}
    'achAuthTitle' => 'ACH Debit Authorization',        {{-- ACH auth section title --}}
    'achAuthText' => 'I authorize this ACH debit and agree to the terms above.', {{-- ACH auth checkbox label --}}
    'achAuthDescription' => null,                       {{-- Custom short ACH auth description (null = full default) --}}
    'isBusinessDescription' => null,                    {{-- Description for business classification field --}}
    'optionalAccountName' => false,                     {{-- Append "(Optional)" to account holder name label --}}

    {{-- Save payment method option --}}
    'showSaveOption' => true,                           {{-- Show "Save this payment method" checkbox + nickname --}}
    'saveModel' => 'savePaymentMethod',                 {{-- Wire model for save checkbox --}}
    'nicknameModel' => 'paymentMethodNickname',         {{-- Wire model for nickname input --}}
])

@if($type === 'card')
    {{-- ==================== Credit Card Fields ==================== --}}
    <div wire:key="payment-fields-card" class="space-y-4">
        <flux:field>
            <flux:label>Card Number</flux:label>
            <flux:input wire:model="cardNumber" placeholder="{{ $showCardName ? '1234 5678 9012 3456' : '4111 1111 1111 1111' }}" :maxlength="$required ? '19' : null" :required="$required"
                x-on:input="
                    let v = $el.value.replace(/\D/g, '').substring(0, 16);
                    $el.value = v.replace(/(.{4})/g, '$1 ').trim();
                    $wire.set('cardNumber', $el.value);
                "
            />
            <flux:error name="cardNumber" />
            @if($showDescriptions)
                <flux:description>Enter your 16-digit card number</flux:description>
            @endif
        </flux:field>

        <div class="grid {{ $showCardName ? '' : 'md:' }}grid-cols-2 gap-4">
            <flux:field>
                <flux:label>{{ $optionalLabels ? 'Expiry Date' : 'Expiration Date' }}</flux:label>
                <flux:input wire:model="cardExpiry" placeholder="MM/YY" :maxlength="$required ? '5' : null" :required="$required"
                    x-on:input="
                        let v = $el.value.replace(/\D/g, '').substring(0, 4);
                        if (v.length >= 2) v = v.substring(0, 2) + '/' + v.substring(2);
                        $el.value = v;
                        $wire.set('cardExpiry', $el.value);
                    "
                />
                <flux:error name="cardExpiry" />
                @if($showDescriptions)
                    <flux:description>Format: MM/YY</flux:description>
                @endif
            </flux:field>

            <flux:field>
                <flux:label>{{ $showDescriptions ? 'CVV / Security Code' : ($optionalLabels ? 'CVV (Optional)' : 'CVV') }}</flux:label>
                <flux:input wire:model="cardCvv" type="password" placeholder="123" :maxlength="$required ? '4' : null" :required="$required"
                    x-on:input="
                        $el.value = $el.value.replace(/\D/g, '').substring(0, 4);
                        $wire.set('cardCvv', $el.value);
                    "
                />
                <flux:error name="cardCvv" />
                @if($showDescriptions)
                    <flux:description>3 or 4 digits on back of card</flux:description>
                @endif
            </flux:field>
        </div>

        @if($showCardName)
            <flux:field>
                <flux:label>{{ $optionalLabels ? 'Name on Card (Optional)' : 'Name on Card' }}</flux:label>
                <flux:input wire:model="cardName" placeholder="John Doe" />
            </flux:field>
        @endif

        @if($showSaveOption)
            @include('components.payment-method-fields-save', [
                'saveModel' => $saveModel,
                'nicknameModel' => $nicknameModel,
                'nicknamePlaceholder' => 'e.g., Personal Card, Work Card',
            ])
        @endif
    </div>

@elseif($type === 'ach')
    {{-- ==================== ACH / Bank Account Fields ==================== --}}
    <div wire:key="payment-fields-ach" class="space-y-4">
        @if($showAccountName)
            <flux:field>
                <flux:label>{{ $optionalAccountName ? 'Account Holder Name (Optional)' : 'Account Holder Name' }}</flux:label>
                <flux:input wire:model="accountName" placeholder="John Doe" />
            </flux:field>
        @endif

        @if($showBankName)
            <flux:field>
                <flux:label>{{ $bankNameOptional ? 'Bank Name (Optional)' : 'Bank Name' }}</flux:label>
                <flux:input wire:model="bankName" placeholder="{{ $bankNameOptional ? 'Chase Bank' : 'First National Bank' }}" :required="$required && !$bankNameOptional" />
                <flux:error name="bankName" />
            </flux:field>
        @endif

        <div class="grid {{ ($showAccountName || $showBankName) ? '' : 'md:' }}grid-cols-2 gap-4">
            <flux:field>
                <flux:label>Routing Number</flux:label>
                <flux:input wire:model="routingNumber" placeholder="123456789" :maxlength="$required ? '9' : null" :required="$required" />
                <flux:error name="routingNumber" />
                @if($showDescriptions)
                    <flux:description>9 digits{{ $showBankName ? '' : ' (bottom left of check)' }}</flux:description>
                @endif
            </flux:field>

            <flux:field>
                <flux:label>Account Number</flux:label>
                <flux:input wire:model="accountNumber" placeholder="{{ $showAccountName ? '1234567890' : '123456789' }}" :maxlength="$required ? '17' : null" :required="$required" />
                <flux:error name="accountNumber" />
                @if($showDescriptions)
                    <flux:description>8-17 digits</flux:description>
                @endif
            </flux:field>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <flux:field>
                <flux:label>Account Type</flux:label>
                <flux:select wire:model="{{ $accountTypeModel }}">
                    <flux:select.option value="checking">Checking</flux:select.option>
                    <flux:select.option value="savings">Savings</flux:select.option>
                </flux:select>
                <flux:error name="{{ $accountTypeModel }}" />
            </flux:field>

            @if($showIsBusiness)
                <flux:field>
                    <flux:label>Account Classification</flux:label>
                    <flux:select wire:model="{{ $isBusinessModel }}">
                        <flux:select.option value="0">Personal Account</flux:select.option>
                        <flux:select.option value="1">Business Account</flux:select.option>
                    </flux:select>
                    <flux:error name="{{ $isBusinessModel }}" />
                    @if($isBusinessDescription)
                        <flux:description>{{ $isBusinessDescription }}</flux:description>
                    @endif
                </flux:field>
            @endif
        </div>

        @if($showAchAuth)
            {{-- ACH Authorization --}}
            <div class="bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 mt-4">
                <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-{{ $achAuthDescription === null ? '3' : '2' }}">{{ $achAuthTitle }}</h4>
                @if($achAuthDescription === null)
                    <div class="text-xs text-zinc-600 dark:text-zinc-400 space-y-2">
                        <p>
                            By providing your bank account information and proceeding with this payment, you authorize
                            <strong>{{ config('branding.company_name', 'our company') }}</strong> to electronically debit your
                            {{ $accountTypeValue === 'savings' ? 'savings' : 'checking' }} account at the financial institution
                            indicated for the amount specified.
                        </p>
                        <p>
                            You also authorize {{ config('branding.company_name', 'our company') }}, if necessary, to electronically
                            credit your account to correct erroneous debits or make payment of refunds or other related credits.
                        </p>
                        <p>
                            This authorization will remain in full force and effect until you notify
                            {{ config('branding.company_name', 'our company') }} in writing that you wish to revoke this authorization.
                            {{ config('branding.company_name', 'our company') }} requires at least <strong>five (5) business days</strong>
                            prior notice in order to cancel this authorization.
                        </p>
                    </div>
                @else
                    <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-3">{{ $achAuthDescription }}</p>
                @endif
                <div class="mt-3">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model="{{ $achAuthModel }}"
                            class="mt-0.5 rounded border-zinc-300 dark:border-zinc-600 text-zinc-800 dark:text-zinc-200 focus:ring-zinc-500"
                            required
                        >
                        <span class="text-xs text-zinc-700 dark:text-zinc-300">
                            {{ $achAuthText }}
                        </span>
                    </label>
                    <flux:error name="{{ $achAuthModel }}" />
                </div>
            </div>
        @endif

        @if($showSaveOption)
            @include('components.payment-method-fields-save', [
                'saveModel' => $saveModel,
                'nicknameModel' => $nicknameModel,
                'nicknamePlaceholder' => 'e.g., Checking Account, Business Account',
            ])
        @endif
    </div>
@endif
