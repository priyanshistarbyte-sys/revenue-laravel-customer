<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-ADX toggle: auto-create GAM ad units when a domain on this network is added.
 * The manual "Create" button on the Domains page works regardless of this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('adx') || Schema::hasColumn('adx', 'auto_ad_unit')) return;

        Schema::table('adx', function (Blueprint $table) {
            $table->boolean('auto_ad_unit')->default(true)->after('hourly_report_id')
                  ->comment('Auto-create ad units when a domain on this network is added');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('adx', 'auto_ad_unit')) {
            Schema::table('adx', function (Blueprint $table) {
                $table->dropColumn('auto_ad_unit');
            });
        }
    }
};
