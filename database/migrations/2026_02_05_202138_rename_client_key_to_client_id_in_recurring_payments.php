<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename client_key to client_id in recurring_payments table.
 *
 * The column was incorrectly named 'client_key' but actually stores
 * the PracticeCS Client.client_id (human-readable ID), not Client.client_KEY
 * (internal integer primary key).
 *
 * This migration corrects the naming to match the actual data stored.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recurring_payments', function (Blueprint $table) {
            $table->renameColumn('client_key', 'client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_payments', function (Blueprint $table) {
            $table->renameColumn('client_id', 'client_key');
        });
    }
};
