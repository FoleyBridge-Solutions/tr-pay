{{--
    Step: Project Acceptance
    
    User reviews and accepts pending projects before proceeding to payment.
--}}

@php
    $project = $pendingProjects[$currentProjectIndex];
    $projectNumber = $currentProjectIndex + 1;
    $totalProjects = count($pendingProjects);
@endphp

<x-payment.step 
    name="project-acceptance"
    :show-back="false"
    :show-next="false"
>
    {{-- Header --}}
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
    <div class="bg-gradient-to-br from-indigo-50 to-blue-50 dark:from-indigo-950 dark:to-blue-950 border-2 border-zinc-300 rounded-lg p-6 mb-6">
        <flux:heading size="lg" class="mb-4 text-zinc-900 dark:text-zinc-100">
            {{ $project['project_name'] }}
        </flux:heading>
        
        <div class="grid md:grid-cols-2 gap-4 mb-4">
            <div>
                <flux:subheading class="text-sm text-zinc-700 dark:text-zinc-300">Project ID</flux:subheading>
                <flux:text class="font-mono">{{ $project['engagement_id'] }}</flux:text>
            </div>
            <div>
                <flux:subheading class="text-sm text-zinc-700 dark:text-zinc-300">Engagement Type</flux:subheading>
                <flux:text>{{ $project['engagement_type'] }}</flux:text>
            </div>
            @if($project['start_date'])
            <div>
                <flux:subheading class="text-sm text-zinc-700 dark:text-zinc-300">Start Date</flux:subheading>
                <flux:text>{{ \Carbon\Carbon::parse($project['start_date'])->format('M d, Y') }}</flux:text>
            </div>
            @endif
            @if($project['end_date'])
            <div>
                <flux:subheading class="text-sm text-zinc-700 dark:text-zinc-300">End Date</flux:subheading>
                <flux:text>{{ \Carbon\Carbon::parse($project['end_date'])->format('M d, Y') }}</flux:text>
            </div>
            @endif
        </div>
        
        <div class="bg-white dark:bg-zinc-900 rounded-lg p-4 border border-zinc-200 dark:border-zinc-700">
            <flux:subheading class="text-sm mb-2">Project Budget</flux:subheading>
            <flux:heading size="2xl" class="text-zinc-800 dark:text-zinc-400">
                ${{ number_format($project['budget_amount'], 2) }}
            </flux:heading>
        </div>
        
        @if($project['notes'])
        <div class="mt-4">
            <flux:subheading class="text-sm mb-2">Project Notes</flux:subheading>
            <div class="bg-white dark:bg-zinc-900 rounded-lg p-4 border border-zinc-200 dark:border-zinc-700 text-sm">
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
    <form wire:submit.prevent="acceptProject" class="space-y-6">
        <div class="bg-white dark:bg-zinc-900 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <label class="flex items-start gap-3 cursor-pointer">
                <input 
                    type="checkbox" 
                    wire:model.live="acceptTerms" 
                    class="mt-1 w-5 h-5 text-zinc-800 rounded border-gray-300 focus:ring-zinc-500 dark:bg-zinc-800 dark:border-zinc-600"
                >
                <span class="text-sm text-zinc-700 dark:text-zinc-300 font-medium">
                    I accept the terms and conditions for this project and agree to pay the stated budget amount.
                </span>
            </label>
            @error('acceptTerms') 
                <p class="mt-2 text-sm text-red-600 ml-8">{{ $message }}</p> 
            @enderror
        </div>

        <div class="flex gap-3 pt-4">
            <flux:button 
                type="button"
                variant="danger"
                wire:click="declineProject"
            >
                Decline Project
            </flux:button>
            <flux:button 
                type="submit"
                variant="primary"
                class="flex-1"
                :disabled="!$acceptTerms"
            >
                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                Accept Project & Continue
            </flux:button>
        </div>
    </form>

    @if($totalProjects > 1)
    <div class="mt-6 text-center text-sm text-zinc-600">
        You will be asked to review {{ $totalProjects - $projectNumber }} more {{ $totalProjects - $projectNumber === 1 ? 'project' : 'projects' }} after this.
    </div>
    @endif
</x-payment.step>
