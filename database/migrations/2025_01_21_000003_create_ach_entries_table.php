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
        Schema::create('ach_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ach_batch_id')->constrained('ach_batches')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // Entry detail record fields
            $table->string('transaction_code', 2); // 27=debit checking, 37=debit savings, 22=credit checking, 32=credit savings
            $table->string('receiving_dfi_id', 8); // First 8 digits of routing number
            $table->string('check_digit', 1); // 9th digit of routing
            $table->text('dfi_account_number_encrypted'); // Encrypted bank account number
            $table->unsignedBigInteger('amount'); // In cents
            $table->string('individual_id', 15)->nullable(); // Customer ID/reference
            $table->string('individual_name', 22);
            $table->string('discretionary_data', 2)->nullable();
            $table->string('trace_number', 15)->unique();

            // Optional addenda
            $table->boolean('has_addenda')->default(false);
            $table->text('addenda_info')->nullable();

            // Original payment data (for reference)
            $table->string('client_key')->nullable();
            $table->string('routing_number_last_four', 4)->nullable();
            $table->string('account_number_last_four', 4)->nullable();
            $table->enum('account_type', ['checking', 'savings'])->default('checking');

            // Status tracking
            $table->enum('status', [
                'pending',      // In batch, not submitted
                'submitted',    // Sent to Kotapay
                'accepted',     // ACH network accepted
                'settled',      // Funds transferred
                'returned',     // Return received
                'corrected',    // NOC received, corrected
                'cancelled',     // Cancelled before submission
            ])->default('pending');

            $table->string('return_code', 4)->nullable(); // R01, R02, etc.
            $table->text('return_reason')->nullable();
            $table->string('noc_code', 4)->nullable(); // C01, C02, etc.
            $table->text('noc_data')->nullable(); // Corrected data from NOC

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            $table->timestamps();

            $table->index(['ach_batch_id', 'status']);
            $table->index('payment_id');
            $table->index('client_key');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ach_entries');
    }
};
