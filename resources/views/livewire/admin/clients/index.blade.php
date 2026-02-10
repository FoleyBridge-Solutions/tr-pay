<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <flux:heading size="xl">Clients</flux:heading>
            <flux:subheading>Search and view client information from PracticeCS</flux:subheading>
        </div>
    </div>

    {{-- Search --}}
    <flux:card class="mb-6">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="w-full md:w-48">
                <flux:select wire:model.live="searchType">
                    <option value="name">By Name</option>
                    <option value="client_id">By Client ID</option>
                    <option value="tax_id">By Tax ID (last 4)</option>
                </flux:select>
            </div>
            <div class="flex-1">
                <flux:input
                    wire:model="search"
                    wire:keydown.enter="searchClients"
                    placeholder="{{ $searchType === 'name' ? 'Enter client name...' : ($searchType === 'client_id' ? 'Enter client ID...' : 'Enter last 4 of SSN/EIN...') }}"
                    icon="magnifying-glass"
                />
            </div>
            <flux:button wire:click="searchClients" variant="primary" :disabled="$loading">
                @if($loading)
                    <flux:icon name="arrow-path" class="w-4 h-4 animate-spin mr-2" />
                @endif
                Search
            </flux:button>
        </div>
    </flux:card>

    <flux:card>
        @if(count($searchResults) === 0 && $search)
            <div class="p-12 text-center">
                <flux:icon name="users" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">No clients found</flux:heading>
                <flux:text class="text-zinc-500">Try a different search term</flux:text>
            </div>
        @elseif(count($searchResults) === 0)
            <div class="p-12 text-center">
                <flux:icon name="magnifying-glass" class="w-12 h-12 mx-auto text-zinc-400 mb-4" />
                <flux:heading size="lg">Search for clients</flux:heading>
                <flux:text class="text-zinc-500">Enter a name, client ID, or tax ID to search</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Client ID</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Tax ID</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($searchResults as $client)
                        <flux:table.row wire:key="client-{{ $client['client_id'] }}">
                            <flux:table.cell class="font-mono">{{ $client['client_id'] }}</flux:table.cell>
                            <flux:table.cell>
                                <span class="font-medium">{{ $client['client_name'] }}</span>
                                @if($client['individual_first_name'] && $client['individual_last_name'])
                                    <span class="text-zinc-500 text-sm block">{{ $client['individual_first_name'] }} {{ $client['individual_last_name'] }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">
                                @if($client['federal_tin'])
                                    ****{{ substr($client['federal_tin'], -4) }}
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button href="{{ route('admin.clients.show', $client['client_id']) }}" size="sm" variant="ghost" icon="eye">
                                    View
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if(count($searchResults) >= 50)
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-700 text-center text-sm text-zinc-500">
                    Showing first 50 results. Refine your search for more specific results.
                </div>
            @endif
        @endif
    </flux:card>
</div>
