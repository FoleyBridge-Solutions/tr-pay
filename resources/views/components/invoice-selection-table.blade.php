@props([
    'invoices' => [],
    'wireModel' => 'selectedInvoices',
    'selectionKey' => 'ledger_entry_KEY',
    'compact' => false,
])

@if(count($invoices) === 0)
    <div class="text-center py-8 text-zinc-500">
        No open invoices found for this client.
    </div>
@else
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
        <flux:checkbox.group wire:model.live="{{ $wireModel }}">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="w-10">
                        <flux:checkbox.all />
                    </flux:table.column>
                    <flux:table.column>Invoice #</flux:table.column>
                    @if(!$compact)
                        <flux:table.column>Date</flux:table.column>
                        <flux:table.column>Due Date</flux:table.column>
                        <flux:table.column>Type</flux:table.column>
                    @endif
                    <flux:table.column class="text-right">Amount</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($invoices as $invoice)
                        @php $key = (string) $invoice[$selectionKey]; @endphp
                        <flux:table.row
                            wire:key="invoice-{{ $key }}"
                            x-on:click="$el.querySelector('input[type=checkbox]')?.click()"
                            class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800"
                        >
                            <flux:table.cell x-on:click.stop>
                                <flux:checkbox value="{{ $key }}" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <span class="font-mono text-sm">{{ $invoice['invoice_number'] }}</span>
                                @if($compact && !empty($invoice['description']))
                                    <span class="text-xs text-zinc-500 block truncate">{{ $invoice['description'] }}</span>
                                @endif
                            </flux:table.cell>
                            @if(!$compact)
                                <flux:table.cell>{{ $invoice['invoice_date'] }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice['due_date'] }}</flux:table.cell>
                                <flux:table.cell>{{ $invoice['type'] }}</flux:table.cell>
                            @endif
                            <flux:table.cell class="text-right font-medium">
                                ${{ number_format($invoice['open_amount'], 2) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:checkbox.group>
    </div>
@endif
