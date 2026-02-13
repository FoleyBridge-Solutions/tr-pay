<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add index on customer_payment_methods.mpc_token.
 *
 * This is the join key used by payment_plans.payment_method_token and
 * recurring_payments.payment_method_token but had no index, causing
 * full table scans on every token lookup.
 *
 * Must run before the backfill migration that populates customer_payment_method_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payment_methods', function (Blueprint $table) {
            $table->index('mpc_token', 'cpm_mpc_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payment_methods', function (Blueprint $table) {
            $table->dropIndex('cpm_mpc_token_index');
        });
    }
};
