<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the GAM Ad Exchange Site (Inventory → Sites) registered for a domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('domains')) return;

        Schema::table('domains', function (Blueprint $table) {
            if (!Schema::hasColumn('domains', 'gam_site_id')) {
                $table->string('gam_site_id', 64)->nullable()->after('gam_ad_unit_error')
                      ->comment('GAM Ad Exchange site id registered for this domain');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('domains', 'gam_site_id')) {
            Schema::table('domains', function (Blueprint $table) {
                $table->dropColumn('gam_site_id');
            });
        }
    }
};
