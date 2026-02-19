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
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->integer('client_key')->nullable();
            $table->string('client_id');
            $table->string('client_name');
            $table->string('email');
            $table->decimal('amount', 10, 2);
            $table->json('invoices')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('sent_by')->constrained('users');
            $table->dateTime('expires_at');
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
