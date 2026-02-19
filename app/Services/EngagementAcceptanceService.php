<?php

// app/Services/EngagementAcceptanceService.php

namespace App\Services;

use App\Notifications\EngagementSyncFailed;
use FoleyBridgeSolutions\PracticeCsPI\Exceptions\PracticeCsException;
use FoleyBridgeSolutions\PracticeCsPI\Services\EngagementService;
use Illuminate\Support\Facades\Log;

/**
 * EngagementAcceptanceService
 *
 * Handles the acceptance of EXPANSION engagements in PracticeCS via the
 * practicecs-pi package API. When a client pays for a proposed project,
 * this service delegates to EngagementService to update the engagement
 * type from EXPANSION to the appropriate target type.
 *
 * Business Logic:
 * - EXPANSION (type 3) engagements are "proposed work" that clients must accept
 * - Each EXPANSION engagement has a template (e.g., EXPTAX, EXPREP) that indicates
 *   what type of engagement it should become when accepted
 * - Upon payment, the API handles the type resolution and changeset audit trail
 *
 * Year-Based Type Resolution:
 * - The API handles year-based type lookup using the project description
 * - TR-Pay passes the project description through; the API resolves the target type
 */
class EngagementAcceptanceService
{
    /**
     * The PracticeCS engagement service from the practicecs-pi package.
     */
    private EngagementService $engagementApi;

    /**
     * Create a new EngagementAcceptanceService instance.
     *
     * @param  EngagementService  $engagementApi  The PracticeCS engagement API service
     */
    public function __construct(EngagementService $engagementApi)
    {
        $this->engagementApi = $engagementApi;
    }

    /**
     * Accept an engagement by updating its type from EXPANSION to the target type
     *
     * This should be called AFTER payment is successfully processed.
     * Delegates to the practicecs-pi EngagementService API, which handles:
     * - Engagement lookup and EXPANSION type verification
     * - Target type resolution (static and year-based)
     * - Changeset creation for audit trail
     * - The actual engagement update
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

        try {
            $result = $this->engagementApi->acceptEngagement($engagementKey, $staffKey, $projectDescription);

            if ($result->success) {
                Log::info('EngagementAcceptance: Engagement type updated successfully', [
                    'engagement_KEY' => $engagementKey,
                    'new_type_KEY' => $result->newTypeKey,
                    'changeset_KEY' => $result->changesetKey,
                ]);
            } else {
                Log::info('EngagementAcceptance: API returned non-success', [
                    'engagement_KEY' => $engagementKey,
                    'message' => $result->message,
                    'error' => $result->error,
                ]);
            }

            return $result->toArray();

        } catch (PracticeCsException $e) {
            Log::error('EngagementAcceptance: API call failed', [
                'engagement_KEY' => $engagementKey,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
                'response_body' => $e->getResponseBody(),
            ]);

            try {
                AdminAlertService::notifyAll(new EngagementSyncFailed(
                    "Engagement #{$engagementKey}",
                    (string) $engagementKey,
                    $e->getMessage()
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('EngagementAcceptance: Unexpected error', [
                'engagement_KEY' => $engagementKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                AdminAlertService::notifyAll(new EngagementSyncFailed(
                    "Engagement #{$engagementKey}",
                    (string) $engagementKey,
                    $e->getMessage()
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send admin notification', ['error' => $notifyEx->getMessage()]);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Accept multiple engagements (batch operation)
     *
     * Uses the batch API endpoint for efficiency when accepting multiple
     * engagements. Falls back to individual calls if the batch API fails.
     *
     * @param  array<int, int>  $engagementKeys  Array of engagement_KEYs to accept
     * @param  int  $staffKey  The staff_KEY to record as the updater
     * @param  array<int, string|null>  $projectDescriptions  Optional map of engagement_KEY => project description
     * @return array{success: bool, results: array}
     */
    public function acceptEngagements(array $engagementKeys, int $staffKey, array $projectDescriptions = []): array
    {
        if (! config('practicecs.payment_integration.enabled')) {
            Log::warning('EngagementAcceptance: PracticeCS integration disabled, skipping batch', [
                'engagement_KEYs' => $engagementKeys,
            ]);

            $results = [];
            foreach ($engagementKeys as $key) {
                $results[$key] = [
                    'success' => false,
                    'error' => 'PracticeCS integration is disabled',
                ];
            }

            return [
                'success' => false,
                'results' => $results,
            ];
        }

        try {
            $apiResults = $this->engagementApi->acceptEngagements($engagementKeys, $staffKey, $projectDescriptions);

            $results = [];
            $allSuccess = true;

            foreach ($apiResults as $engagementKey => $result) {
                $results[$engagementKey] = $result->toArray();

                if (! $result->success && $result->message === null) {
                    $allSuccess = false;
                }
            }

            return [
                'success' => $allSuccess,
                'results' => $results,
            ];

        } catch (PracticeCsException $e) {
            Log::error('EngagementAcceptance: Batch API call failed, falling back to individual calls', [
                'engagement_KEYs' => $engagementKeys,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);

            try {
                AdminAlertService::notifyAll(new EngagementSyncFailed(
                    'Batch engagement acceptance ('.count($engagementKeys).' engagements)',
                    implode(', ', $engagementKeys),
                    $e->getMessage()
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send EngagementSyncFailed notification', ['error' => $notifyEx->getMessage()]);
            }

            // Fall back to individual calls
            return $this->acceptEngagementsIndividually($engagementKeys, $staffKey, $projectDescriptions);

        } catch (\Exception $e) {
            Log::error('EngagementAcceptance: Unexpected batch error, falling back to individual calls', [
                'engagement_KEYs' => $engagementKeys,
                'error' => $e->getMessage(),
            ]);

            try {
                AdminAlertService::notifyAll(new EngagementSyncFailed(
                    'Batch engagement acceptance ('.count($engagementKeys).' engagements)',
                    implode(', ', $engagementKeys),
                    $e->getMessage()
                ));
            } catch (\Exception $notifyEx) {
                Log::warning('Failed to send EngagementSyncFailed notification', ['error' => $notifyEx->getMessage()]);
            }

            return $this->acceptEngagementsIndividually($engagementKeys, $staffKey, $projectDescriptions);
        }
    }

    /**
     * Accept engagements individually (fallback for batch failures).
     *
     * @param  array<int, int>  $engagementKeys  Array of engagement_KEYs to accept
     * @param  int  $staffKey  The staff_KEY to record as the updater
     * @param  array<int, string|null>  $projectDescriptions  Optional map of engagement_KEY => project description
     * @return array{success: bool, results: array}
     */
    private function acceptEngagementsIndividually(array $engagementKeys, int $staffKey, array $projectDescriptions = []): array
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
     * Get the target engagement type for a template, using the API.
     *
     * Delegates to the practicecs-pi EngagementService for type resolution.
     * Falls back to local config template maps on API failure to preserve
     * the original in-memory lookup behavior for static templates.
     *
     * @param  string  $templateId  The engagement_template_id (e.g., 'EXPTAX')
     * @return int|null The target engagement_type_KEY, or null if not found
     */
    public function getTargetTypeKey(string $templateId): ?int
    {
        try {
            return $this->engagementApi->getTargetTypeKey($templateId);
        } catch (PracticeCsException $e) {
            Log::error('EngagementAcceptance: Failed to get target type key from API, using local fallback', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);

            return $this->getTargetTypeKeyFromConfig($templateId);
        } catch (\Exception $e) {
            Log::error('EngagementAcceptance: Unexpected error getting target type key, using local fallback', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);

            return $this->getTargetTypeKeyFromConfig($templateId);
        }
    }

    /**
     * Check if a template ID is a known expansion template
     *
     * Delegates to the practicecs-pi EngagementService.
     * Falls back to local config template maps on API failure to preserve
     * the original in-memory lookup behavior.
     *
     * @param  string  $templateId  The engagement_template_id
     * @return bool True if this template has a known type mapping
     */
    public function isExpansionTemplate(string $templateId): bool
    {
        try {
            return $this->engagementApi->isExpansionTemplate($templateId);
        } catch (PracticeCsException $e) {
            Log::error('EngagementAcceptance: Failed to check expansion template via API, using local fallback', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);

            return $this->isExpansionTemplateFromConfig($templateId);
        } catch (\Exception $e) {
            Log::error('EngagementAcceptance: Unexpected error checking expansion template, using local fallback', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);

            return $this->isExpansionTemplateFromConfig($templateId);
        }
    }

    /**
     * Local config fallback for getTargetTypeKey().
     *
     * Checks static_template_map and legacy_year_type_map from local config.
     * Cannot resolve year-based types (those require a DB lookup on the API side).
     *
     * @param  string  $templateId  The engagement_template_id
     * @return int|null The target engagement_type_KEY, or null if not found
     */
    private function getTargetTypeKeyFromConfig(string $templateId): ?int
    {
        $staticMap = config('practicecs.static_template_map', []);
        $legacyMap = config('practicecs.legacy_year_type_map', []);

        return $staticMap[$templateId] ?? $legacyMap[$templateId] ?? null;
    }

    /**
     * Local config fallback for isExpansionTemplate().
     *
     * @param  string  $templateId  The engagement_template_id
     * @return bool True if this template is in the static or year-based maps
     */
    private function isExpansionTemplateFromConfig(string $templateId): bool
    {
        $staticMap = config('practicecs.static_template_map', []);
        $yearBasedMap = config('practicecs.year_based_template_suffix', []);

        return isset($staticMap[$templateId]) || isset($yearBasedMap[$templateId]);
    }
}
