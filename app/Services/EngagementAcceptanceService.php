<?php

// app/Services/EngagementAcceptanceService.php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EngagementAcceptanceService
 *
 * Handles the acceptance of EXPANSION engagements in PracticeCS.
 * When a client pays for a proposed project, this service updates
 * the engagement type from EXPANSION to the appropriate target type.
 * 
 * Business Logic:
 * - EXPANSION (type 3) engagements are "proposed work" that clients must accept
 * - Each EXPANSION engagement has a template (e.g., EXPTAX, EXPREP) that indicates
 *   what type of engagement it should become when accepted
 * - Upon payment, the engagement_type_KEY is updated to convert the proposal
 *   into an active engagement
 * 
 * Example:
 * - Client has engagement with template "EXPTAX" (Proposed Tax Expansion)
 * - Client accepts and pays via portal
 * - Engagement type changes from EXPANSION (3) to TAXDELIVERY (11)
 * - The work is now authorized and can proceed
 */
class EngagementAcceptanceService
{
    /**
     * Mapping from template IDs to target engagement_type_KEY
     * 
     * When an EXPANSION engagement is accepted, we look up its template ID
     * and use this map to determine the new engagement type.
     * 
     * This includes:
     * - EXP* templates (e.g., EXPTAX -> TAXDELIVERY)
     * - Non-EXP templates used with EXPANSION type (e.g., TAXFEEREQ -> TAXFEEREQ)
     */
    private const TEMPLATE_TO_TYPE_MAP = [
        // EXP* templates -> their target types
        'EXPADVISORY' => 21, // -> ADVISORY (Advisory & Consulting)
        'EXPAUDIT'    => 25, // -> AUDIT (Audit Engagement)
        'EXPAYROLL'   => 13, // -> PAYROLL (Payroll Processing & Payroll Returns Preparation)
        'EXPBOOK'     => 12, // -> BOOKKEEPING (Bookkeeping & Sales Tax Work)
        'EXPCONSULT'  => 22, // -> CONSULTING (Consulting)
        'EXPEXAM'     => 24, // -> EXAM (Examination Representation)
        'EXPFIN'      => 14, // -> FINANCIALS (Reviews, Compilations & Preparations)
        'EXPPLANNING' => 23, // -> PLANNING (Tax Planning)
        'EXPREP'      => 4,  // -> REP (Tax Debt Representation)
        'EXPSTARTUP'  => 5,  // -> STARTUP (Sales & Startup Work)
        'EXPTAX'      => 11, // -> TAXDELIVERY (Tax Work Billed @ Delivery)
        'EXPVAL'      => 15, // -> VALUATION (Valuation)
        
        // Non-EXP templates that may be used with EXPANSION type
        // These convert to their matching type (template ID = type ID)
        'TAXFEEREQ'   => 16, // -> TAXFEEREQ (Tax Work Billed Using Fee Requests)
        'GAMEPLAN'    => 2,  // -> GAMEPLAN (Troubleshooting Gameplan)
    ];

    /**
     * The engagement_type_KEY for EXPANSION type
     */
    private const EXPANSION_TYPE_KEY = 3;

    /**
     * Accept an engagement by updating its type from EXPANSION to the target type
     * 
     * This should be called AFTER payment is successfully processed.
     * 
     * @param int $engagementKey The engagement_KEY to accept
     * @param int $staffKey The staff_KEY to record as the updater (for audit)
     * @return array ['success' => bool, 'new_type_KEY' => int|null, 'error' => string|null]
     */
    public function acceptEngagement(int $engagementKey, int $staffKey): array
    {
        if (!config('practicecs.payment_integration.enabled')) {
            Log::warning('EngagementAcceptance: PracticeCS integration disabled, skipping', [
                'engagement_KEY' => $engagementKey,
            ]);
            return [
                'success' => false,
                'error' => 'PracticeCS integration is disabled',
            ];
        }

        $connection = config('practicecs.payment_integration.connection', 'sqlsrv');

        try {
            // 1. Get the engagement details
            $engagement = DB::connection($connection)->selectOne("
                SELECT 
                    E.engagement_KEY,
                    E.engagement_type_KEY,
                    E.engagement_template_KEY,
                    E.update__staff_KEY,
                    ET.engagement_type_id AS current_type_id,
                    TM.engagement_template_id AS template_id
                FROM Engagement E
                JOIN Engagement_Type ET ON E.engagement_type_KEY = ET.engagement_type_KEY
                JOIN Engagement_Template TM ON E.engagement_template_KEY = TM.engagement_template_KEY
                WHERE E.engagement_KEY = ?
            ", [$engagementKey]);

            if (!$engagement) {
                return [
                    'success' => false,
                    'error' => "Engagement not found: {$engagementKey}",
                ];
            }

            // 2. Verify it's an EXPANSION type
            if ($engagement->engagement_type_KEY !== self::EXPANSION_TYPE_KEY) {
                Log::info('EngagementAcceptance: Engagement is not EXPANSION type, skipping', [
                    'engagement_KEY' => $engagementKey,
                    'current_type' => $engagement->current_type_id,
                ]);
                return [
                    'success' => true, // Not an error, just nothing to do
                    'message' => 'Engagement is not an EXPANSION type',
                ];
            }

            // 3. Look up the target type from the template
            $templateId = $engagement->template_id;
            if (!isset(self::TEMPLATE_TO_TYPE_MAP[$templateId])) {
                Log::error('EngagementAcceptance: Unknown template ID', [
                    'engagement_KEY' => $engagementKey,
                    'template_id' => $templateId,
                ]);
                return [
                    'success' => false,
                    'error' => "Unknown expansion template: {$templateId}",
                ];
            }

            $targetTypeKey = self::TEMPLATE_TO_TYPE_MAP[$templateId];

            // 4. Begin transaction
            DB::connection($connection)->beginTransaction();

            // 5. Create changeset entry for audit trail
            $changesetKey = $this->createChangeset($connection);

            // 6. Update the engagement
            DB::connection($connection)->update("
                UPDATE Engagement
                SET 
                    engagement_type_KEY = ?,
                    update__staff_KEY = ?,
                    update__changeset_KEY = ?
                WHERE engagement_KEY = ?
            ", [
                $targetTypeKey,
                $staffKey,
                $changesetKey,
                $engagementKey,
            ]);

            // 7. Close the changeset
            $this->closeChangeset($connection, $changesetKey);

            // 8. Commit
            DB::connection($connection)->commit();

            Log::info('EngagementAcceptance: Engagement type updated successfully', [
                'engagement_KEY' => $engagementKey,
                'from_type' => self::EXPANSION_TYPE_KEY,
                'to_type' => $targetTypeKey,
                'template_id' => $templateId,
                'changeset_KEY' => $changesetKey,
            ]);

            return [
                'success' => true,
                'new_type_KEY' => $targetTypeKey,
                'changeset_KEY' => $changesetKey,
            ];

        } catch (\Exception $e) {
            if (DB::connection($connection)->transactionLevel() > 0) {
                DB::connection($connection)->rollBack();
            }

            Log::error('EngagementAcceptance: Failed to update engagement', [
                'engagement_KEY' => $engagementKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Accept multiple engagements (batch operation)
     * 
     * @param array $engagementKeys Array of engagement_KEYs to accept
     * @param int $staffKey The staff_KEY to record as the updater
     * @return array ['success' => bool, 'results' => array, 'error' => string|null]
     */
    public function acceptEngagements(array $engagementKeys, int $staffKey): array
    {
        $results = [];
        $allSuccess = true;

        foreach ($engagementKeys as $engagementKey) {
            $result = $this->acceptEngagement($engagementKey, $staffKey);
            $results[$engagementKey] = $result;
            
            if (!$result['success'] && !isset($result['message'])) {
                $allSuccess = false;
            }
        }

        return [
            'success' => $allSuccess,
            'results' => $results,
        ];
    }

    /**
     * Create a new changeset entry for audit trail
     * 
     * PracticeCS requires changeset entries to track all modifications.
     * This creates a changeset that identifies the change as coming from
     * the payment portal.
     * 
     * @param string $connection Database connection name
     * @return int The new changeset_KEY
     */
    private function createChangeset(string $connection): int
    {
        // Generate next changeset KEY with lock
        $nextKey = DB::connection($connection)->selectOne(
            "SELECT ISNULL(MAX(changeset_KEY), 0) + 1 AS next_key FROM Changeset WITH (TABLOCKX)"
        )->next_key;

        // Insert changeset
        DB::connection($connection)->insert("
            INSERT INTO Changeset (
                changeset_KEY,
                begin_date_utc,
                program_name,
                user_name,
                host_name,
                resolved_end_date_utc
            )
            VALUES (?, GETUTCDATE(), ?, ?, ?, GETUTCDATE())
        ", [
            $nextKey,
            'TR-Pay Payment Portal',
            'PaymentPortal',
            config('app.url', 'tr-pay'),
        ]);

        Log::debug('EngagementAcceptance: Changeset created', [
            'changeset_KEY' => $nextKey,
        ]);

        return $nextKey;
    }

    /**
     * Close a changeset by setting its end_date_utc
     * 
     * @param string $connection Database connection name
     * @param int $changesetKey The changeset_KEY to close
     */
    private function closeChangeset(string $connection, int $changesetKey): void
    {
        DB::connection($connection)->update("
            UPDATE Changeset
            SET 
                end_date_utc = GETUTCDATE(),
                resolved_end_date_utc = GETUTCDATE()
            WHERE changeset_KEY = ?
        ", [$changesetKey]);
    }

    /**
     * Get the target engagement type for an EXP template
     * 
     * @param string $templateId The engagement_template_id (e.g., 'EXPTAX')
     * @return int|null The target engagement_type_KEY, or null if not found
     */
    public function getTargetTypeKey(string $templateId): ?int
    {
        return self::TEMPLATE_TO_TYPE_MAP[$templateId] ?? null;
    }

    /**
     * Check if a template ID is an expansion template
     * 
     * @param string $templateId The engagement_template_id
     * @return bool True if this is an EXP* template
     */
    public function isExpansionTemplate(string $templateId): bool
    {
        return isset(self::TEMPLATE_TO_TYPE_MAP[$templateId]);
    }
}
