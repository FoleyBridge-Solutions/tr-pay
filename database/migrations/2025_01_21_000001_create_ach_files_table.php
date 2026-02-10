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
        Schema::create('ach_files', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->unique();
            $table->string('file_id_modifier', 1)->default('A'); // A-Z, 0-9

            // File header info
            $table->string('immediate_destination', 10); // Kotapay routing
            $table->string('immediate_origin', 10); // Your company ID
            $table->string('immediate_destination_name', 23);
            $table->string('immediate_origin_name', 23);
            $table->date('file_creation_date');
            $table->string('file_creation_time', 4)->nullable(); // HHMM

            // File totals
            $table->unsignedInteger('batch_count')->default(0);
            $table->unsignedInteger('block_count')->default(0);
            $table->unsignedInteger('entry_addenda_count')->default(0);
            $table->string('entry_hash', 10)->nullable();
            $table->unsignedBigInteger('total_debit_amount')->default(0); // In cents
            $table->unsignedBigInteger('total_credit_amount')->default(0); // In cents

            // File content
            $table->longText('file_contents')->nullable();
            $table->string('file_hash', 64)->nullable(); // SHA-256 hash

            // Status
            $table->enum('status', [
                'pending',      // Being created
                'generated',    // File generated, ready to submit
                'submitted',    // Sent to Kotapay
                'accepted',     // Kotapay accepted
                'rejected',     // Kotapay rejected
                'processing',   // Being processed by ACH network
                'completed',    // All entries settled
                'failed',        // File failed
            ])->default('pending');

            $table->text('rejection_reason')->nullable();
            $table->string('kotapay_reference')->nullable();
            $table->string('kotapay_filename')->nullable(); // Filename on Kotapay SFTP

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('file_creation_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ach_files');
    }
};
