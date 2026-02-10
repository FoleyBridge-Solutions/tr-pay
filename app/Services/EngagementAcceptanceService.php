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
 * Year-Based Type Resolution:
 * - Some templates (EXPADVISORY, EXPAYROLL, EXPBOOK, EXPSALES) map to year-specific
 *   engagement types (e.g., 2026ADVISOR, 2027PAYROLL)
 * - The tax year is determined from the first word of the project description
 * - Years >= 2026 use dynamic DB lookup for {YEAR}{SUFFIX} type IDs
 * - Years < 2026 (or unparseable) fall back to legacy non-year-based types
 * - All other templates use static mappings that don't change by year
 */
class EngagementAcceptanceService
{
    /**
     * Static (non-year-based) template-to-type mapping.
     * These templates always map to the same engagement_type_KEY regardless of year.
     *
     * @var array<string, int>
     */
    private const STATIC_TEMPLATE_MAP = [
        'EXPAUDIT' => 25, // -> AUDIT (Audit Engagement)
        'EXPCONSULT' => 22, // -> CONSULTING (Consulting)
        'EXPEXAM' => 24, // -> EXAM (Examination Representation)
        'EXPFIN' => 14, // -> FINANCIALS (Reviews, Compilations & Preparations)
        'EXPPLANNING' => 23, // -> PLANNING (Tax Planning)
        'EXPREP' => 4,  // -> REP (Tax Debt Representation)
        'EXPSTARTUP' => 5,  // -> STARTUP (Sales & Startup Work)
        'EXPTAX' => 16, // -> TAXFEEREQ (Tax Work Billed Using Fee Requests)
        'EXPVAL' => 15, // -> VALUATION (Valuation)

        // Non-EXP templates that may be used with EXPANSION type
        'TAXFEEREQ' => 16, // -> TAXFEEREQ (Tax Work Billed Using Fee Requests)
        'GAMEPLAN' => 2,  // -> GAMEPLAN (Troubleshooting Gameplan)
    ];

    /**
     * Year-based templates mapped to their Engagement_Type ID suffix.
     *
     * For years >= YEAR_BASED_THRESHOLD, we build the engagement_type_id
     * as "{YEAR}{SUFFIX}" and look it up dynamically from the Engagement_Type table.
     *
     * e.g., EXPADVISORY with year 2026 -> look up "2026ADVISOR"
     *       EXPBOOK with year 2027     -> look up "2027BOOKS"
     *
     * @var array<string, string>
     */
    private const YEAR_BASED_TEMPLATE_SUFFIX = [
        'EXPADVISORY' => 'ADVISOR',
        'EXPAYROLL' => 'PAYROLL',
        'EXPBOOK' => 'BOOKS',
        'EXPSALES' => 'SALES',
    ];

    /**
     * Legacy (pre-2026) type mapping for year-based templates.
     * Used when the project's tax year is before YEAR_BASED_THRESHOLD
     * or when the year cannot be determined from the project description.
     *
     * @var array<string, int>
     */
    private const LEGACY_YEAR_TYPE_MAP = [
        'EXPADVISORY' => 21, // -> ADVISORY
        'EXPAYROLL' => 13, // -> PAYROLL
        'EXPBOOK' => 12, // -> BOOKKEEPING
        'EXPSALES' => 12, // -> BOOKKEEPING (sales tax was combined with bookkeeping pre-2026)
    ];

    /**
     * Year threshold: years >= this value use dynamic year-based type lookup.
     * Years below this threshold use LEGACY_YEAR_TYPE_MAP.
     */
    private const YEAR_BASED_THRESHOLD = 2026;

    /**
     * The engagement_type_KEY for EXPANSION type
     */
    private const EXPANSION_TYPE_KEY = 3;

    /**
     * Accept an engagement by updating its type from EXPANSION to the target type
     *
     * This should be called AFTER payment is successfully processed.
     *
     * @param  int  $engagementKey  The engagement_KEY to accept
     * @param  int  $staffKey  The staff_KEY to record as the updater (for audit)
     * @param  string|null  $projectDescription  The project description (P.long_description) used to extract the tax year
     * @return array{success: bool, new_type_KEY?: int, changeset_KEY?: int, message?: string, error?: string}
     */
    public function acceptEngagement(int $engagementKey, int $staffKey, ?string $projectDescription = null): array
    {
        if (! config('practicecs.payment_integration.enabled')) {
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
            // Note: LEFT JOIN on Engagement_Type because some engagements (e.g., EXPADVISORY)
            // have NULL engagement_type_KEY and need to be resolved by template instead.
            $engagement = DB::connection($connection)->selectOne('
                SELECT 
                    E.engagement_KEY,
                    E.engagement_type_KEY,
                    E.engagement_template_KEY,
                    E.update__staff_KEY,
                    ET.engagement_type_id AS current_type_id,
                    TM.engagement_template_id AS template_id
                FROM Engagement E
                LEFT JOIN Engagement_Type ET ON E.engagement_type_KEY = ET.engagement_type_KEY
                JOIN Engagement_Template TM ON E.engagement_template_KEY = TM.engagement_template_KEY
                WHERE E.engagement_KEY = ?
            ', [$engagementKey]);

            if (! $engagement) {
                return [
                    'success' => false,
                    'error' => "Engagement not found: {$engagementKey}",
                ];
            }

            // 2. Verify it's an EXPANSION type or has NULL type (e.g., EXPADVISORY template)
            // NULL type_KEY: untyped engagement created from an EXP* template, needs conversion
            // EXPANSION_TYPE_KEY (3): standard expansion engagement, needs conversion
            // Anything else: already converted to a working type, skip
            if ($engagement->engagement_type_KEY !== null
                && $engagement->engagement_type_KEY !== self::EXPANSION_TYPE_KEY) {
                Log::info('EngagementAcceptance: Engagement is not EXPANSION type, skipping', [
                    'engagement_KEY' => $engagementKey,
                    'current_type' => $engagement->current_type_id,
                ]);

                return [
                    'success' => true, // Not an error, just nothing to do
                    'message' => 'Engagement is not an EXPANSION type',
                ];
            }

            // 3. Resolve the target type from template + project description year
            $templateId = $engagement->template_id;
            $targetTypeKey = $this->resolveTargetTypeKey($templateId, $projectDescription, $connection);

            if ($targetTypeKey === null) {
                Log::error('EngagementAcceptance: Could not resolve target type', [
                    'engagement_KEY' => $engagementKey,
                    'template_id' => $templateId,
                    'project_description' => $projectDescription,
                ]);

                return [
                    'success' => false,
                    'error' => "Could not resolve target type for template: {$templateId}",
                ];
            }

            // 4. Begin transaction
            DB::connection($connection)->beginTransaction();

            // 5. Create changeset entry for audit trail
            $changesetKey = $this->createChangeset($connection);

            // 6. Update the engagement
            DB::connection($connection)->update('
                UPDATE Engagement
                SET 
                    engagement_type_KEY = ?,
                    update__staff_KEY = ?,
                    update__changeset_KEY = ?
                WHERE engagement_KEY = ?
            ', [
                $targetTypeKey,
                $staffKey,
                $changesetKey,
                $engagementKey,
            ]);

            // 7. Close the changeset
            $this->closeChangeset($connection, $changesetKey);

            // 8. Commit
            DB::connection($connection)->commit();

            $year = $this->extractYearFromDescription($projectDescription);

            Log::info('EngagementAcceptance: Engagement type updated successfully', [
                'engagement_KEY' => $engagementKey,
                'from_type' => $engagement->engagement_type_KEY ?? 'NULL',
                'to_type' => $targetTypeKey,
                'template_id' => $templateId,
                'tax_year' => $year,
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
     * Resolve the target engagement_type_KEY for a given template and project description.
     *
     * For static (non-year-based) templates, returns the fixed mapping.
     * For year-based templates:
     *   - Extracts the tax year from the first word of the project description
     *   - Year >= 2026: dynamically looks up {YEAR}{SUFFIX} in the Engagement_Type table
     *   - Year < 2026 or unparseable: falls back to the legacy type mapping
     *
     * @param  string  $templateId  The engagement_template_id (e.g., 'EXPTAX', 'EXPADVISORY')
     * @param  string|null  $projectDescription  The project description (P.long_description)
     * @param  string  $connection  The database connection name
     * @return int|null The target engagement_type_KEY, or null if template is unknown
     */
    private function resolveTargetTypeKey(string $templateId, ?string $projectDescription, string $connection): ?int
    {
        // Static (non-year-based) templates — fixed mapping
        if (isset(self::STATIC_TEMPLATE_MAP[$templateId])) {
            return self::STATIC_TEMPLATE_MAP[$templateId];
        }

        // Year-based templates — need year resolution
        if (! isset(self::YEAR_BASED_TEMPLATE_SUFFIX[$templateId])) {
            return null; // Unknown template
        }

        $year = $this->extractYearFromDescription($projectDescription);

        if ($year !== null && $year >= self::YEAR_BASED_THRESHOLD) {
            // Dynamic lookup: query Engagement_Type for {YEAR}{SUFFIX}
            $suffix = self::YEAR_BASED_TEMPLATE_SUFFIX[$templateId];
            $typeId = $year . $suffix;

            $type = DB::connection($connection)->selectOne(
                'SELECT engagement_type_KEY FROM Engagement_Type WHERE engagement_type_id = ?',
                [$typeId]
            );

            if ($type) {
                Log::debug('EngagementAcceptance: Resolved year-based type', [
                    'template_id' => $templateId,
                    'year' => $year,
                    'type_id' => $typeId,
                    'type_KEY' => $type->engagement_type_KEY,
                ]);

                return $type->engagement_type_KEY;
            }

            // Year-based type doesn't exist in DB yet — fall back to legacy
            Log::warning('EngagementAcceptance: Year-based type not found in DB, falling back to legacy', [
                'template_id' => $templateId,
                'expected_type_id' => $typeId,
                'year' => $year,
            ]);
        } else {
            Log::debug('EngagementAcceptance: Using legacy type mapping', [
                'template_id' => $templateId,
                'year' => $year,
                'reason' => $year === null ? 'year not parseable from description' : 'year below threshold',
            ]);
        }

        // Fall back to legacy (pre-2026) mapping
        return self::LEGACY_YEAR_TYPE_MAP[$templateId] ?? null;
    }

    /**
     * Extract a 4-digit tax year from the first word of a project description.
     *
     * Project descriptions are expected to start with the tax year,
     * e.g., "2026 Monthly Bookkeeping" -> 2026
     *
     * @param  string|null  $description  The project description (P.long_description)
     * @return int|null The extracted year, or null if not parseable
     */
    private function extractYearFromDescription(?string $description): ?int
    {
        if (empty($description)) {
            return null;
        }

        $firstWord = strtok(trim($description), " \t\n\r");

        if ($firstWord !== false && preg_match('/^\d{4}$/', $firstWord)) {
            return (int) $firstWord;
        }

        return null;
    }

    /**
     * Accept multiple engagements (batch operation)
     *
     * @param  array<int, int>  $engagementKeys  Array of engagement_KEYs to accept
     * @param  int  $staffKey  The staff_KEY to record as the updater
     * @param  array<int, string|null>  $projectDescriptions  Optional map of engagement_KEY => project description
     * @return array{success: bool, results: array}
     */
    public function acceptEngagements(array $engagementKeys, int $staffKey, array $projectDescriptions = []): array
    {
        $results = [];
        $allSuccess = true;

        foreach ($engagementKeys as $engagementKey) {
            $description = $projectDescriptions[$engagementKey] ?? null;
            $result = $this->acceptEngagement($engagementKey, $staffKey, $description);
            $results[$engagementKey] = $result;

            if (! $result['success'] && ! isset($result['message'])) {
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
     * @param  string  $connection  Database connection name
     * @return int The new changeset_KEY
     */
    private function createChangeset(string $connection): int
    {
        // Generate next changeset KEY with lock
        $nextKey = DB::connection($connection)->selectOne(
            'SELECT ISNULL(MAX(changeset_KEY), 0) + 1 AS next_key FROM Changeset WITH (TABLOCKX)'
        )->next_key;

        // Insert changeset
        DB::connection($connection)->insert('
            INSERT INTO Changeset (
                changeset_KEY,
                begin_date_utc,
                program_name,
                user_name,
                host_name,
                resolved_end_date_utc
            )
            VALUES (?, GETUTCDATE(), ?, ?, ?, GETUTCDATE())
        ', [
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
     * @param  string  $connection  Database connection name
     * @param  int  $changesetKey  The changeset_KEY to close
     */
    private function closeChangeset(string $connection, int $changesetKey): void
    {
        DB::connection($connection)->update('
            UPDATE Changeset
            SET 
                end_date_utc = GETUTCDATE(),
                resolved_end_date_utc = GETUTCDATE()
            WHERE changeset_KEY = ?
        ', [$changesetKey]);
    }

    /**
     * Get the target engagement type for a template, using legacy (non-year-based) resolution.
     *
     * This method does NOT perform year-based dynamic lookup.
     * For year-aware resolution, use resolveTargetTypeKey() via acceptEngagement().
     *
     * @param  string  $templateId  The engagement_template_id (e.g., 'EXPTAX')
     * @return int|null The target engagement_type_KEY, or null if not found
     */
    public function getTargetTypeKey(string $templateId): ?int
    {
        return self::STATIC_TEMPLATE_MAP[$templateId]
            ?? self::LEGACY_YEAR_TYPE_MAP[$templateId]
            ?? null;
    }

    /**
     * Check if a template ID is a known expansion template
     *
     * @param  string  $templateId  The engagement_template_id
     * @return bool True if this template has a known type mapping
     */
    public function isExpansionTemplate(string $templateId): bool
    {
        return isset(self::STATIC_TEMPLATE_MAP[$templateId])
            || isset(self::YEAR_BASED_TEMPLATE_SUFFIX[$templateId]);
    }
}
