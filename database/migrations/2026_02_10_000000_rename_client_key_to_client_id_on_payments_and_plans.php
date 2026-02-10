<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename client_key to client_id on payments, payment_plans, and ach_entries tables.
     *
     * After Phase 4, these columns store human-readable client_id values,
     * so the column name should match.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('client_key', 'client_id');
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->renameColumn('client_key', 'client_id');
        });

        // Fix column type on payment_plans: was unsignedBigInteger, should be string
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->string('client_id', 50)->nullable()->change();
        });

        Schema::table('ach_entries', function (Blueprint $table) {
            $table->renameColumn('client_key', 'client_id');
        });
    }

    /**
     * Reverse the column renames.
     */
    public function down(): void
    {
        Schema::table('ach_entries', function (Blueprint $table) {
            $table->renameColumn('client_id', 'client_key');
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->renameColumn('client_id', 'client_key');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('client_id', 'client_key');
        });
    }
};
