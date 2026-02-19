{{--
    Step: Invoice Selection
    
    User selects which invoices to pay and specifies the payment amount.
--}}

<x-payment.step 
    name="invoice-selection"
    title="Payment Information"
    :show-next="false"
    :show-back="false"
>
    {{-- Account Info Header --}}
    <div class="bg-zinc-100 dark:bg-zinc-800 p-4 rounded-lg mb-6">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <flux:subheading>Account</flux:subheading>
                <flux:text>{{ $clientInfo['client_name'] ?? 'Unknown' }}</flux:text>
            </div>
            <div class="text-right">
                <flux:subheading>Client ID</flux:subheading>
                <flux:text>{{ $clientInfo['client_id'] ?? 'Unknown' }}</flux:text>
            </div>
        </div>
    </div>

    @php
        // Group invoices by client
        $invoicesByClient = collect($openInvoices)->groupBy('client_name');
        $totalInvoiceCount = collect($openInvoices)->where(function($invoice) {
            return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
        })->count();
        $hasOtherClients = $invoicesByClient->count() > 1;
    @endphp

    <div class="flex justify-between items-center mb-6">
        <div>
            <flux:heading size="lg">Open Invoices ({{ $totalInvoiceCount }})</flux:heading>
            @if($hasOtherClients)
                <flux:subheading class="text-sm text-zinc-600 mt-1">
                    Invoices from {{ $invoicesByClient->count() }} client{{ $invoicesByClient->count() > 1 ? 's' : '' }} in your group
                </flux:subheading>
            @endif
        </div>
    </div>

    @if(count($openInvoices) > 0)
        @error('selectedInvoices')
            <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                {{ $message }}
            </div>
        @enderror

        @foreach($invoicesByClient as $clientName => $clientInvoices)
            @php
                $isPrimaryClient = ($clientName === $clientInfo['client_name']);
                $clientInvoiceCount = $clientInvoices->where(function($invoice) {
                    return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
                })->count();
                $clientTotal = $clientInvoices->where(function($invoice) {
                    return !isset($invoice['is_placeholder']) || !$invoice['is_placeholder'];
                })->sum('open_amount');
                $hasRealInvoices = $clientInvoiceCount > 0;
                $clientKey = md5($clientName);
            @endphp

            <div class="mb-6 {{ $isPrimaryClient ? 'bg-white border-2 border-zinc-200' : 'bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-700' }} rounded-lg overflow-hidden">
                {{-- Client Header --}}
                <div class="px-4 py-3 sm:px-6 sm:py-4 {{ $isPrimaryClient ? 'bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-200' : 'bg-amber-100 dark:bg-amber-900 border-b border-amber-300' }}">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="flex items-center gap-3">
                            @if($isPrimaryClient)
                                <div class="w-8 h-8 bg-zinc-800 rounded-full flex items-center justify-center shrink-0">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <flux:heading size="md" class="text-zinc-900 dark:text-zinc-100">{{ $clientName }}</flux:heading>
                                    <flux:subheading class="text-sm text-zinc-700 dark:text-zinc-300">Your Account</flux:subheading>
                                </div>
                            @else
                                <div class="w-8 h-8 bg-amber-600 rounded-full flex items-center justify-center shrink-0">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <flux:heading size="md" class="text-amber-900 dark:text-amber-100">{{ $clientName }}</flux:heading>
                                    <flux:subheading class="text-sm text-amber-700 dark:text-amber-300">Related Client</flux:subheading>
                                </div>
                            @endif
                        </div>
                        <div class="sm:text-right flex sm:block items-center justify-between pl-11 sm:pl-0">
                            <div class="text-lg font-bold {{ $isPrimaryClient ? 'text-zinc-800' : 'text-amber-600' }}">
                                ${{ number_format($clientTotal, 2) }}
                            </div>
                            <div class="text-sm text-zinc-600">
                                {{ $clientInvoiceCount }} invoice{{ $clientInvoiceCount !== 1 ? 's' : '' }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Client Invoices --}}
                @if($hasRealInvoices)
                    <div class="p-4 sm:p-6">
                        <flux:checkbox.group wire:model.live="selectedInvoices">
                            {{-- Desktop Table (hidden on mobile) --}}
                            <div class="hidden md:block">
                                <flux:table>
                                    <flux:table.columns>
                                        <flux:table.column>
                                            <flux:checkbox.all label="Pay All" />
                                        </flux:table.column>
                                        <flux:table.column>Invoice #</flux:table.column>
                                        <flux:table.column>Date</flux:table.column>
                                        <flux:table.column>Due Date</flux:table.column>
                                        <flux:table.column align="end">Amount</flux:table.column>
                                    </flux:table.columns>
                                    <flux:table.rows>
                                        @foreach($clientInvoices as $invoice)
                                            @php
                                                $isSelected = in_array($invoice['invoice_number'], $selectedInvoices);
                                                $isOverdue = false;
                                                $daysPastDue = 0;

                                                if ($invoice['due_date'] !== 'N/A' && !empty($invoice['due_date'])) {
                                                    try {
                                                        $dueDate = \Carbon\Carbon::parse($invoice['due_date']);
                                                        $isOverdue = $dueDate->isPast();
                                                        $daysPastDue = $isOverdue ? (int)$dueDate->diffInDays(now()) : 0;
                                                    } catch (\Exception $e) {
                                                        $isOverdue = false;
                                                        $daysPastDue = 0;
                                                    }
                                                }
                                            @endphp

                                            <flux:table.row class="{{ $isSelected ? 'bg-zinc-50 dark:bg-zinc-950' : '' }}" wire:key="invoice-{{ $invoice['invoice_number'] }}">
                                                <flux:table.cell>
                                                    @if(!isset($invoice['is_placeholder']) || !$invoice['is_placeholder'])
                                                        <flux:checkbox 
                                                            value="{{ $invoice['invoice_number'] }}" 
                                                        />
                                                    @else
                                                        <span class="text-zinc-400 text-sm">-</span>
                                                    @endif
                                                </flux:table.cell>
                                                <flux:table.cell>
                                                    @if(isset($invoice['is_placeholder']) && $invoice['is_placeholder'])
                                                        <div class="text-zinc-500 italic">{{ $invoice['description'] }}</div>
                                                    @elseif(isset($invoice['is_project']) && $invoice['is_project'])
                                                        <div class="font-medium">{{ $invoice['description'] }}</div>
                                                        <div class="text-xs text-zinc-500">{{ $invoice['invoice_number'] }}</div>
                                                    @else
                                                        <div class="font-medium">{{ $invoice['invoice_number'] }}</div>
                                                    @endif
                                                </flux:table.cell>
                                                <flux:table.cell class="whitespace-nowrap">
                                                    @if(isset($invoice['is_placeholder']) && $invoice['is_placeholder'])
                                                        <span class="text-zinc-400 dark:text-zinc-500 italic">{{ $invoice['invoice_date'] }}</span>
                                                    @else
                                                        {{ \Carbon\Carbon::parse($invoice['invoice_date'])->format('M d, Y') }}
                                                    @endif
                                                </flux:table.cell>
                                                <flux:table.cell class="whitespace-nowrap">
                                                    @if(isset($invoice['is_placeholder']) && $invoice['is_placeholder'])
                                                        <span class="text-zinc-400 dark:text-zinc-500 italic">{{ $invoice['due_date'] }}</span>
                                                    @else
                                                        <div class="flex items-center gap-2">
                                                            @if($invoice['due_date'] === 'N/A')
                                                                <span class="text-zinc-400 dark:text-zinc-500 italic">No due date</span>
                                                            @else
                                                                {{ $invoice['due_date'] }}
                                                                @if($isOverdue)
                                                                    <flux:badge color="red" size="sm" inset="top bottom">
                                                                        {{ $daysPastDue }} {{ $daysPastDue === 1 ? 'day' : 'days' }} overdue
                                                                    </flux:badge>
                                                                @endif
                                                            @endif
                                                        </div>
                                                    @endif
                                                </flux:table.cell>
                                                <flux:table.cell variant="strong" align="end">
                                                    @if(isset($invoice['is_placeholder']) && $invoice['is_placeholder'])
                                                        <span class="text-zinc-400 dark:text-zinc-500 italic">{{ $invoice['open_amount'] }}</span>
                                                    @else
                                                        ${{ number_format($invoice['open_amount'], 2) }}
                                                    @endif
                                                </flux:table.cell>
                                            </flux:table.row>
                                        @endforeach
                                    </flux:table.rows>
                                </flux:table>
                            </div>

                            {{-- Mobile Card List (hidden on desktop) --}}
                            <div class="md:hidden space-y-3">
                                <div class="pb-2 border-b border-zinc-200 dark:border-zinc-700">
                                    <flux:checkbox.all label="Select All" />
                                </div>

                                @foreach($clientInvoices as $invoice)
                                    @php
                                        $isSelected = in_array($invoice['invoice_number'], $selectedInvoices);
                                        $isOverdue = false;
                                        $daysPastDue = 0;

                                        if ($invoice['due_date'] !== 'N/A' && !empty($invoice['due_date'])) {
                                            try {
                                                $dueDate = \Carbon\Carbon::parse($invoice['due_date']);
                                                $isOverdue = $dueDate->isPast();
                                                $daysPastDue = $isOverdue ? (int)$dueDate->diffInDays(now()) : 0;
                                            } catch (\Exception $e) {
                                                $isOverdue = false;
                                                $daysPastDue = 0;
                                            }
                                        }
                                    @endphp

                                    <div 
                                        class="rounded-lg border p-3 transition-colors {{ $isSelected ? 'bg-zinc-50 dark:bg-zinc-950 border-zinc-300 dark:border-zinc-600' : 'border-zinc-200 dark:border-zinc-700' }}"
                                        wire:key="invoice-mobile-{{ $invoice['invoice_number'] }}"
                                    >
                                        @if(isset($invoice['is_placeholder']) && $invoice['is_placeholder'])
                                            <div class="text-zinc-400 dark:text-zinc-500 italic text-sm py-1">
                                                {{ $invoice['description'] }} &mdash; {{ $invoice['open_amount'] }}
                                            </div>
                                        @else
                                            <div class="flex items-start gap-3">
                                                <div class="pt-0.5">
                                                    <flux:checkbox value="{{ $invoice['invoice_number'] }}" />
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    {{-- Row 1: Invoice # and Amount --}}
                                                    <div class="flex items-center justify-between gap-2">
                                                        <div class="font-medium text-sm truncate">
                                                            @if(isset($invoice['is_project']) && $invoice['is_project'])
                                                                {{ $invoice['description'] }}
                                                            @else
                                                                #{{ $invoice['invoice_number'] }}
                                                            @endif
                                                        </div>
                                                        <div class="font-semibold text-sm whitespace-nowrap">
                                                            ${{ number_format($invoice['open_amount'], 2) }}
                                                        </div>
                                                    </div>

                                                    {{-- Row 2: Due Date with overdue badge --}}
                                                    <div class="flex items-center gap-2 mt-1">
                                                        @if(isset($invoice['is_project']) && $invoice['is_project'])
                                                            <span class="text-xs text-zinc-500">{{ $invoice['invoice_number'] }}</span>
                                                            <span class="text-xs text-zinc-300 dark:text-zinc-600">&middot;</span>
                                                        @endif
                                                        @if($invoice['due_date'] === 'N/A')
                                                            <span class="text-xs text-zinc-400 dark:text-zinc-500 italic">No due date</span>
                                                        @else
                                                            <span class="text-xs text-zinc-500">Due {{ $invoice['due_date'] }}</span>
                                                            @if($isOverdue)
                                                                <flux:badge color="red" size="sm">
                                                                    {{ $daysPastDue }}d overdue
                                                                </flux:badge>
                                                            @endif
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </flux:checkbox.group>
                    </div>
                @else
                    <div class="p-6 text-center">
                        <div class="text-zinc-500 italic">No open invoices for this client</div>
                    </div>
                @endif
            </div>
        @endforeach

        {{-- Totals --}}
        <div class="bg-zinc-100 dark:bg-zinc-800 p-4 rounded-lg mb-6">
            <div class="flex justify-between items-center">
                <flux:subheading>Total Account Balance:</flux:subheading>
                <flux:text class="font-bold">${{ number_format($totalBalance, 2) }}</flux:text>
            </div>
        </div>
        
        <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-lg mb-6">
            <div class="flex justify-between items-center">
                <flux:heading size="lg">Selected Invoices Total:</flux:heading>
                <flux:heading size="lg" class="text-zinc-800">${{ number_format($paymentAmount, 2) }}</flux:heading>
            </div>
            <div class="text-sm text-zinc-600 mt-1">
                {{ count($selectedInvoices) }} of {{ $totalInvoiceCount }} invoices selected
                @if($hasOtherClients)
                    from {{ $invoicesByClient->count() }} client{{ $invoicesByClient->count() > 1 ? 's' : '' }}
                @endif
            </div>
        </div>
    @else
        <flux:subheading>No open invoices found.</flux:subheading>
    @endif

    {{-- Payment Form --}}
    <form class="space-y-6">
        <flux:field>
            <flux:label>Payment Amount</flux:label>
            <flux:input wire:model.live="paymentAmount" type="number" step="0.01" min="0.01" max="{{ collect($openInvoices)->whereIn('invoice_number', $selectedInvoices)->sum('open_amount') }}" prefix="$" />
            <flux:error name="paymentAmount" />
            <flux:description>
                Enter the amount you want to pay (max: ${{ number_format(collect($openInvoices)->whereIn('invoice_number', $selectedInvoices)->sum('open_amount'), 2) }})
            </flux:description>
        </flux:field>

        <flux:field>
            <flux:label>Payment Notes (Optional)</flux:label>
            <flux:textarea wire:model="paymentNotes" rows="3" placeholder="Add any notes about this payment..." />
        </flux:field>

        <div class="flex gap-3 pt-4">
            <flux:button 
                variant="ghost"
                wire:click="goToPrevious"
                wire:loading.attr="disabled"
            >
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Back
            </flux:button>
            <flux:button 
                variant="primary"
                class="flex-1"
                wire:click="savePaymentInfo"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="savePaymentInfo">Continue to Payment (${{ number_format($paymentAmount, 2) }})</span>
                <span wire:loading wire:target="savePaymentInfo" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processing...
                </span>
            </flux:button>
        </div>
    </form>
</x-payment.step>
