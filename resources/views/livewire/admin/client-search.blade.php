<div>
    {{-- Selected Client Card (compact/showSelected mode only) --}}
    @if($showSelected && $selectedClient)
        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 flex items-center justify-between">
            <div>
                <flux:text class="font-medium">{{ $selectedClient['client_name'] }}</flux:text>
                <flux:text class="text-sm text-zinc-500">ID: {{ $selectedClient['client_id'] }}</flux:text>
            </div>
            <flux:button wire:click="clearClient" variant="ghost" size="sm" icon="x-mark">
                Change
            </flux:button>
        </div>
    @else
        {{-- Search Bar --}}
        <div class="flex flex-col md:flex-row gap-4 mb-4">
            <div class="w-full md:w-48">
                <flux:select wire:model.live="searchType">
                    <option value="name">By Name</option>
                    <option value="client_id">By Client ID</option>
                    <option value="tax_id">By Tax ID (last 4)</option>
                </flux:select>
            </div>
            <div class="flex-1">
                <flux:input
                    wire:model="searchQuery"
                    wire:keydown.enter="searchClients"
                    placeholder="{{ $searchType === 'name' ? 'Enter client name...' : ($searchType === 'client_id' ? 'Enter client ID...' : 'Enter last 4 digits of SSN/EIN...') }}"
                    icon="magnifying-glass"
                    maxlength="{{ $searchType === 'tax_id' ? '4' : '' }}"
                />
            </div>
            <flux:button wire:click="searchClients" variant="primary" :disabled="$loading">
                @if($loading)
                    <flux:icon name="arrow-path" class="w-4 h-4 animate-spin mr-2" />
                @endif
                Search
            </flux:button>
        </div>

        {{-- Error Message --}}
        @if($errorMessage)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">
                {{ $errorMessage }}
            </flux:callout>
        @endif

        {{-- Search Results --}}
        @if(count($searchResults) > 0)
            @if($mode === 'compact')
                {{-- Compact mode: scrollable button list --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden max-h-48 overflow-y-auto">
                    @foreach($searchResults as $client)
                        <button
                            type="button"
                            wire:click="selectClient('{{ $client['client_id'] }}')"
                            wire:key="client-{{ $client['client_id'] }}"
                            class="w-full px-4 py-2 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 last:border-b-0"
                        >
                            <span class="font-medium">{{ $client['client_name'] }}</span>
                            <span class="text-zinc-500 text-sm ml-2">{{ $client['client_id'] }}</span>
                        </button>
                    @endforeach
                </div>
            @elseif($mode === 'browse')
                {{-- Browse mode: table with View links and extra detail --}}
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Client ID</flux:table.column>
                        <flux:table.column>Name</flux:table.column>
                        @if($showTaxId)
                            <flux:table.column>Tax ID</flux:table.column>
                        @endif
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($searchResults as $client)
                            <flux:table.row wire:key="client-{{ $client['client_id'] }}">
                                <flux:table.cell class="font-mono">{{ $client['client_id'] }}</flux:table.cell>
                                <flux:table.cell>
                                    <span class="font-medium">{{ $client['client_name'] }}</span>
                                    @if(($client['individual_first_name'] ?? null) && ($client['individual_last_name'] ?? null))
                                        <span class="text-zinc-500 text-sm block">{{ $client['individual_first_name'] }} {{ $client['individual_last_name'] }}</span>
                                    @endif
                                </flux:table.cell>
                                @if($showTaxId)
                                    <flux:table.cell class="text-zinc-500">
                                        @if($client['federal_tin'] ?? null)
                                            ****{{ substr($client['federal_tin'], -4) }}
                                        @else
                                            -
                                        @endif
                                    </flux:table.cell>
                                @endif
                                <flux:table.cell>
                                    <flux:button href="{{ route('admin.clients.show', $client['client_id']) }}" size="sm" variant="ghost" icon="eye">
                                        View
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                @if(count($searchResults) >= $limit)
                    <div class="p-4 border-t border-zinc-200 dark:border-zinc-700 text-center text-sm text-zinc-500">
                        Showing first {{ $limit }} results. Refine your search for more specific results.
                    </div>
                @endif
            @else
                {{-- Select mode: table with Select buttons --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Client ID</flux:table.column>
                            <flux:table.column>Name</flux:table.column>
                            @if($showTaxId)
                                <flux:table.column>Tax ID</flux:table.column>
                            @endif
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($searchResults as $client)
                                <flux:table.row wire:key="client-{{ $client['client_id'] }}">
                                    <flux:table.cell class="font-mono">{{ $client['client_id'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $client['client_name'] }}</flux:table.cell>
                                    @if($showTaxId)
                                        <flux:table.cell class="text-zinc-500">****{{ substr($client['federal_tin'] ?? '', -4) }}</flux:table.cell>
                                    @endif
                                    <flux:table.cell>
                                        <flux:button wire:click="selectClient('{{ $client['client_id'] }}')" size="sm" variant="primary">
                                            Select
                                        </flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        @elseif($searchQuery && !$loading && count($searchResults) === 0 && !$errorMessage)
            {{-- Empty state --}}
            @if($mode === 'browse')
                <div class="p-12 text-center">
                    <flux:icon name="users" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                    <flux:heading size="lg">No clients found</flux:heading>
                    <flux:text class="text-zinc-500">Try a different search term</flux:text>
                </div>
            @else
                <div class="text-center py-8 text-zinc-500">
                    No clients found matching your search.
                </div>
            @endif
        @elseif(!$searchQuery && $mode === 'browse')
            {{-- Browse mode initial state --}}
            <div class="p-12 text-center">
                <flux:icon name="magnifying-glass" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">Search for clients</flux:heading>
                <flux:text class="text-zinc-500">Enter a name, client ID, or tax ID to search</flux:text>
            </div>
        @endif
    @endif
</div>
