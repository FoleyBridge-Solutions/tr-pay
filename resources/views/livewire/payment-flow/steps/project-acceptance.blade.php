{{--
    Step: Engagement Acceptance

    User reviews and accepts pending engagements (grouped with their projects)
    before proceeding to payment. Uses Flux UI components throughout for
    consistent spacing, styling, and behavior.
--}}

@php
    $engagement = $pendingEngagements[$currentEngagementIndex];
    $engagementNumber = $currentEngagementIndex + 1;
    $totalEngagements = count($pendingEngagements);
    $projects = $engagement['projects'];
    $hasMultipleProjects = count($projects) > 1;
    $projectCount = count($projects);
@endphp

<x-payment.step
    name="project-acceptance"
    :show-back="false"
    :show-next="false"
    title=""
    subtitle=""
>
    <div class="space-y-6">
        {{-- Top Banner: Step title + counter --}}
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">Engagement Acceptance</flux:heading>
                <flux:subheading class="text-amber-600">
                    Review and accept the terms for this engagement before proceeding.
                </flux:subheading>
            </div>
            <flux:badge color="amber" size="lg" class="shrink-0 ml-4">
                {{ $engagementNumber }} of {{ $totalEngagements }}
            </flux:badge>
        </div>

        {{-- Engagement Summary --}}
        <flux:callout color="blue" icon="briefcase">
            <flux:callout.heading>{{ $engagement['client_name'] }}</flux:callout.heading>
            <flux:callout.text>
                {{ $engagement['engagement_type'] }}
                <span class="mx-1">&middot;</span>
                <span class="font-mono">ID: {{ $engagement['engagement_KEY'] }}</span>
                @if($hasMultipleProjects)
                    <span class="mx-1">&middot;</span>
                    {{ $projectCount }} projects
                @endif
            </flux:callout.text>
            <x-slot:controls>
                <div class="text-right px-2">
                    <flux:subheading class="text-xs uppercase tracking-wide">Total Budget</flux:subheading>
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                        ${{ number_format($engagement['total_budget'], 2) }}
                    </div>
                </div>
            </x-slot:controls>
        </flux:callout>

        <flux:separator />

        {{-- Projects Accordion --}}
        <div>
            <flux:heading size="xl" class="flex items-center gap-2 mb-3">
                <flux:icon icon="clipboard-document-list" variant="mini" class="text-zinc-400" />
                {{ $hasMultipleProjects ? 'Projects in This Engagement' : 'Project Details' }}
            </flux:heading>

            <flux:accordion transition>
                @foreach($projects as $index => $project)
                    <flux:accordion.item>
                        <flux:accordion.heading>
                            <span class="flex items-center justify-between w-full gap-3">
                                <span class="truncate">
                                    @if($project['notes'])
                                        {{ \Illuminate\Support\Str::limit(trim($project['notes']), 80) }}
                                    @else
                                        Project #{{ $project['project_number'] }}
                                    @endif
                                </span>
                                <span class="font-semibold shrink-0">
                                    ${{ number_format($project['budget_amount'], 2) }}
                                </span>
                            </span>
                        </flux:accordion.heading>

                        <flux:accordion.content>
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>Start Date</flux:table.column>
                                    <flux:table.column>End Date</flux:table.column>
                                    <flux:table.column align="end">Budget</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    <flux:table.row>
                                        <flux:table.cell>
                                            {{ $project['start_date'] ? \Carbon\Carbon::parse($project['start_date'])->format('M d, Y') : '—' }}
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            {{ $project['end_date'] ? \Carbon\Carbon::parse($project['end_date'])->format('M d, Y') : '—' }}
                                        </flux:table.cell>
                                        <flux:table.cell align="end" variant="strong">
                                            ${{ number_format($project['budget_amount'], 2) }}
                                        </flux:table.cell>
                                    </flux:table.row>
                                </flux:table.rows>
                            </flux:table>

                            @if($project['notes'])
                                <flux:callout color="zinc" class="mt-3">
                                    <flux:callout.text>{{ trim($project['notes']) }}</flux:callout.text>
                                </flux:callout>
                            @endif
                        </flux:accordion.content>
                    </flux:accordion.item>
                @endforeach
            </flux:accordion>
        </div>

        <flux:separator />

        {{-- Terms & Conditions --}}
        <flux:callout color="amber" icon="document-text">
            <flux:callout.heading>Terms & Conditions</flux:callout.heading>
            <flux:callout.text>
                <p class="font-medium mb-1.5">By accepting this engagement, you agree to:</p>
                <ul class="list-disc pl-5 space-y-0.5">
                    <li>Pay the engagement budget amount of <strong>${{ number_format($engagement['total_budget'], 2) }}</strong>{{ $hasMultipleProjects ? ' (across '.$projectCount.' projects)' : '' }}</li>
                    <li>Work will commence upon engagement acceptance and payment</li>
                    <li>Project scope and deliverables as outlined in the engagement agreement</li>
                    <li>Payment terms and conditions as specified in your service agreement</li>
                    <li>Any additional terms specific to {{ $engagement['engagement_type'] }} engagements</li>
                </ul>
            </flux:callout.text>
        </flux:callout>

        {{-- Acceptance Checkbox + Buttons --}}
        <form wire:submit.prevent="acceptEngagement" class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                <div class="flex-1 min-w-0">
                    <flux:checkbox
                        wire:model.live="acceptTerms"
                        label="I accept the terms and agree to pay ${{ number_format($engagement['total_budget'], 2) }}."
                    />
                </div>

                <div class="flex gap-2 shrink-0">
                    <flux:button
                        type="button"
                        variant="danger"
                        size="sm"
                        wire:click="declineEngagement"
                    >
                        Decline
                    </flux:button>
                    <flux:button
                        type="submit"
                        variant="primary"
                        size="sm"
                        :disabled="!$acceptTerms"
                    >
                        Accept & Continue
                    </flux:button>
                </div>
            </div>

            @error('acceptTerms')
                <flux:text class="text-red-600">{{ $message }}</flux:text>
            @enderror
        </form>

        {{-- Remaining engagements note --}}
        @if($totalEngagements > 1 && $engagementNumber < $totalEngagements)
            <flux:text class="text-center text-sm">
                {{ $totalEngagements - $engagementNumber }} more {{ ($totalEngagements - $engagementNumber) === 1 ? 'engagement' : 'engagements' }} to review after this.
            </flux:text>
        @endif
    </div>
</x-payment.step>
