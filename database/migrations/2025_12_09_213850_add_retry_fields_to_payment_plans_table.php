<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            // Retry tracking fields
            $table->date('next_retry_date')->nullable()->after('next_payment_date')->index();
            $table->timestamp('last_attempt_at')->nullable()->after('next_retry_date');
            $table->date('original_due_date')->nullable()->after('last_attempt_at');
            
            // Add index for efficient querying of plans needing processing
            $table->index(['status', 'next_retry_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_retry_date']);
            $table->dropColumn(['next_retry_date', 'last_attempt_at', 'original_due_date']);
        });
    }
};
