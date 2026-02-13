<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing database indexes identified during architecture review.
 *
 * - payments.vendor_transaction_id: used for Kotapay status lookups
 * - payments.recurring_payment_id: FK lookup when loading recurring payment history
 * - recurring_payments.customer_payment_method_id: new FK from migration 200001
 * - payment_plans.customer_payment_method_id: new FK from migration 200001
 *
 * Idempotent: checks for existing indexes before adding (safe to re-run).
 */
return new class extends Migration
{
    public function up(): void
    {
        // payments.vendor_transaction_id — used by Kotapay settlement status checks
        if (Schema::hasColumn('payments', 'vendor_transaction_id') && ! $this->indexExists('payments', 'payments_vendor_transaction_id_index')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index('vendor_transaction_id', 'payments_vendor_transaction_id_index');
            });
        }

        // payments.recurring_payment_id — FK lookup (constrained FK was added but
        // SQLite doesn't auto-create indexes for FK constraints)
        if (! $this->indexExists('payments', 'payments_recurring_payment_id_index')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index('recurring_payment_id', 'payments_recurring_payment_id_index');
            });
        }

        // recurring_payments.customer_payment_method_id — new FK column
        if (Schema::hasColumn('recurring_payments', 'customer_payment_method_id')
            && ! $this->indexExists('recurring_payments', 'rp_customer_payment_method_id_index')) {
            Schema::table('recurring_payments', function (Blueprint $table) {
                $table->index('customer_payment_method_id', 'rp_customer_payment_method_id_index');
            });
        }

        // payment_plans.customer_payment_method_id — new FK column
        if (Schema::hasColumn('payment_plans', 'customer_payment_method_id')
            && ! $this->indexExists('payment_plans', 'pp_customer_payment_method_id_index')) {
            Schema::table('payment_plans', function (Blueprint $table) {
                $table->index('customer_payment_method_id', 'pp_customer_payment_method_id_index');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('payment_plans', 'pp_customer_payment_method_id_index')) {
            Schema::table('payment_plans', function (Blueprint $table) {
                $table->dropIndex('pp_customer_payment_method_id_index');
            });
        }

        if ($this->indexExists('recurring_payments', 'rp_customer_payment_method_id_index')) {
            Schema::table('recurring_payments', function (Blueprint $table) {
                $table->dropIndex('rp_customer_payment_method_id_index');
            });
        }

        if ($this->indexExists('payments', 'payments_recurring_payment_id_index')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_recurring_payment_id_index');
            });
        }

        if ($this->indexExists('payments', 'payments_vendor_transaction_id_index')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_vendor_transaction_id_index');
            });
        }
    }

    /**
     * Check if an index exists on a table (SQLite-compatible).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?", [$table, $indexName]);

        return count($indexes) > 0;
    }
};
