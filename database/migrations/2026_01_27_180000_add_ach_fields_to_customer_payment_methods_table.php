<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds ACH-specific fields to customer_payment_methods table:
     * - is_business: Whether the bank account is a business account (for CCD vs PPD batching)
     * - account_type: The type of bank account (checking or savings)
     */
    public function up(): void
    {
        Schema::table('customer_payment_methods', function (Blueprint $table) {
            $table->boolean('is_business')->default(false)->after('bank_name');
            $table->string('account_type', 10)->nullable()->after('is_business'); // 'checking' or 'savings'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_payment_methods', function (Blueprint $table) {
            $table->dropColumn(['is_business', 'account_type']);
        });
    }
};
