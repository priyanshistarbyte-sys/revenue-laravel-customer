<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second GAM saved-report id per ADX network — the hourly Site×Hour CTR report
 * that feeds GAM Check (fetched by the same sync that pulls revenue).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('adx') || Schema::hasColumn('adx', 'hourly_report_id')) return;

        Schema::table('adx', function (Blueprint $table) {
            $table->string('hourly_report_id', 100)->nullable()->after('saved_report_id')
                  ->comment('GAM saved report id for the hourly CTR report (GAM Check)');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('adx', 'hourly_report_id')) {
            Schema::table('adx', function (Blueprint $table) {
                $table->dropColumn('hourly_report_id');
            });
        }
    }
};
