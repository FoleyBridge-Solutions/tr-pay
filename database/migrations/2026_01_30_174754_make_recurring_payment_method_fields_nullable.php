<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make payment method fields nullable in recurring_payments table.
 *
 * This allows importing recurring payments without payment details,
 * which can be added later by an admin. Records without payment info
 * are saved with status = 'pending'.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recurring_payments', function (Blueprint $table) {
            $table->string('payment_method_type')->nullable()->change();
            $table->text('payment_method_token')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_payments', function (Blueprint $table) {
            // Note: This will fail if there are null values in the table
            $table->string('payment_method_type')->nullable(false)->change();
            $table->text('payment_method_token')->nullable(false)->change();
        });
    }
};
