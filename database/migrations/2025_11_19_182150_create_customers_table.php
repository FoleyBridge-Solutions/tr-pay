<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Customers Table Migration
 * 
 * ⚠️ IMPORTANT: This migration runs on SQLite (default connection)
 * NOT on the SQL Server database which is READ-ONLY!
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            
            // References to the READ-ONLY SQL Server database
            // These are stored here for lookup purposes only
            $table->string('client_id')->nullable()->comment('Reference to Client.client_id from SQL Server (READ-ONLY)');
            $table->integer('client_key')->nullable()->comment('Reference to Client.client_KEY from SQL Server (READ-ONLY)');
            
            // MiPaymentChoice Customer ID (managed by Billable trait)
            $table->string('mpc_customer_id')->nullable()->comment('MiPaymentChoice Customer ID');
            
            $table->timestamps();
            
            // Indexes
            $table->index('client_id');
            $table->index('client_key');
            $table->index('mpc_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
