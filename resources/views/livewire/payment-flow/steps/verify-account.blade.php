{{--
    Step: Verify Account
    
    User enters their personal identification information to verify their account.
    Note: Business clients are grouped together and treated as personal accounts.
--}}

<x-payment.step 
    name="verify-account"
    title="Verify Your Account"
    subtitle="Please enter your personal information"
    :show-next="false"
    :show-back="false"
>
    <form wire:submit.prevent="verifyAccount" class="space-y-6 max-w-md mx-auto">
        <flux:field>
            <flux:label>Last 4 Digits of SSN</flux:label>
            <flux:input 
                name="last4"
                placeholder="∙∙∙-∙∙-____" 
                maxlength="11" 
                inputmode="numeric"
                wire:loading.attr="disabled" 
                wire:target="verifyAccount" 
                x-init="
                    let init = $wire.get('last4') || '';
                    if (init.length > 0) $el.value = '∙∙∙-∙∙-' + init;
                "
                x-on:input="
                    let v = $el.value.replace(/\D/g, '').substring(0, 4);
                    $el.value = v.length > 0 ? '∙∙∙-∙∙-' + v : '';
                    $wire.set('last4', v);
                "
                x-on:keydown.backspace="
                    if ($el.value === '∙∙∙-∙∙-') {
                        $el.value = '';
                        $wire.set('last4', '');
                        $event.preventDefault();
                    }
                "
            />
            <flux:error name="last4" />
        </flux:field>

        <flux:field>
            <flux:label>Last Name</flux:label>
            <flux:input 
                wire:model="lastName" 
                placeholder="Smith" 
                wire:loading.attr="disabled" 
                wire:target="verifyAccount" 
            />
            <flux:error name="lastName" />
            <flux:description>Enter as shown on your account</flux:description>
        </flux:field>

        <div
            x-data
            x-init="
                // Set up Turnstile callbacks before rendering.
                // The package's <script> listens for livewire:initialized which
                // already fired before this lazy component mounted, so we must
                // define the callbacks ourselves.
                window.captchaCallback = function (token) {
                    $wire.set('turnstileToken', token);
                };
                window.captchaExpiredCallback = function () {
                    $wire.set('turnstileToken', '');
                    if (window.turnstile) window.turnstile.reset();
                };

                function renderTurnstile() {
                    let el = $el.querySelector('.cf-turnstile');
                    if (el && !el.querySelector('iframe') && window.turnstile) {
                        window.turnstile.render(el);
                    }
                }
                $nextTick(() => {
                    if (window.turnstile) {
                        renderTurnstile();
                    } else {
                        let check = setInterval(() => {
                            if (window.turnstile) { clearInterval(check); renderTurnstile(); }
                        }, 100);
                    }
                })
            "
        >
            <x-turnstile wire:model="turnstileToken" data-action="verify-account" data-theme="light" />
            <flux:error name="turnstileToken" />
        </div>

        {{-- Custom footer for this step --}}
        <div class="flex gap-3 pt-4">
            <flux:button 
                type="submit"
                variant="primary"
                class="w-full"
                wire:loading.attr="disabled" 
                wire:target="verifyAccount"
            >
                <span wire:loading.remove wire:target="verifyAccount">Continue</span>
                <span wire:loading wire:target="verifyAccount" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Verifying...
                </span>
            </flux:button>
        </div>
    </form>
</x-payment.step>
