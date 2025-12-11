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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            
            // Customer reference
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('client_key')->index(); // Reference to PracticeCS client
            
            // Payment plan reference (nullable for one-time payments)
            $table->foreignId('payment_plan_id')->nullable()->constrained()->onDelete('set null');
            
            // Transaction details
            $table->string('transaction_id')->unique(); // MiPaymentChoice transaction ID
            $table->decimal('amount', 12, 2);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            
            // Payment method
            $table->string('payment_method'); // 'credit_card', 'ach', 'check'
            $table->string('payment_method_last_four')->nullable();
            
            // Status tracking
            $table->string('status')->default('pending'); // pending, completed, failed, refunded
            $table->string('failure_reason')->nullable();
            $table->unsignedInteger('attempt_count')->default(1);
            
            // Scheduling (for payment plan payments)
            $table->date('scheduled_date')->nullable()->index();
            $table->unsignedInteger('payment_number')->nullable(); // 1, 2, 3... for plan payments
            $table->boolean('is_automated')->default(false); // true if processed automatically
            
            // Description and metadata
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            
            // Processing timestamps
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            
            $table->timestamps();
            
            // Indexes for querying
            $table->index(['status', 'scheduled_date']);
            $table->index(['payment_plan_id', 'payment_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
