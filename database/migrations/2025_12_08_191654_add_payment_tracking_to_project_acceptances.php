<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds payment tracking and PracticeCS sync status fields.
     * These track whether the accepted project has been paid for
     * and whether the engagement type was successfully updated in PracticeCS.
     */
    public function up(): void
    {
        Schema::table('project_acceptances', function (Blueprint $table) {
            // Payment tracking
            $table->boolean('paid')->default(false)->after('acceptance_signature');
            $table->timestamp('paid_at')->nullable()->after('paid');
            $table->string('payment_transaction_id')->nullable()->after('paid_at');

            // PracticeCS sync status
            $table->boolean('practicecs_updated')->default(false)->after('payment_transaction_id');
            $table->integer('new_engagement_type_key')->nullable()->after('practicecs_updated');
            $table->timestamp('practicecs_updated_at')->nullable()->after('new_engagement_type_key');
            $table->text('practicecs_error')->nullable()->after('practicecs_updated_at');

            // Index for querying unpaid acceptances
            $table->index(['accepted', 'paid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_acceptances', function (Blueprint $table) {
            $table->dropIndex(['accepted', 'paid']);
            $table->dropColumn([
                'paid',
                'paid_at',
                'payment_transaction_id',
                'practicecs_updated',
                'new_engagement_type_key',
                'practicecs_updated_at',
                'practicecs_error',
            ]);
        });
    }
};
