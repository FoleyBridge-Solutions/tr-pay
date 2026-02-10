<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the customer_payment_methods table for PCI-compliant storage
     * of tokenized payment methods linked to customers.
     *
     * SECURITY NOTE: This table stores ONLY tokenized references to payment methods.
     * Actual card numbers and sensitive data are stored by MiPaymentChoice gateway.
     * Only last_four digits are stored for display purposes.
     */
    public function up(): void
    {
        Schema::create('customer_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('mpc_token');                                    // MiPaymentChoice reusable token
            $table->string('type');                                         // 'card' or 'ach'
            $table->string('last_four', 4);                                 // Last 4 digits for display
            $table->string('brand')->nullable();                            // Visa, Mastercard, Amex, Discover, etc.
            $table->unsignedTinyInteger('exp_month')->nullable();           // 1-12 (cards only)
            $table->unsignedSmallInteger('exp_year')->nullable();           // e.g., 2027 (cards only)
            $table->string('bank_name')->nullable();                        // Bank name for ACH
            $table->string('nickname')->nullable();                         // User-friendly name (e.g., "Personal Card")
            $table->boolean('is_default')->default(false);
            $table->timestamp('expiration_notified_at')->nullable();        // Track when expiration notice was sent
            $table->timestamps();

            // Indexes for common queries
            $table->index(['customer_id', 'is_default']);
            $table->index(['customer_id', 'type']);
            $table->index(['exp_year', 'exp_month']);                       // For expiration cleanup queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_payment_methods');
    }
};
