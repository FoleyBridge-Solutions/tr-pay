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
        Schema::create('ach_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 7)->unique(); // 7-digit batch number
            $table->foreignId('ach_file_id')->nullable()->constrained('ach_files')->nullOnDelete();

            // Batch header info
            $table->string('company_name', 16);
            $table->string('company_id', 10); // Company identification (tax ID)
            $table->string('sec_code', 3)->default('WEB'); // WEB, PPD, CCD, etc.
            $table->string('company_entry_description', 10)->default('PAYMENT');
            $table->string('company_descriptive_date', 6)->nullable();
            $table->date('effective_entry_date');

            // Batch totals (calculated)
            $table->unsignedInteger('entry_count')->default(0);
            $table->unsignedBigInteger('total_debit_amount')->default(0); // In cents
            $table->unsignedBigInteger('total_credit_amount')->default(0); // In cents
            $table->string('entry_hash', 10)->nullable(); // Sum of routing numbers

            // Status tracking
            $table->enum('status', [
                'pending',      // Collecting entries
                'ready',        // Ready for file generation
                'generated',    // NACHA file generated
                'submitted',    // Sent to Kotapay
                'accepted',     // Kotapay accepted
                'rejected',     // Kotapay rejected
                'settled',      // Funds settled
                'cancelled',     // Cancelled
            ])->default('pending');

            $table->text('rejection_reason')->nullable();
            $table->string('kotapay_reference')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'effective_entry_date']);
            $table->index('effective_entry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ach_batches');
    }
};
