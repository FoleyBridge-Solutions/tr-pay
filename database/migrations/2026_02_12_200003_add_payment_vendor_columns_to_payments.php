<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add payment_vendor and vendor_transaction_id to payments table.
 *
 * These columns were added manually to the live SQLite database outside of
 * migrations. This migration makes them idempotent so it works on both
 * the live DB (where they already exist) and fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'payment_vendor')) {
                $table->string('payment_vendor')->nullable()->after('metadata');
            }

            if (! Schema::hasColumn('payments', 'vendor_transaction_id')) {
                $table->string('vendor_transaction_id')->nullable()->after('payment_vendor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'vendor_transaction_id')) {
                $table->dropColumn('vendor_transaction_id');
            }

            if (Schema::hasColumn('payments', 'payment_vendor')) {
                $table->dropColumn('payment_vendor');
            }
        });
    }
};
