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
        Schema::create('project_acceptances', function (Blueprint $table) {
            $table->id();
            $table->integer('project_engagement_key')->unique()->comment('From MSSQL Engagement table');
            $table->integer('client_key')->comment('From MSSQL Client table');
            $table->string('client_group_name')->nullable();
            $table->string('engagement_id');
            $table->string('project_name');
            $table->decimal('budget_amount', 15, 2);
            $table->boolean('accepted')->default(false);
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by_ip')->nullable();
            $table->text('acceptance_signature')->nullable(); // Client name typed as signature
            $table->timestamps();
            
            $table->index('client_key');
            $table->index('accepted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_acceptances');
    }
};
