<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Update client_key columns to support alphanumeric values and add max_occurrences.
 *
 * This migration:
 * 1. Changes client_key from integer/bigInteger to string(50) in:
 *    - recurring_payments table
 *    - customers table
 *    - payments table
 * 2. Adds max_occurrences column to recurring_payments for "After X occurrences" support
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update recurring_payments table
        Schema::table('recurring_payments', function (Blueprint $table) {
            // Change client_key from unsignedBigInteger to string
            $table->string('client_key', 50)->change();

            // Add max_occurrences column for "After X occurrences" support
            $table->unsignedInteger('max_occurrences')->nullable()->after('end_date');
        });

        // Update customers table
        Schema::table('customers', function (Blueprint $table) {
            // Change client_key from integer to string
            $table->string('client_key', 50)->nullable()->change();
        });

        // Update payments table
        Schema::table('payments', function (Blueprint $table) {
            // Change client_key from unsignedBigInteger to string
            $table->string('client_key', 50)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert recurring_payments table
        Schema::table('recurring_payments', function (Blueprint $table) {
            $table->dropColumn('max_occurrences');
            $table->unsignedBigInteger('client_key')->change();
        });

        // Revert customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->integer('client_key')->nullable()->change();
        });

        // Revert payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('client_key')->change();
        });
    }
};
