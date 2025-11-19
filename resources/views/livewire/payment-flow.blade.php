<div class="space-y-6">
    
    {{-- Step 1: Account Type --}}
    @if($currentStep === 1)
        <flux:card class="p-8">
            <flux:heading size="xl" class="text-center mb-2">Select Account Type</flux:heading>
            <flux:subheading class="text-center mb-8">Are you making a payment for a business or personal account?</flux:subheading>
            
            <div class="grid md:grid-cols-2 gap-6">
                <button wire:click="selectAccountType('business')" type="button" class="h-48 flex flex-col items-center justify-center gap-4 rounded-lg border-2 border-zinc-300 hover:border-indigo-500 bg-white hover:bg-zinc-50 transition-colors">
                    <svg class="w-16 h-16 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                    <div>
                        <div class="text-xl font-semibold text-zinc-900">Business</div>
                        <div class="text-sm text-zinc-600">Company or Organization</div>
                    </div>
                </button>
                
                <button wire:click="selectAccountType('personal')" type="button" class="h-48 flex flex-col items-center justify-center gap-4 rounded-lg border-2 border-zinc-300 hover:border-indigo-500 bg-white hover:bg-zinc-50 transition-colors">
                    <svg class="w-16 h-16 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    <div>
                        <div class="text-xl font-semibold text-zinc-900">Personal</div>
                        <div class="text-sm text-zinc-600">Individual or Family</div>
                    </div>
                </button>
            </div>
        </flux:card>
    @endif

    {{-- Step 2: Account Verification --}}
    @if($currentStep === 2)
        <flux:card class="p-8">
            <flux:heading size="xl" class="text-center mb-2">Verify Your Account</flux:heading>
            <flux:subheading class="text-center mb-8">
                @if($accountType === 'business')
                    Please enter your business information
                @else
                    Please enter your personal information
                @endif
            </flux:subheading>

            <form wire:submit.prevent="verifyAccount" class="space-y-6">
                <flux:field>
                    <flux:label>
                        @if($accountType === 'business')
                            Last 4 Digits of EIN
                        @else
                            Last 4 Digits of SSN
                        @endif
                    </flux:label>
                    <flux:input wire:model="last4" placeholder="1234" maxlength="4" />
                    <flux:error name="last4" />
                    <flux:description>
                        @if($accountType === 'business')
                            Example: If your EIN is 12-3456789, enter 6789
                        @else
                            Example: If your SSN is 123-45-6789, enter 6789
                        @endif
                    </flux:description>
                </flux:field>

                @if($accountType === 'business')
                    <flux:field>
                        <flux:label>Legal Business Name</flux:label>
                        <flux:input wire:model="businessName" placeholder="Acme Corporation, LLC" />
                        <flux:error name="businessName" />
                        <flux:description>Enter exactly as shown on tax documents</flux:description>
                    </flux:field>
                @else
                    <flux:field>
                        <flux:label>Last Name</flux:label>
                        <flux:input wire:model="lastName" placeholder="Smith" />
                        <flux:error name="lastName" />
                        <flux:description>Enter as shown on your account</flux:description>
                    </flux:field>
                @endif

                <div class="flex gap-3">
                    <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 hover:text-zinc-900">Back</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg">Continue</button>
                </div>
            </form>
        </flux:card>
    @endif

    {{-- Step 3: Project Acceptance --}}
    @if($currentStep === 3 && $hasProjectsToAccept && isset($pendingProjects[$currentProjectIndex]))
        @php
            $project = $pendingProjects[$currentProjectIndex];
            $projectNumber = $currentProjectIndex + 1;
            $totalProjects = count($pendingProjects);
        @endphp
        
        <flux:card class="p-8">
            <div class="mb-6">
                <div class="flex items-center justify-between mb-4">
                    <flux:heading size="xl">Project Acceptance Required</flux:heading>
                    <flux:badge color="amber" size="lg">
                        Project {{ $projectNumber }} of {{ $totalProjects }}
                    </flux:badge>
                </div>
                
                <flux:subheading class="text-amber-600">
                    Please review and accept the terms for this project before proceeding to payment.
                </flux:subheading>
            </div>

            {{-- Project Details Card --}}
            <div class="bg-gradient-to-br from-indigo-50 to-blue-50 dark:from-indigo-950 dark:to-blue-950 border-2 border-indigo-300 rounded-lg p-6 mb-6">
                <flux:heading size="lg" class="mb-4 text-indigo-900 dark:text-indigo-100">
                    {{ $project['project_name'] }}
                </flux:heading>
                
                <div class="grid md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <flux:subheading class="text-sm text-indigo-700 dark:text-indigo-300">Project ID</flux:subheading>
                        <flux:text class="font-mono">{{ $project['engagement_id'] }}</flux:text>
                    </div>
                    <div>
                        <flux:subheading class="text-sm text-indigo-700 dark:text-indigo-300">Engagement Type</flux:subheading>
                        <flux:text>{{ $project['engagement_type'] }}</flux:text>
                    </div>
                    @if($project['start_date'])
                    <div>
                        <flux:subheading class="text-sm text-indigo-700 dark:text-indigo-300">Start Date</flux:subheading>
                        <flux:text>{{ \Carbon\Carbon::parse($project['start_date'])->format('M d, Y') }}</flux:text>
                    </div>
                    @endif
                    @if($project['end_date'])
                    <div>
                        <flux:subheading class="text-sm text-indigo-700 dark:text-indigo-300">End Date</flux:subheading>
                        <flux:text>{{ \Carbon\Carbon::parse($project['end_date'])->format('M d, Y') }}</flux:text>
                    </div>
                    @endif
                </div>
                
                <div class="bg-white dark:bg-zinc-900 rounded-lg p-4 border border-indigo-200 dark:border-indigo-700">
                    <flux:subheading class="text-sm mb-2">Project Budget</flux:subheading>
                    <flux:heading size="2xl" class="text-indigo-600 dark:text-indigo-400">
                        ${{ number_format($project['budget_amount'], 2) }}
                    </flux:heading>
                </div>
                
                @if($project['notes'])
                <div class="mt-4">
                    <flux:subheading class="text-sm mb-2">Project Notes</flux:subheading>
                    <div class="bg-white dark:bg-zinc-900 rounded-lg p-4 border border-indigo-200 dark:border-indigo-700 text-sm">
                        {{ $project['notes'] }}
                    </div>
                </div>
                @endif
            </div>

            {{-- Terms & Conditions --}}
            <div class="border-2 border-amber-300 rounded-lg p-6 bg-amber-50 dark:bg-amber-950 mb-6">
                <flux:heading size="md" class="mb-4 flex items-center gap-2">
                    <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Project Terms & Conditions
                </flux:heading>
                
                <div class="prose prose-sm max-w-none text-zinc-700 dark:text-zinc-300">
                    <p class="mb-3"><strong>By accepting this project, you agree to:</strong></p>
                    <ul class="list-disc pl-5 space-y-2">
                        <li>Pay the project budget amount of <strong>${{ number_format($project['budget_amount'], 2) }}</strong></li>
                        <li>Project work will commence upon budget acceptance and payment</li>
                        <li>Project scope and deliverables as outlined in the engagement agreement</li>
                        <li>Payment terms and conditions as specified in your service agreement</li>
                        <li>Any additional terms specific to {{ $project['engagement_type'] }} engagements</li>
                    </ul>
                </div>
            </div>

            {{-- Acceptance Form --}}
            <div class="mt-8 border-t pt-6 border-zinc-200 dark:border-zinc-700">
                <form wire:submit.prevent="acceptProject" class="space-y-6">
                    <div class="bg-white dark:bg-zinc-900 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 shadow-sm">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input 
                                type="checkbox" 
                                wire:model.live="acceptTerms" 
                                class="mt-1 w-5 h-5 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500 dark:bg-zinc-800 dark:border-zinc-600"
                            >
                            <span class="text-sm text-zinc-700 dark:text-zinc-300 font-medium">
                                I accept the terms and conditions for this project and agree to pay the stated budget amount.
                            </span>
                        </label>
                        @error('acceptTerms') 
                            <p class="mt-2 text-sm text-red-600 ml-8">{{ $message }}</p> 
                        @enderror
                    </div>

                    <div class="flex gap-3 pt-2" style="display: flex !important; gap: 12px !important; padding-top: 8px !important; margin-top: 20px !important;">
                        <button 
                            type="button"
                            wire:click="declineProject" 
                            style="padding: 12px 24px !important; background-color: #dc2626 !important; color: white !important; font-weight: 500 !important; border-radius: 8px !important; border: none !important; cursor: pointer !important; display: inline-block !important; visibility: visible !important;"
                        >
                            Decline Project
                        </button>
                        <button 
                            type="submit" 
                            style="flex: 1 !important; padding: 12px 24px !important; background-color: {{ !$acceptTerms ? '#9ca3af' : '#16a34a' }} !important; color: white !important; font-weight: bold !important; border-radius: 8px !important; border: none !important; cursor: {{ !$acceptTerms ? 'not-allowed' : 'pointer' }} !important; display: flex !important; align-items: center !important; justify-content: center !important; gap: 8px !important; visibility: visible !important;"
                            {{ !$acceptTerms ? 'disabled' : '' }}
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width: 20px !important; height: 20px !important;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Accept Project &amp; Continue</span>
                        </button>
                    </div>
                </form>
            </div>

            @if($totalProjects > 1)
            <div class="mt-6 text-center text-sm text-zinc-600">
                You will be asked to review {{ $totalProjects - $projectNumber }} more {{ $totalProjects - $projectNumber === 1 ? 'project' : 'projects' }} after this.
            </div>
            @endif
        </flux:card>
    @endif

    {{-- Step 4: Payment Information --}}
    @if($currentStep === 4)
        <flux:card class="p-8">
            <flux:heading size="xl" class="mb-6">Payment Information</flux:heading>
            
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
                    @endphp

                    <div class="mb-6 {{ $isPrimaryClient ? 'bg-white border-2 border-indigo-200' : 'bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-700' }} rounded-lg overflow-hidden">
                        {{-- Client Header --}}
                        <div class="px-6 py-4 {{ $isPrimaryClient ? 'bg-indigo-50 dark:bg-indigo-950 border-b border-indigo-200' : 'bg-amber-100 dark:bg-amber-900 border-b border-amber-300' }}">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    @if($isPrimaryClient)
                                        <div class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <flux:heading size="md" class="text-indigo-900 dark:text-indigo-100">{{ $clientName }}</flux:heading>
                                            <flux:subheading class="text-sm text-indigo-700 dark:text-indigo-300">Your Account</flux:subheading>
                                        </div>
                                    @else
                                        <div class="w-8 h-8 bg-amber-600 rounded-full flex items-center justify-center">
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
                                <div class="text-right">
                                    <div class="text-lg font-bold {{ $isPrimaryClient ? 'text-indigo-600' : 'text-amber-600' }}">
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
                            <div class="p-6">
                                <flux:table>
                                    <flux:table.columns>
                                        <flux:table.column>
                                            <flux:switch wire:model.live="selectAll" label="Pay All" />
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

                                            <flux:table.row class="{{ $isSelected ? 'bg-indigo-50 dark:bg-indigo-950' : '' }}" wire:key="invoice-{{ $invoice['invoice_number'] }}">
                                                <flux:table.cell>
                                                    @if(!isset($invoice['is_placeholder']) || !$invoice['is_placeholder'])
                                                        <flux:switch 
                                                            wire:model.live="selectedInvoices" 
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
                        @else
                            <div class="p-6 text-center">
                                <div class="text-zinc-500 italic">No open invoices for this client</div>
                            </div>
                        @endif
                    </div>
                @endforeach

                <div class="bg-zinc-100 dark:bg-zinc-800 p-4 rounded-lg mb-6">
                    <div class="flex justify-between items-center">
                        <flux:subheading>Total Account Balance:</flux:subheading>
                        <flux:text class="font-bold">${{ number_format($totalBalance, 2) }}</flux:text>
                    </div>
                </div>
                
                <div class="bg-indigo-50 dark:bg-indigo-900 p-4 rounded-lg mb-6">
                    <div class="flex justify-between items-center">
                        <flux:heading size="lg">Selected Invoices Total:</flux:heading>
                        <flux:heading size="lg" class="text-indigo-600">${{ number_format($paymentAmount, 2) }}</flux:heading>
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

                 <div class="flex gap-3">
                    <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 hover:text-zinc-900" wire:loading.attr="disabled">Back</button>
                    <button wire:click="savePaymentInfo" type="button" class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg flex items-center justify-center gap-2" wire:loading.attr="disabled">
                        <span wire:loading.remove>Continue to Payment (${{ number_format($paymentAmount, 2) }})</span>
                        <span wire:loading>
                            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Processing...
                        </span>
                    </button>
                </div>

                <div wire:loading class="mt-4">
                    <div class="bg-indigo-100 dark:bg-indigo-900 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="animate-pulse flex space-x-1">
                                <div class="h-2 w-2 bg-indigo-600 rounded-full"></div>
                                <div class="h-2 w-2 bg-indigo-600 rounded-full" style="animation-delay: 0.2s"></div>
                                <div class="h-2 w-2 bg-indigo-600 rounded-full" style="animation-delay: 0.4s"></div>
                            </div>
                            <div class="text-sm text-indigo-800 dark:text-indigo-200">
                                Preparing payment options...
                            </div>
                        </div>
                        <div class="mt-2 bg-indigo-200 dark:bg-indigo-700 rounded-full h-1">
                            <div class="bg-indigo-600 h-1 rounded-full animate-pulse" style="width: 60%"></div>
                        </div>
                    </div>
                </div>
            </form>


        </flux:card>
    @endif

    {{-- Step 5: Payment Method --}}
    @if($currentStep === 5)
        <flux:card class="p-8">
            @if(!$isPaymentPlan)
                {{-- Payment Method Selection --}}
                <flux:heading size="xl" class="text-center mb-2">Select Payment Method</flux:heading>
                <div class="text-center mb-8">
                    <flux:subheading>Payment Amount:</flux:subheading>
                    <flux:heading size="2xl" class="text-indigo-600">${{ number_format($paymentAmount, 2) }}</flux:heading>
                </div>

                @error('payment_method')
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
                        {{ $message }}
                    </div>
                @enderror

                <div class="grid md:grid-cols-2 gap-6 mb-6">
                    <button wire:click="selectPaymentMethod('credit_card')" type="button" class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-300 hover:border-indigo-500 bg-white hover:bg-zinc-50 transition-colors relative">
                        <div wire:loading wire:target="selectPaymentMethod" class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center">
                            <div class="text-indigo-600">Processing...</div>
                        </div>
                        <svg class="w-12 h-12 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                        </svg>
                        <div>
                            <div class="text-lg font-semibold text-zinc-900">Credit Card</div>
                            <div class="text-sm text-zinc-600">3% fee applies</div>
                        </div>
                    </button>

                    <button wire:click="selectPaymentMethod('ach')" type="button" class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-300 hover:border-indigo-500 bg-white hover:bg-zinc-50 transition-colors">
                        <svg class="w-12 h-12 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                        </svg>
                        <div>
                            <div class="text-lg font-semibold text-zinc-900">ACH Transfer</div>
                            <div class="text-sm text-zinc-600">No fee</div>
                        </div>
                    </button>

                    <button wire:click="selectPaymentMethod('check')" type="button" class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-300 hover:border-indigo-500 bg-white hover:bg-zinc-50 transition-colors">
                        <svg class="w-12 h-12 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        <div>
                            <div class="text-lg font-semibold text-zinc-900">Check</div>
                            <div class="text-sm text-zinc-600">Mail payment</div>
                        </div>
                    </button>

                    <button wire:click="selectPaymentMethod('payment_plan')" type="button" class="h-40 flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-zinc-300 hover:border-indigo-500 bg-white hover:bg-zinc-50 transition-colors">
                        <svg class="w-12 h-12 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <div>
                            <div class="text-lg font-semibold text-zinc-900">Payment Plan</div>
                            <div class="text-sm text-zinc-600">Pay over time</div>
                        </div>
                    </button>
                </div>

                <div class="text-center">
                    <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 hover:text-zinc-900">Back</button>
                </div>
            @else
                {{-- Payment Plan Configuration --}}
                <flux:heading size="xl" class="mb-2">Configure Payment Plan</flux:heading>
                <flux:subheading class="mb-6">Set up your installment payment schedule</flux:subheading>

                <div class="bg-indigo-50 dark:bg-indigo-900 p-4 rounded-lg mb-6">
                    <div class="mb-3 pb-3 border-b border-indigo-200">
                        <div class="flex justify-between items-center">
                            <div class="text-sm text-indigo-700">Invoice Total:</div>
                            <div class="text-xl font-bold text-indigo-900">${{ number_format($paymentAmount, 2) }}</div>
                        </div>
                        @if($creditCardFee > 0)
                            <div class="flex justify-between items-center mt-2">
                                <div class="text-sm text-indigo-700">Credit Card Fee (3%):</div>
                                <div class="text-lg font-semibold text-indigo-900">+${{ number_format($creditCardFee, 2) }}</div>
                            </div>
                        @endif
                        @if($paymentPlanFee > 0)
                            <div class="flex justify-between items-center mt-2">
                                <div class="text-sm text-indigo-700">
                                    Payment Plan Fee (Variable based on terms):
                                </div>
                                <div class="text-lg font-semibold text-indigo-900">+${{ number_format($paymentPlanFee, 2) }}</div>
                            </div>
                        @endif
                    </div>
                    <div class="flex justify-between items-center">
                        <flux:subheading>Total Amount:</flux:subheading>
                        <flux:heading size="2xl" class="text-indigo-600">${{ number_format($paymentAmount + $creditCardFee + $paymentPlanFee, 2) }}</flux:heading>
                    </div>
                </div>

                <form wire:submit.prevent="confirmPaymentPlan" class="space-y-6">
                    <div class="grid md:grid-cols-2 gap-6">
                        <flux:field>
                            <flux:label>Down Payment (Optional)</flux:label>
                            <flux:input wire:model.live="downPayment" type="number" step="0.01" min="0" max="{{ $paymentAmount }}" prefix="$" />
                            <flux:error name="downPayment" />
                            <flux:description>Amount to pay today (suggested 20%: ${{ number_format($paymentAmount * 0.20, 2) }})</flux:description>
                        </flux:field>

                        <flux:field>
                            <flux:label>Number of Installments</flux:label>
                            <flux:input wire:model.live="planDuration" type="number" min="2" max="12" />
                            <flux:error name="planDuration" />
                            <flux:description>Split remaining balance into installments (2-12)</flux:description>
                        </flux:field>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <flux:field>
                            <flux:label>Payment Frequency</flux:label>
                            <flux:select wire:model.live="planFrequency">
                                <option value="weekly">Weekly (every 7 days)</option>
                                <option value="biweekly">Bi-weekly (every 14 days)</option>
                                <option value="monthly">Monthly (every 30 days)</option>
                                <option value="quarterly">Quarterly (every 90 days)</option>
                                <option value="semiannually">Semi-annually (every 180 days)</option>
                                <option value="annually">Annually (every 365 days)</option>
                            </flux:select>
                        </flux:field>

                        <flux:field>
                            <flux:label>Plan Start Date (Optional)</flux:label>
                            <flux:input wire:model.live="planStartDate" type="date" min="{{ now()->format('Y-m-d') }}" />
                            <flux:description>Leave blank to start payments today</flux:description>
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:checkbox wire:model.live="customAmounts" label="Set custom amounts for each installment" />
                        <flux:description>Check this to specify different amounts for each payment</flux:description>
                    </flux:field>

                    @if($customAmounts)
                        <div class="border border-zinc-300 dark:border-zinc-600 rounded-lg p-4">
                            <flux:heading size="md" class="mb-4">Custom Installment Amounts</flux:heading>
                            <div class="grid md:grid-cols-2 gap-4">
                                @for($i = 0; $i < $planDuration; $i++)
                                    <flux:field>
                                        <flux:label>Payment {{ $i + 1 }}</flux:label>
                                        <flux:input
                                            wire:model.blur="installmentAmounts.{{ $i }}"
                                            wire:change="updateInstallmentAmount({{ $i }}, $event.target.value)"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            prefix="$"
                                        />
                                    </flux:field>
                                @endfor
                            </div>
                            <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900 rounded-lg">
                                <flux:text class="text-sm">
                                    Total installments: ${{ number_format(array_sum($installmentAmounts), 2) }} |
                                    Remaining balance: ${{ number_format($paymentAmount - $downPayment, 2) }}
                                </flux:text>
                            </div>
                        </div>
                    @endif

                    {{-- Payment Schedule Preview --}}
                    @if(count($paymentSchedule) > 0)
                        <div>
                            <flux:heading size="lg" class="mb-4">Payment Schedule Preview</flux:heading>
                            
                            <flux:table container:class="max-h-80">
                                <flux:table.columns sticky class="bg-white dark:bg-zinc-900">
                                    <flux:table.column>Payment</flux:table.column>
                                    <flux:table.column>Due Date</flux:table.column>
                                    <flux:table.column align="end">Amount</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach($paymentSchedule as $payment)
                                        <flux:table.row>
                                            <flux:table.cell>
                                                <div class="font-medium">{{ $payment['label'] }}</div>
                                            </flux:table.cell>
                                            <flux:table.cell class="whitespace-nowrap">
                                                {{ $payment['due_date'] }}
                                                @if($payment['payment_number'] === 0)
                                                    <flux:badge color="green" size="sm" inset="top bottom" class="ml-2">Due Today</flux:badge>
                                                @endif
                                            </flux:table.cell>
                                            <flux:table.cell variant="strong" align="end">
                                                ${{ number_format($payment['amount'], 2) }}
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    @endif

                    <div class="flex gap-3">
                        <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 hover:text-zinc-900">Back</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg">
                            Confirm Payment Plan
                        </button>
                    </div>
                </form>
            @endif
        </flux:card>
    @endif

    {{-- Step 6: Payment Details (One-Time Payment) --}}
    @if($currentStep === 6 && !$isPaymentPlan)
        <flux:card class="p-8">
            <flux:heading size="xl" class="text-center mb-2">Enter Payment Details</flux:heading>
            <flux:subheading class="text-center mb-8">Securely provide your payment information</flux:subheading>

            <div class="mb-6 bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                <div class="flex justify-between items-center">
                    <div>
                        <div class="text-sm text-indigo-700 font-medium">Payment Amount:</div>
                        <div class="text-2xl font-bold text-indigo-900">${{ number_format($paymentAmount, 2) }}</div>
                    </div>
                    @if($paymentMethod === 'credit_card' && $creditCardFee > 0)
                        <div class="text-right">
                            <div class="text-sm text-indigo-700">Credit Card Fee (3%):</div>
                            <div class="text-lg font-semibold text-indigo-900">+${{ number_format($creditCardFee, 2) }}</div>
                            <div class="text-xs text-indigo-600 mt-1">Total: ${{ number_format($paymentAmount + $creditCardFee, 2) }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <form wire:submit.prevent="confirmPayment" class="space-y-6">
                @if($paymentMethod === 'credit_card')
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Card Number</flux:label>
                            <flux:input wire:model="cardNumber" placeholder="4111 1111 1111 1111" maxlength="19" required />
                            <flux:error name="cardNumber" />
                            <flux:description>Enter your 16-digit card number</flux:description>
                        </flux:field>

                        <div class="grid md:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Expiration Date</flux:label>
                                <flux:input wire:model="cardExpiry" placeholder="MM/YY" maxlength="5" required />
                                <flux:error name="cardExpiry" />
                                <flux:description>Format: MM/YY</flux:description>
                            </flux:field>

                            <flux:field>
                                <flux:label>CVV / Security Code</flux:label>
                                <flux:input wire:model="cardCvv" type="password" placeholder="123" maxlength="4" required />
                                <flux:error name="cardCvv" />
                                <flux:description>3 or 4 digits on back of card</flux:description>
                            </flux:field>
                        </div>
                    </div>
                @elseif($paymentMethod === 'ach')
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Bank Name</flux:label>
                            <flux:input wire:model="bankName" placeholder="First National Bank" required />
                            <flux:error name="bankName" />
                        </flux:field>

                        <div class="grid md:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Routing Number</flux:label>
                                <flux:input wire:model="routingNumber" placeholder="123456789" maxlength="9" required />
                                <flux:error name="routingNumber" />
                                <flux:description>9 digits (bottom left of check)</flux:description>
                            </flux:field>

                            <flux:field>
                                <flux:label>Account Number</flux:label>
                                <flux:input wire:model="accountNumber" placeholder="123456789" maxlength="17" required />
                                <flux:error name="accountNumber" />
                                <flux:description>8-17 digits</flux:description>
                            </flux:field>
                        </div>
                    </div>
                @endif

                <div class="bg-zinc-50 border border-zinc-200 rounded-lg p-4 text-sm text-zinc-700">
                    <div class="flex items-start gap-2">
                        <svg class="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                        <div>
                            <strong>Secure Payment:</strong> Your payment information is encrypted and securely transmitted through MiPaymentChoice gateway. We never store your full card number or CVV.
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 hover:text-zinc-900">
                        ← Back
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg flex items-center justify-center gap-2" wire:loading.attr="disabled">
                        <span wire:loading.remove>
                            Process Payment (${{ number_format($paymentMethod === 'credit_card' ? $paymentAmount + $creditCardFee : $paymentAmount, 2) }})
                        </span>
                        <span wire:loading>
                            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Processing Payment...
                        </span>
                    </button>
                </div>
            </form>
        </flux:card>
    @endif

    {{-- Step 6: Payment Plan Authorization --}}
    @if($currentStep === 6 && $isPaymentPlan)
        <flux:card class="p-8">
            <flux:heading size="xl" class="text-center mb-2">Authorize Payment Plan</flux:heading>
            <flux:subheading class="text-center mb-8">Review terms and provide payment method for your installment plan</flux:subheading>

            {{-- Payment Plan Summary --}}
            <div class="bg-indigo-50 dark:bg-indigo-900 p-4 rounded-lg mb-6">
                <flux:heading size="lg" class="mb-3">Payment Plan Summary</flux:heading>
                <div class="grid md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <flux:subheading>Invoice Amount:</flux:subheading>
                        <flux:text class="font-bold">${{ number_format($paymentAmount, 2) }}</flux:text>
                    </div>
                    @if($creditCardFee > 0)
                    <div>
                        <flux:subheading>Credit Card Fee:</flux:subheading>
                        <flux:text class="font-bold">${{ number_format($creditCardFee, 2) }}</flux:text>
                    </div>
                    @endif
                    @if($paymentPlanFee > 0)
                    <div>
                        <flux:subheading>Plan Fee:</flux:subheading>
                        <flux:text class="font-bold">${{ number_format($paymentPlanFee, 2) }}</flux:text>
                    </div>
                    @endif
                    <div>
                        <flux:subheading>Total Obligation:</flux:subheading>
                        <flux:text class="font-bold text-indigo-600">${{ number_format($paymentAmount + $creditCardFee + $paymentPlanFee, 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:subheading>Down Payment:</flux:subheading>
                        <flux:text class="font-bold">${{ number_format($downPayment, 2) }}</flux:text>
                    </div>
                    <div>
                        <flux:subheading>Installments:</flux:subheading>
                        <flux:text>{{ $planDuration }} payments</flux:text>
                    </div>
                    <div>
                        <flux:subheading>Frequency:</flux:subheading>
                        <flux:text class="capitalize">{{ $planFrequency }}</flux:text>
                    </div>
                </div>
            </div>

            <form wire:submit.prevent="authorizePaymentPlan" class="space-y-6">
                {{-- Terms and Conditions --}}
                <div class="border border-zinc-300 dark:border-zinc-600 rounded-lg p-6">
                    <flux:heading size="lg" class="mb-4">Terms and Conditions</flux:heading>

                    <div class="prose prose-sm max-w-none text-zinc-700 dark:text-zinc-300 mb-6">
                        <p class="mb-3"><strong>Payment Plan Agreement:</strong></p>
                        <ul class="list-disc pl-5 space-y-1 mb-4">
                            <li>You agree to pay the total amount of ${{ number_format($paymentAmount, 2) }} in {{ $planDuration }} {{ $planFrequency }} installments</li>
                            <li>The down payment of ${{ number_format($downPayment, 2) }} will be charged immediately</li>
                            <li>Remaining balance of ${{ number_format($paymentAmount - $downPayment, 2) }} will be charged in {{ $planDuration - 1 }} equal installments</li>
                            <li>Payments will be automatically charged to your selected payment method on the due dates</li>
                            <li>Late payments may incur additional fees and interest charges</li>
                            <li>You may cancel this payment plan at any time, but all outstanding amounts become due immediately</li>
                        </ul>

                        <p class="mb-3"><strong>Authorization:</strong></p>
                        <p>By agreeing to these terms, you authorize [Company Name] to charge your payment method for all scheduled payments. You understand that this authorization will remain in effect until the payment plan is completed or cancelled.</p>
                    </div>

                    <flux:field>
                        <flux:checkbox wire:model="agreeToTerms" label="I agree to the terms and conditions above and authorize the automatic charges to my payment method." />
                        <flux:error name="agreeToTerms" />
                    </flux:field>
                </div>

                {{-- Payment Method Collection --}}
                <div class="border border-zinc-300 dark:border-zinc-600 rounded-lg p-6">
                    <flux:heading size="lg" class="mb-4">Payment Method</flux:heading>
                    <flux:subheading class="mb-4">Securely store your payment method for automatic billing</flux:subheading>

                    <div class="grid md:grid-cols-2 gap-6 mb-6">
                        <button
                            wire:click="$set('paymentMethod', 'credit_card')"
                            type="button"
                            class="h-32 flex flex-col items-center justify-center gap-3 rounded-lg border-2 {{ $paymentMethod === 'credit_card' ? 'border-indigo-500 bg-indigo-50' : 'border-zinc-300 hover:border-indigo-500 bg-white hover:bg-zinc-50' }} transition-colors"
                        >
                            <svg class="w-10 h-10 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                            </svg>
                            <div class="text-center">
                                <div class="text-lg font-semibold text-zinc-900">Credit Card</div>
                                <div class="text-sm text-zinc-600">Visa, MasterCard, Amex</div>
                            </div>
                        </button>

                        <button
                            wire:click="$set('paymentMethod', 'ach')"
                            type="button"
                            class="h-32 flex flex-col items-center justify-center gap-3 rounded-lg border-2 {{ $paymentMethod === 'ach' ? 'border-indigo-500 bg-indigo-50' : 'border-zinc-300 hover:border-indigo-500 bg-white hover:bg-zinc-50' }} transition-colors"
                        >
                            <svg class="w-10 h-10 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                            </svg>
                            <div class="text-center">
                                <div class="text-lg font-semibold text-zinc-900">Bank Account</div>
                                <div class="text-sm text-zinc-600">ACH Transfer</div>
                            </div>
                        </button>
                    </div>

                    @if($paymentMethod === 'credit_card')
                        <div class="space-y-4">
                            <flux:field>
                                <flux:label>Card Number</flux:label>
                                <flux:input wire:model="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19" />
                                <flux:error name="cardNumber" />
                                <flux:description>Enter your 16-digit card number</flux:description>
                            </flux:field>

                            <div class="grid md:grid-cols-2 gap-4">
                                <flux:field>
                                    <flux:label>Expiration Date</flux:label>
                                    <flux:input wire:model="cardExpiry" placeholder="MM/YY" maxlength="5" />
                                    <flux:error name="cardExpiry" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>CVV</flux:label>
                                    <flux:input wire:model="cardCvv" placeholder="123" maxlength="4" />
                                    <flux:error name="cardCvv" />
                                </flux:field>
                            </div>
                        </div>
                    @elseif($paymentMethod === 'ach')
                        <div class="space-y-4">
                            <flux:field>
                                <flux:label>Bank Name</flux:label>
                                <flux:input wire:model="bankName" placeholder="First National Bank" />
                                <flux:error name="bankName" />
                            </flux:field>

                            <div class="grid md:grid-cols-2 gap-4">
                                <flux:field>
                                    <flux:label>Account Number</flux:label>
                                    <flux:input wire:model="accountNumber" placeholder="123456789" maxlength="17" />
                                    <flux:error name="accountNumber" />
                                    <flux:description>8-17 digits</flux:description>
                                </flux:field>

                                <flux:field>
                                    <flux:label>Routing Number</flux:label>
                                    <flux:input wire:model="routingNumber" placeholder="123456789" maxlength="9" />
                                    <flux:error name="routingNumber" />
                                    <flux:description>9 digits</flux:description>
                                </flux:field>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex gap-3">
                    <button wire:click="changePaymentMethod" type="button" class="px-4 py-2 bg-zinc-500 hover:bg-zinc-600 text-white font-medium rounded-lg">
                        ← Change Method
                    </button>
                    <button wire:click="editPaymentPlan" type="button" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white font-medium rounded-lg flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Edit Plan
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg">
                        Authorize & Continue
                    </button>
                </div>
            </form>
        </flux:card>
    @endif

    {{-- Step 7: Confirmation --}}
    @if($currentStep === 7)
        <flux:card class="p-8">
            <div class="text-center mb-8">
                @if($paymentProcessed)
                    {{-- Payment already confirmed --}}
                    <svg class="w-20 h-20 mx-auto mb-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    @if($isPaymentPlan)
                        <flux:heading size="2xl" class="text-green-600 mb-2">Payment Plan Confirmed!</flux:heading>
                        <flux:subheading>Your payment plan has been set up successfully</flux:subheading>
                    @else
                        <flux:heading size="2xl" class="text-green-600 mb-2">Payment Confirmed!</flux:heading>
                        <flux:subheading>Your payment has been processed successfully</flux:subheading>
                    @endif
                @else
                    {{-- Payment confirmation needed --}}
                    <svg class="w-20 h-20 mx-auto mb-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    @if($isPaymentPlan)
                        <flux:heading size="2xl" class="text-blue-600 mb-2">Review Your Payment Plan</flux:heading>
                        <flux:subheading>Please confirm the details below to set up your payment plan</flux:subheading>
                    @else
                        <flux:heading size="2xl" class="text-blue-600 mb-2">Review Your Payment</flux:heading>
                        <flux:subheading>Please confirm the details below to process your payment</flux:subheading>
                    @endif
                @endif
            </div>

            <flux:separator class="my-6" />

            <div class="space-y-3">
                <div class="flex justify-between py-3 border-b">
                    <flux:subheading>{{ $isPaymentPlan ? 'Plan ID:' : 'Transaction ID:' }}</flux:subheading>
                    <flux:text>{{ $transactionId }}</flux:text>
                </div>
                <div class="flex justify-between py-3 border-b">
                    <flux:subheading>Account:</flux:subheading>
                    <flux:text>{{ $clientInfo['client_name'] }}</flux:text>
                </div>
                
                @if($isPaymentPlan)
                    {{-- Payment Plan Details --}}
                    <div class="flex justify-between py-3 border-b">
                        <flux:subheading>Invoice Amount:</flux:subheading>
                        <flux:text>${{ number_format($paymentAmount, 2) }}</flux:text>
                    </div>
                    @if($creditCardFee > 0)
                        <div class="flex justify-between py-3 border-b">
                            <flux:subheading>Credit Card Fee:</flux:subheading>
                            <flux:text>${{ number_format($creditCardFee, 2) }}</flux:text>
                        </div>
                    @endif
                    @if($paymentPlanFee > 0)
                        <div class="flex justify-between py-3 border-b">
                            <flux:subheading>Plan Fee:</flux:subheading>
                            <flux:text>${{ number_format($paymentPlanFee, 2) }}</flux:text>
                        </div>
                    @endif
                    <div class="flex justify-between py-3 border-b">
                        <flux:subheading>Total Obligation:</flux:subheading>
                        <flux:text class="font-bold text-indigo-600">${{ number_format($paymentAmount + $creditCardFee + $paymentPlanFee, 2) }}</flux:text>
                    </div>
                    @if($downPayment > 0)
                        <div class="flex justify-between py-3 border-b">
                            <flux:subheading>Down Payment (Paid Today):</flux:subheading>
                            <flux:text class="font-bold text-green-600">${{ number_format($downPayment, 2) }}</flux:text>
                        </div>
                    @endif
                    <div class="flex justify-between py-3 border-b">
                        <flux:subheading>Remaining Balance:</flux:subheading>
                        <flux:text>${{ number_format(($paymentAmount + $creditCardFee + $paymentPlanFee) - $downPayment, 2) }}</flux:text>
                    </div>
                    <div class="flex justify-between py-3 border-b">
                        <flux:subheading>Payment Frequency:</flux:subheading>
                        <flux:text class="capitalize">{{ $planFrequency }}</flux:text>
                    </div>
                    <div class="flex justify-between py-3 border-b">
                        <flux:subheading>Number of Payments:</flux:subheading>
                        <flux:text>{{ $planDuration }} installments</flux:text>
                    </div>
                    
                    <div class="mt-6">
                        <flux:heading size="lg" class="mb-4">Your Payment Schedule</flux:heading>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Payment</flux:table.column>
                                <flux:table.column>Due Date</flux:table.column>
                                <flux:table.column align="end">Amount</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($paymentSchedule as $payment)
                                    <flux:table.row>
                                        <flux:table.cell>
                                            <div class="font-medium">{{ $payment['label'] }}</div>
                                        </flux:table.cell>
                                        <flux:table.cell class="whitespace-nowrap">
                                            {{ $payment['due_date'] }}
                                            @if($payment['payment_number'] === 0)
                                                <flux:badge color="green" size="sm" inset="top bottom" class="ml-2">Paid Today</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell variant="strong" align="end">
                                            ${{ number_format($payment['amount'], 2) }}
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                    
                    <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900 rounded-lg">
                        <flux:subheading class="mb-2">Important Information</flux:subheading>
                        <ul class="text-sm text-zinc-700 dark:text-zinc-300 space-y-1">
                            <li>• A confirmation email has been sent to your registered email address</li>
                            <li>• You will receive payment reminders before each due date</li>
                            <li>• Payments will be automatically charged to your selected payment method</li>
                            <li>• You can view or modify your payment plan in your account portal</li>
                        </ul>
                    </div>
                @else
                    {{-- One-time Payment Details --}}
                    <div class="flex justify-between py-3 border-b">
                        <flux:subheading>Payment Amount:</flux:subheading>
                        <flux:text>${{ number_format($paymentAmount, 2) }}</flux:text>
                    </div>
                    @if($creditCardFee > 0)
                        <div class="flex justify-between py-3 border-b">
                            <flux:subheading>Credit Card Fee (3%):</flux:subheading>
                            <flux:text>${{ number_format($creditCardFee, 2) }}</flux:text>
                        </div>
                        <div class="flex justify-between py-3 border-b bg-indigo-50">
                            <flux:heading size="lg">Total Amount:</flux:heading>
                            <flux:heading size="lg" class="text-indigo-600">${{ number_format($paymentAmount + $creditCardFee, 2) }}</flux:heading>
                        </div>
                    @endif
                    <div class="flex justify-between py-3 border-b">
                        <flux:subheading>Payment Method:</flux:subheading>
                        <flux:text class="capitalize">{{ str_replace('_', ' ', $paymentMethod) }}</flux:text>
                    </div>
                    <div class="flex justify-between py-3 border-b">
                        <flux:subheading>Invoices Paid:</flux:subheading>
                        <flux:text>{{ count($selectedInvoices) }} invoice(s)</flux:text>
                    </div>
                    @if($paymentNotes)
                        <div class="flex justify-between py-3 border-b">
                            <flux:subheading>Notes:</flux:subheading>
                            <flux:text>{{ $paymentNotes }}</flux:text>
                        </div>
                    @endif
                @endif
            </div>

            <div class="text-center mt-8 space-y-4">
                @if(!$paymentProcessed)
                    {{-- Payment not yet confirmed - show confirmation button --}}
                    <div class="space-y-4">
                        <button wire:click="confirmPayment" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed" type="button" class="px-8 py-4 bg-green-600 hover:bg-green-700 text-white font-medium text-xl rounded-lg flex items-center justify-center gap-3 mx-auto">
                            <svg wire:loading.remove class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span wire:loading.remove>
                                @if($isPaymentPlan)
                                    Confirm Payment Plan
                                @else
                                    Confirm Payment
                                @endif
                            </span>
                            <span wire:loading>Processing...</span>
                        </button>
                        <div class="flex gap-3 justify-center">
                            <button wire:click="goBack" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 hover:text-zinc-900">Back</button>
                            <button wire:click="startOver" type="button" class="px-4 py-2 text-sm font-medium text-zinc-700 hover:text-zinc-900">Start Over</button>
                        </div>
                    </div>
                @else
                    {{-- Payment confirmed - show success actions --}}
                    @if($isPaymentPlan)
                        <div class="bg-blue-50 dark:bg-blue-900 p-4 rounded-lg mb-4">
                            <flux:heading size="md" class="mb-2">Manage Your Payment Plan</flux:heading>
                            <div class="flex flex-wrap gap-3 justify-center">
                                <button wire:click="editPaymentPlan" type="button" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white font-medium rounded-lg flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                    Edit Payment Plan
                                </button>
                                <button wire:click="startOver" type="button" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg">
                                    Set Up New Plan
                                </button>
                            </div>
                            <div class="text-sm text-zinc-600 mt-3">
                                <a href="#" class="text-indigo-600 hover:text-indigo-700 underline mr-4">View in Account Portal</a>
                                <a href="#" class="text-indigo-600 hover:text-indigo-700 underline">Contact Support</a>
                            </div>
                        </div>
                    @else
                        <button wire:click="startOver" type="button" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-lg rounded-lg">
                            Make Another Payment
                        </button>
                    @endif
                @endif
            </div>
        </flux:card>
    @endif
    
</div>
