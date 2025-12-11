{{--
    Step: Account Type Selection
    
    First step in the payment flow where users choose between personal and business accounts.
--}}

<x-payment.step 
    name="account-type"
    title="Select Account Type"
    subtitle="Are you making a payment for a business or personal account?"
    :show-back="false"
    :show-next="false"
>
    {{-- Info Notice --}}
    <div class="max-w-2xl mx-auto mb-8 p-4 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-lg">
        <div class="flex gap-3 text-sm text-zinc-700 dark:text-zinc-300">
            <svg class="w-5 h-5 text-zinc-500 dark:text-zinc-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <p>
                <strong class="font-semibold text-zinc-900 dark:text-zinc-100">Note:</strong> You can pay for all of your linked accounts on either option. If you don't see a linked account, please call us.
            </p>
        </div>
    </div>
    
    {{-- Account Type Buttons --}}
    <div class="max-w-2xl mx-auto space-y-3">
        {{-- Primary: Personal Account --}}
        <button 
            wire:click="selectAccountType('personal')"
            type="button" 
            class="group w-full p-6 flex items-center gap-6 rounded-lg border-2 border-zinc-800 dark:border-zinc-200 bg-zinc-800 dark:bg-zinc-200 hover:bg-black dark:hover:bg-white hover:border-black dark:hover:border-white transition-all duration-200 shadow-md hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] cursor-pointer"
        >
            <div class="flex-shrink-0 p-3 bg-white/10 dark:bg-zinc-800/50 rounded-lg group-hover:bg-white/20 dark:group-hover:bg-zinc-700 transition-all duration-200">
                <svg class="w-8 h-8 text-white dark:text-zinc-800 group-hover:scale-110 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            </div>
            <div class="flex-1 text-left">
                <div class="text-xl font-semibold text-white dark:text-zinc-900 mb-1">Personal Account</div>
                <div class="text-sm text-zinc-300 dark:text-zinc-600">For individual or family payments</div>
            </div>
            <svg class="w-6 h-6 text-zinc-400 dark:text-zinc-600 group-hover:text-white dark:group-hover:text-zinc-900 group-hover:translate-x-1 transition-all duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>
        
        {{-- Secondary: Business Account --}}
        <button 
            wire:click="selectAccountType('business')"
            type="button" 
            class="group w-full p-6 flex items-center gap-6 rounded-lg border-2 border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-zinc-400 dark:hover:border-zinc-600 hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:scale-[1.01] active:scale-[0.99] transition-all duration-200"
        >
            <div class="flex-shrink-0 p-3 bg-zinc-100 dark:bg-zinc-800 rounded-lg group-hover:bg-zinc-200 dark:group-hover:bg-zinc-700 transition-colors duration-200">
                <svg class="w-8 h-8 text-zinc-600 dark:text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
            </div>
            <div class="flex-1 text-left">
                <div class="text-xl font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Business Account</div>
                <div class="text-sm text-zinc-600 dark:text-zinc-400">For company or organization payments</div>
            </div>
            <svg class="w-6 h-6 text-zinc-300 dark:text-zinc-700 group-hover:text-zinc-500 dark:group-hover:text-zinc-500 group-hover:translate-x-1 transition-all duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>
    </div>
</x-payment.step>
