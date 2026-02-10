<?php

// app/Livewire/PaymentFlow/HasProjectAcceptance.php

namespace App\Livewire\PaymentFlow;

use App\Models\ProjectAcceptance;
use App\Services\EngagementAcceptanceService;
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

    /**
     * Persist queued accepted engagements to the database and update PracticeCS.
     *
     * This method:
     * 1. Saves acceptance records to local SQLite database (audit trail)
     * 2. Updates PracticeCS to change engagement type from EXPANSION to target type
     *    (e.g., EXPTAX -> TAXFEEREQ, EXPADVISORY -> 2026ADVISOR) using year-aware
     *    resolution based on the project description's tax year
     * 3. Updates local record with payment and sync status
     */
    private function persistAcceptedEngagements(): void
    {
        $engagementService = app(EngagementAcceptanceService::class);
        $staffKey = config('practicecs.payment_integration.staff_key', 1552);

        foreach ($this->engagementsToPersist as $engagement) {
            // Check if already persisted to avoid duplicates
            $existing = ProjectAcceptance::where('project_engagement_key', $engagement['project_engagement_key'])
                ->first();

            if (! $existing) {
                // 1. Save to local SQLite database (audit trail) with payment info
                $acceptance = ProjectAcceptance::create(array_merge($engagement, [
                    'paid' => true,
                    'paid_at' => now(),
                    'payment_transaction_id' => $this->transactionId ?? null,
                ]));

                Log::info('Engagement acceptance persisted after payment', [
                    'engagement_KEY' => $engagement['project_engagement_key'],
                    'client_key' => $engagement['client_key'],
                ]);

                // 2. Update PracticeCS: Change engagement type from EXPANSION to target type
                // This converts the "proposed" engagement into an active engagement
                // Year-aware: uses project description to determine tax year for type resolution
                $result = $engagementService->acceptEngagement(
                    (int) $engagement['project_engagement_key'],
                    $staffKey,
                    $engagement['project_description'] ?? null
                );

                // 3. Update local record with sync status
                if ($result['success']) {
                    $acceptance->update([
                        'practicecs_updated' => true,
                        'new_engagement_type_key' => $result['new_type_KEY'] ?? null,
                        'practicecs_updated_at' => now(),
                    ]);

                    Log::info('PracticeCS engagement type updated', [
                        'engagement_KEY' => $engagement['project_engagement_key'],
                        'new_type_KEY' => $result['new_type_KEY'] ?? null,
                    ]);
                } else {
                    // Log error but don't fail the payment - local record is saved
                    $acceptance->update([
                        'practicecs_updated' => false,
                        'practicecs_error' => $result['error'] ?? 'Unknown error',
                    ]);

                    Log::error('Failed to update PracticeCS engagement type', [
                        'engagement_KEY' => $engagement['project_engagement_key'],
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                }
            } else {
                // Already exists - update with payment info if not already paid
                if (! $existing->paid) {
                    $existing->update([
                        'paid' => true,
                        'paid_at' => now(),
                        'payment_transaction_id' => $this->transactionId ?? null,
                    ]);
                }
            }
        }

        // Clear the queue
        $this->engagementsToPersist = [];
    }

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
