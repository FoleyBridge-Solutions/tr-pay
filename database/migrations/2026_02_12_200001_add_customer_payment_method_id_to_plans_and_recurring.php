<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Add customer_payment_method_id FK to payment_plans and recurring_payments.
 *
 * Replaces the denormalized payment_method_token string matching with a proper
 * foreign key to customer_payment_methods. The old token columns are kept for
 * backwards compatibility but the FK is the canonical reference going forward.
 *
 * Backfill strategy for recurring_payments (197 rows):
 * - 146 rows: payment_method_token matches customer_payment_methods.mpc_token directly
 * - 12 rows: payment_method_token is Laravel-encrypted JSON (legacy import fallback) — decrypt to find raw data, match via last_four + customer_id
 * - 39 rows: payment_method_token is NULL (status=pending) — leave customer_payment_method_id as NULL
 *
 * payment_plans has 0 rows so no backfill needed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add the FK column to payment_plans
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_payment_method_id')
                ->nullable()
                ->after('payment_method_token');
        });

        // Add the FK column to recurring_payments
        Schema::table('recurring_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_payment_method_id')
                ->nullable()
                ->after('payment_method_token');
        });

        // Backfill recurring_payments: match by token directly
        DB::statement("
            UPDATE recurring_payments
            SET customer_payment_method_id = (
                SELECT cpm.id
                FROM customer_payment_methods cpm
                WHERE cpm.mpc_token = recurring_payments.payment_method_token
                  AND cpm.customer_id = recurring_payments.customer_id
                LIMIT 1
            )
            WHERE payment_method_token IS NOT NULL
              AND payment_method_token != ''
              AND customer_id IS NOT NULL
        ");

        // Backfill encrypted tokens (12 rows) — these have payment_method_token
        // that didn't match above because they're Laravel-encrypted JSON blobs.
        // Try to match by customer_id + last_four + type instead.
        $unmatchedWithTokens = DB::table('recurring_payments')
            ->whereNull('customer_payment_method_id')
            ->whereNotNull('payment_method_token')
            ->where('payment_method_token', '!=', '')
            ->whereNotNull('customer_id')
            ->get();

        foreach ($unmatchedWithTokens as $row) {
            // Try to decrypt the token to extract payment data
            try {
                $decrypted = decrypt($row->payment_method_token);
                $data = json_decode($decrypted, true);

                if (! is_array($data)) {
                    continue;
                }

                // Extract last four and type from the decrypted data
                $type = $data['type'] ?? null;
                $lastFour = null;

                if ($type === 'card' && ! empty($data['number'])) {
                    $lastFour = substr(preg_replace('/\D/', '', $data['number']), -4);
                } elseif ($type === 'ach' && ! empty($data['account'])) {
                    $lastFour = substr(preg_replace('/\D/', '', $data['account']), -4);
                }

                if (! $lastFour || ! $type) {
                    continue;
                }

                // Find matching saved payment method
                $savedMethod = DB::table('customer_payment_methods')
                    ->where('customer_id', $row->customer_id)
                    ->where('type', $type)
                    ->where('last_four', $lastFour)
                    ->first();

                if ($savedMethod) {
                    DB::table('recurring_payments')
                        ->where('id', $row->id)
                        ->update([
                            'customer_payment_method_id' => $savedMethod->id,
                            // Also fix the token to the proper mpc_token
                            'payment_method_token' => $savedMethod->mpc_token,
                        ]);

                    Log::info('Backfill: migrated encrypted token to FK', [
                        'recurring_payment_id' => $row->id,
                        'customer_payment_method_id' => $savedMethod->id,
                    ]);
                } else {
                    Log::warning('Backfill: no matching saved method for encrypted token', [
                        'recurring_payment_id' => $row->id,
                        'customer_id' => $row->customer_id,
                        'type' => $type,
                        'last_four' => $lastFour,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Backfill: could not decrypt token', [
                    'recurring_payment_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Log backfill results
        $matched = DB::table('recurring_payments')
            ->whereNotNull('customer_payment_method_id')
            ->count();
        $total = DB::table('recurring_payments')->count();
        Log::info("Backfill complete: {$matched}/{$total} recurring_payments linked to customer_payment_methods");
    }

    public function down(): void
    {
        Schema::table('recurring_payments', function (Blueprint $table) {
            $table->dropColumn('customer_payment_method_id');
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropColumn('customer_payment_method_id');
        });
    }
};
