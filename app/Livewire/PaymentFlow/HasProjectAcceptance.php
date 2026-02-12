<?php

// app/Livewire/PaymentFlow/HasProjectAcceptance.php

namespace App\Livewire\PaymentFlow;

use Illuminate\Support\Facades\Log;

/**
 * Trait for engagement acceptance functionality.
 *
 * Engagements are grouped from the repository — each engagement may contain
 * one or more child projects (via Schedule_Item -> Project). The user
 * accepts or declines an entire engagement at a time.
 *
 * This trait handles:
 * - Checking for pending engagements requiring acceptance
 * - Accepting or declining engagements (with all their projects)
 * - Adding accepted engagements as synthetic invoices
 * - Persisting acceptances to database after payment
 */
trait HasProjectAcceptance
{
    // ==================== Engagement Acceptance Properties ====================
    // Note: These are declared in the main component, not here
    // $pendingEngagements, $currentEngagementIndex, $acceptTerms,
    // $hasEngagementsToAccept, $engagementsToPersist

    /**
     * Check for pending engagements for the client group.
     *
     * Populates $pendingEngagements with engagement-grouped data from the
     * repository. Each engagement contains a 'projects' array of child items.
     */
    private function checkForPendingEngagements(): void
    {
        $clientKey = isset($this->clientInfo['clients'])
            ? $this->clientInfo['clients'][0]['client_KEY']
            : $this->clientInfo['client_KEY'];

        $this->pendingEngagements = $this->paymentRepo->getPendingProjectsForClientGroup($clientKey);
        $this->hasEngagementsToAccept = count($this->pendingEngagements) > 0;
        $this->currentEngagementIndex = 0;
    }

    /**
     * Accept the current engagement (and all its projects).
     */
    public function acceptEngagement(): void
    {
        // Validate checkbox
        $this->validate([
            'acceptTerms' => 'accepted',
        ], [
            'acceptTerms.accepted' => 'You must agree to the terms and conditions to continue.',
        ]);

        $currentEngagement = $this->pendingEngagements[$this->currentEngagementIndex];

        // Queue acceptance for persistence after payment.
        // One record per engagement — budget is the sum of all child projects.
        $this->engagementsToPersist[] = [
            'project_engagement_key' => $currentEngagement['engagement_KEY'],
            'client_key' => $currentEngagement['client_KEY'],
            'client_group_name' => $currentEngagement['group_name'] ?? null,
            'engagement_id' => $currentEngagement['engagement_KEY'],
            'project_name' => $currentEngagement['engagement_name'],
            'budget_amount' => $currentEngagement['total_budget'],
            'project_description' => $this->getFirstProjectNotes($currentEngagement),
            'accepted' => true,
            'accepted_at' => now(),
            'accepted_by_ip' => request()->ip(),
            'acceptance_signature' => 'Checkbox Accepted',
        ];

        Log::info('Engagement acceptance queued', [
            'engagement_KEY' => $currentEngagement['engagement_KEY'],
            'client_key' => $currentEngagement['client_KEY'],
            'project_count' => count($currentEngagement['projects']),
            'total_budget' => $currentEngagement['total_budget'],
        ]);

        // Move to next engagement or continue to invoices
        $this->currentEngagementIndex++;
        $this->acceptTerms = false; // Reset checkbox for next engagement

        if ($this->currentEngagementIndex >= count($this->pendingEngagements)) {
            // All engagements reviewed, proceed to invoice selection
            // Reset index to last engagement so user can go back if needed
            $this->currentEngagementIndex = count($this->pendingEngagements) - 1;
            $this->loadClientInvoices();
            $this->addAcceptedEngagementsAsInvoices();
            $this->goToStep(Steps::INVOICE_SELECTION);
        }
        // else: Stay on project acceptance to show next engagement
    }

    /**
     * Decline the current engagement (and all its projects).
     */
    public function declineEngagement(): void
    {
        $currentEngagement = $this->pendingEngagements[$this->currentEngagementIndex];

        Log::info('Engagement declined', [
            'engagement_KEY' => $currentEngagement['engagement_KEY'],
            'client_key' => $currentEngagement['client_KEY'],
            'project_count' => count($currentEngagement['projects']),
        ]);

        // Move to next engagement or continue
        $this->currentEngagementIndex++;
        $this->acceptTerms = false; // Reset checkbox

        if ($this->currentEngagementIndex >= count($this->pendingEngagements)) {
            // All engagements reviewed, proceed to invoices
            $this->loadClientInvoices();
            $this->addAcceptedEngagementsAsInvoices();
            $this->goToStep(Steps::INVOICE_SELECTION);
        }
    }

    /**
     * Add accepted engagements as synthetic invoices to the invoice list.
     *
     * Creates one invoice per accepted engagement using the total_budget as the amount.
     */
    private function addAcceptedEngagementsAsInvoices(): void
    {
        foreach ($this->pendingEngagements as $engagement) {
            // Check if engagement is in the persistence queue (i.e. was accepted)
            $queuedAcceptance = collect($this->engagementsToPersist)
                ->firstWhere('project_engagement_key', $engagement['engagement_KEY']);

            if ($queuedAcceptance) {
                // Use the earliest project start date for the invoice date
                $invoiceDate = $this->getEarliestProjectDate($engagement);

                $this->openInvoices[] = [
                    'ledger_entry_KEY' => 'project_'.$engagement['engagement_KEY'],
                    'invoice_number' => 'PROJECT-'.$engagement['engagement_KEY'],
                    'invoice_date' => $invoiceDate ? date('m/d/Y', strtotime($invoiceDate)) : date('m/d/Y'),
                    'due_date' => 'Upon Acceptance',
                    'type' => 'Engagement Budget',
                    'open_amount' => number_format($engagement['total_budget'], 2, '.', ''),
                    'description' => $engagement['engagement_name'],
                    'client_name' => $engagement['client_name'],
                    'client_id' => $engagement['client_id'] ?? '',
                    'client_KEY' => $engagement['client_KEY'],
                    'is_project' => true, // Flag to identify as engagement/project
                    'engagement_id' => $engagement['engagement_KEY'],
                ];

                // Pre-select the engagement invoice
                $this->selectedInvoices[] = 'PROJECT-'.$engagement['engagement_KEY'];
            }
        }

        // Recalculate total
        $this->calculatePaymentAmount();
    }

    // persistAcceptedEngagements() has been moved to PaymentOrchestrator.
    // Engagement persistence is now handled by PaymentOrchestrator::persistEngagements()
    // for one-time payments (both public and admin flows).

    /**
     * Get the notes from the first project in an engagement.
     *
     * Used to pass to EngagementAcceptanceService for year-aware type resolution.
     *
     * @param  array  $engagement  An engagement group from pendingEngagements
     * @return string|null The first project's notes/description, or null
     */
    private function getFirstProjectNotes(array $engagement): ?string
    {
        if (! empty($engagement['projects'])) {
            return $engagement['projects'][0]['notes'] ?? null;
        }

        return null;
    }

    /**
     * Get the earliest project start date for an engagement.
     *
     * Falls back to project_date if start_date is not set.
     *
     * @param  array  $engagement  An engagement group from pendingEngagements
     * @return string|null The earliest date string, or null
     */
    private function getEarliestProjectDate(array $engagement): ?string
    {
        $dates = [];

        foreach ($engagement['projects'] as $project) {
            $date = $project['start_date'] ?? $project['project_date'] ?? null;
            if ($date) {
                $dates[] = $date;
            }
        }

        if (empty($dates)) {
            return null;
        }

        sort($dates);

        return $dates[0];
    }
}
