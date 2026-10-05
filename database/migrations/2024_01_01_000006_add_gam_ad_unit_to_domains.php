<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the GAM ad unit created for a domain (via the Domains page button).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('domains')) return;

        Schema::table('domains', function (Blueprint $table) {
            if (!Schema::hasColumn('domains', 'gam_ad_unit_id')) {
                $table->string('gam_ad_unit_id', 64)->nullable()->after('adx_id')
                      ->comment('GAM ad unit id created for this domain');
            }
            if (!Schema::hasColumn('domains', 'gam_ad_unit_code')) {
                $table->string('gam_ad_unit_code', 255)->nullable()->after('gam_ad_unit_id');
            }
            if (!Schema::hasColumn('domains', 'gam_ad_unit_synced_at')) {
                $table->timestamp('gam_ad_unit_synced_at')->nullable()->after('gam_ad_unit_code');
            }
            if (!Schema::hasColumn('domains', 'gam_ad_unit_error')) {
                $table->string('gam_ad_unit_error', 500)->nullable()->after('gam_ad_unit_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            foreach (['gam_ad_unit_id', 'gam_ad_unit_code', 'gam_ad_unit_synced_at', 'gam_ad_unit_error'] as $c) {
                if (Schema::hasColumn('domains', $c)) $table->dropColumn($c);
            }
        });
    }
};
