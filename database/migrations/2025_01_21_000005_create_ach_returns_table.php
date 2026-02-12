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
        Schema::create('ach_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ach_entry_id')->nullable()->constrained('ach_entries')->nullOnDelete();

            // Return identification
            $table->string('trace_number', 15);
            $table->string('original_trace_number', 15)->nullable();
            $table->date('return_date');

            // Return details
            $table->enum('return_type', ['return', 'noc', 'dishonored', 'contested']);
            $table->string('return_code', 4); // R01, R02, C01, etc.
            $table->string('return_reason_code', 3)->nullable();
            $table->text('return_description')->nullable();

            // Original transaction info
            $table->string('original_receiving_dfi', 8)->nullable();
            $table->unsignedBigInteger('original_amount')->nullable(); // In cents
            $table->string('individual_name', 22)->nullable();

            // NOC-specific fields
            $table->text('corrected_data')->nullable();
            $table->string('addenda_information')->nullable();

            // Processing status
            $table->enum('status', [
                'received',     // Just received from Kotapay
                'processing',   // Being processed
                'applied',      // Applied to original entry
                'reviewed',     // Manually reviewed
                'resolved',      // Issue resolved
            ])->default('received');

            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Raw data
            $table->text('raw_record')->nullable();
            $table->string('kotapay_reference')->nullable();

            $table->timestamps();

            $table->index(['return_type', 'status']);
            $table->index('return_date');
            $table->index('trace_number');
            $table->index('return_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ach_returns');
    }
};
