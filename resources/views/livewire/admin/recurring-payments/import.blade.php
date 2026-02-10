<div>
    <div class="mb-8">
        <flux:heading size="xl">Import Recurring Payments</flux:heading>
        <flux:subheading>Upload a CSV or Excel file with recurring payment schedules and card/ACH details</flux:subheading>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Import Form --}}
        <div class="lg:col-span-2">
            <flux:card>
                <div class="p-6">
                    {{-- Toggle Input Method --}}
                    <div class="flex justify-end mb-4">
                        <flux:button wire:click="toggleInputMethod" variant="ghost" size="sm">
                            {{ $useTextInput ? 'Upload File Instead' : 'Paste CSV Instead' }}
                        </flux:button>
                    </div>

                    @if(!$useTextInput)
                        {{-- File Upload --}}
                        <div class="mb-6">
                            <flux:field>
                                <flux:label>Import File</flux:label>
                                <div
                                    x-data="{ dragging: false }"
                                    x-on:dragover.prevent="dragging = true"
                                    x-on:dragleave.prevent="dragging = false"
                                    x-on:drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files; $refs.fileInput.dispatchEvent(new Event('change'))"
                                    class="border-2 border-dashed rounded-lg p-8 text-center transition-colors"
                                    :class="dragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-300 dark:border-zinc-700'"
                                >
                                    <input
                                        type="file"
                                        wire:model="csvFile"
                                        x-ref="fileInput"
                                        accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                                        class="hidden"
                                        id="csv-upload"
                                    />
                                    <label for="csv-upload" class="cursor-pointer">
                                        <flux:icon name="arrow-up-tray" class="w-10 h-10 mx-auto text-zinc-400 mb-2" />
                                        <flux:text class="text-zinc-600 dark:text-zinc-400">
                                            Drop your file here or <span class="text-blue-600 underline">browse</span>
                                        </flux:text>
                                        <flux:text class="text-sm text-zinc-500 mt-1">Supports CSV, XLSX, XLS (max 5MB)</flux:text>
                                    </label>

                                    @if($csvFile)
                                        <div class="mt-4 p-3 bg-zinc-100 dark:bg-zinc-800 rounded-lg inline-flex items-center gap-2">
                                            <flux:icon name="document" class="w-5 h-5 text-zinc-500" />
                                            <span>{{ $csvFile->getClientOriginalName() }}</span>
                                            <flux:button wire:click="$set('csvFile', null)" size="sm" variant="ghost" icon="x-mark" />
                                        </div>
                                    @endif
                                </div>
                                <flux:error name="csvFile" />
                            </flux:field>
                        </div>
                    @else
                        {{-- Text Input --}}
                        <div class="mb-6">
                            <flux:field>
                                <flux:label>CSV Content</flux:label>
                                <flux:textarea
                                    wire:model="csvContent"
                                    rows="12"
                                    placeholder="Paste your CSV content here..."
                                    class="font-mono text-sm"
                                />
                                <flux:description>Paste your CSV data including the header row</flux:description>
                                <flux:error name="csvContent" />
                            </flux:field>
                        </div>
                    @endif

                    {{-- Import Result --}}
                    @if($importResult)
                        <div class="mb-6">
                            @if($importResult['success'])
                                <flux:callout variant="success" icon="check-circle">
                                    <flux:callout.heading>Import Successful</flux:callout.heading>
                                    <flux:callout.text>
                                        Successfully imported {{ $importResult['imported'] }} recurring payments.
                                        @if(!empty($importResult['warnings']))
                                            <strong>({{ count($importResult['warnings']) }} items need review)</strong>
                                        @endif
                                    </flux:callout.text>
                                </flux:callout>

                                @if(!empty($importResult['warnings']))
                                    <div class="mt-4 bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 max-h-48 overflow-y-auto">
                                        <flux:text class="font-medium text-amber-800 dark:text-amber-200 mb-2">Warnings (records imported but need review):</flux:text>
                                        <ul class="list-disc list-inside space-y-1 text-sm text-amber-700 dark:text-amber-300">
                                            @foreach($importResult['warnings'] as $warning)
                                                <li>Row {{ $warning['row'] }}: {{ $warning['message'] }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            @else
                                <flux:callout variant="danger" icon="exclamation-triangle">
                                    <flux:callout.heading>Import Failed</flux:callout.heading>
                                    <flux:callout.text>{{ $importResult['error'] }}</flux:callout.text>
                                </flux:callout>

                                @if(!empty($importResult['errors']))
                                    <div class="mt-4 bg-red-50 dark:bg-red-900/20 rounded-lg p-4 max-h-48 overflow-y-auto">
                                        <flux:text class="font-medium text-red-800 dark:text-red-200 mb-2">Errors:</flux:text>
                                        <ul class="list-disc list-inside space-y-1 text-sm text-red-700 dark:text-red-300">
                                            @foreach($importResult['errors'] as $error)
                                                <li>Row {{ $error['row'] }}: {{ $error['message'] }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endif

                    {{-- Actions --}}
                    <div class="flex justify-between">
                        <flux:button href="{{ route('admin.recurring-payments') }}" variant="ghost">
                            Back to List
                        </flux:button>
                        <div class="flex gap-2">
                            @if($importResult)
                                <flux:button wire:click="resetForm" variant="ghost">
                                    Reset
                                </flux:button>
                            @endif
                            <flux:button
                                wire:click="import"
                                variant="primary"
                                :disabled="$processing || (!$csvFile && !$csvContent)"
                            >
                                @if($processing)
                                    <flux:icon name="arrow-path" class="w-4 h-4 animate-spin mr-2" />
                                    Importing...
                                @else
                                    Import
                                @endif
                            </flux:button>
                        </div>
                    </div>
                </div>
            </flux:card>
        </div>

        {{-- Instructions --}}
        <div class="lg:col-span-1">
            <flux:card class="sticky top-4">
                <div class="p-6">
                    <flux:heading size="md" class="mb-4">File Format</flux:heading>

                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400 mb-4">
                        Your file can use either system column names or spreadsheet-friendly names:
                    </flux:text>

                    <div class="space-y-3 text-sm">
                        <div>
                            <flux:text class="font-medium">Required Columns:</flux:text>
                            <ul class="list-disc list-inside text-zinc-600 dark:text-zinc-400 mt-1">
                                <li>Customer ID <span class="text-zinc-400">(must match PracticeCS)</span></li>
                                <li>Customer Name <span class="text-zinc-400">(or client_name)</span></li>
                                <li>Amount <span class="text-zinc-400">($0 allowed, marked for review)</span></li>
                                <li>Frequency <span class="text-zinc-400">(e.g., "Every 3 months")</span></li>
                                <li>Next Due <span class="text-zinc-400">(or start_date)</span></li>
                            </ul>
                        </div>

                        <div>
                            <flux:text class="font-medium">Optional:</flux:text>
                            <ul class="list-disc list-inside text-zinc-600 dark:text-zinc-400 mt-1">
                                <li>Ends <span class="text-zinc-400">(e.g., "After 9 occurrences")</span></li>
                                <li>Description</li>
                            </ul>
                        </div>

                        <div>
                            <flux:text class="font-medium">For ACH/eCheck:</flux:text>
                            <ul class="list-disc list-inside text-zinc-600 dark:text-zinc-400 mt-1">
                                <li>Method <span class="text-zinc-400">(eCheck)</span></li>
                                <li>ACH- Rout# <span class="text-zinc-400">(auto-padded to 9 digits)</span></li>
                                <li>ACH- ACT # <span class="text-zinc-400">(or account_number)</span></li>
                            </ul>
                        </div>

                        <div>
                            <flux:text class="font-medium">For Card Payments:</flux:text>
                            <ul class="list-disc list-inside text-zinc-600 dark:text-zinc-400 mt-1">
                                <li>Method <span class="text-zinc-400">(VISA, MC, Amex)</span></li>
                                <li>CC# <span class="text-zinc-400">(or card_number)</span></li>
                                <li>CC-EXP <span class="text-zinc-400">(or card_expiry)</span></li>
                                <li>CC-CVV <span class="text-zinc-400">(optional)</span></li>
                            </ul>
                        </div>

                        <div class="mt-2 p-2 bg-amber-50 dark:bg-amber-900/20 rounded text-xs">
                            <flux:text class="text-amber-800 dark:text-amber-200">
                                Records without payment info will be saved as "pending" for later completion.
                            </flux:text>
                        </div>
                    </div>

                    <flux:separator class="my-4" />

                    <flux:button wire:click="downloadTemplate" variant="ghost" class="w-full" icon="arrow-down-tray">
                        Download Template
                    </flux:button>
                </div>
            </flux:card>
        </div>
    </div>
</div>
