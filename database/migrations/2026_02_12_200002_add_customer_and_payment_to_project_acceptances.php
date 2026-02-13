<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add customer_id and payment_id FK columns to project_acceptances.
 *
 * project_acceptances was a completely isolated entity with zero relationships.
 * This links it to the customer who accepted and the payment that fulfilled it.
 *
 * Table has 0 rows so no backfill is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_acceptances', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('client_key');
            $table->unsignedBigInteger('payment_id')->nullable()->after('customer_id');

            $table->index('customer_id');
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_acceptances', function (Blueprint $table) {
            $table->dropIndex(['payment_id']);
            $table->dropIndex(['customer_id']);
            $table->dropColumn(['customer_id', 'payment_id']);
        });
    }
};
