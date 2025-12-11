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
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            
            // Customer reference
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('client_key')->index(); // Reference to PracticeCS client
            
            // Plan identification
            $table->string('plan_id')->unique(); // Unique plan identifier (e.g., plan_xxxxx)
            
            // Financial details
            $table->decimal('invoice_amount', 12, 2); // Original invoice amount
            $table->decimal('plan_fee', 12, 2); // Payment plan fee ($150, $300, or $450)
            $table->decimal('total_amount', 12, 2); // Invoice + fee
            $table->decimal('monthly_payment', 12, 2); // Calculated monthly payment
            $table->unsignedInteger('duration_months'); // 3, 6, or 9
            
            // Payment method
            $table->string('payment_method_token'); // Saved payment method token for charging
            $table->string('payment_method_type'); // 'card' or 'ach'
            $table->string('payment_method_last_four')->nullable(); // Last 4 digits for display
            
            // Status tracking
            $table->string('status')->default('active'); // active, completed, cancelled, past_due, failed
            $table->unsignedInteger('payments_completed')->default(0);
            $table->unsignedInteger('payments_failed')->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_remaining', 12, 2);
            
            // Dates
            $table->date('start_date');
            $table->date('next_payment_date')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            
            // Invoice references (JSON array of invoice numbers/keys)
            $table->json('invoice_references')->nullable();
            
            // Additional metadata
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes for querying
            $table->index(['status', 'next_payment_date']);
            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
