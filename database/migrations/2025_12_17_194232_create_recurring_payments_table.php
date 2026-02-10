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
        Schema::create('recurring_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('client_key');
            $table->string('client_name');
            $table->string('frequency'); // weekly, biweekly, monthly, quarterly, yearly
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->string('payment_method_type'); // card, ach
            $table->text('payment_method_token'); // Encrypted token
            $table->string('payment_method_last_four')->nullable();
            $table->string('status')->default('active'); // active, paused, cancelled, completed
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_payment_date')->nullable();
            $table->unsignedInteger('payments_completed')->default(0);
            $table->unsignedInteger('payments_failed')->default(0);
            $table->decimal('total_collected', 12, 2)->default(0);
            $table->timestamp('last_payment_at')->nullable();
            $table->string('import_batch_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_payment_date']);
            $table->index('client_key');
        });

        // Add recurring_payment_id to payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('recurring_payment_id')->nullable()->after('payment_plan_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['recurring_payment_id']);
            $table->dropColumn('recurring_payment_id');
        });

        Schema::dropIfExists('recurring_payments');
    }
};
