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
        Schema::create('admin_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50); // created, updated, deleted, cancelled, etc.
            $table->string('model_type'); // Payment, PaymentPlan, RecurringPayment, User
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('description')->nullable();
            $table->json('old_values')->nullable(); // Previous state (for updates)
            $table->json('new_values')->nullable(); // New state (for creates/updates)
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['model_type', 'model_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_activities');
    }
};
