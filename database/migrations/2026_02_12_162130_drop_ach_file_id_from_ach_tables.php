<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the ach_file_id columns left over from the removed AchFile feature.
     */
    public function up(): void
    {
        Schema::table('ach_batches', function (Blueprint $table) {
            $table->dropForeign(['ach_file_id']);
            $table->dropColumn('ach_file_id');
        });

        Schema::table('ach_returns', function (Blueprint $table) {
            $table->dropForeign(['ach_file_id']);
            $table->dropColumn('ach_file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ach_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('ach_file_id')->nullable()->after('batch_number');
        });

        Schema::table('ach_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('ach_file_id')->nullable()->after('ach_entry_id');
        });
    }
};
