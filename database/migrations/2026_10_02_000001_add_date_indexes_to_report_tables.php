<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every report (Dashboard, Monthly, Date Wise, Date Range, Upload, GAM Check)
 * filters meta_data / gam_data / gam_hourly by `date`, but their keys all lead
 * with campaign_name / site, so those queries were full-table scans.
 *
 * - meta_data: (date, campaign_name, amount_spent) covers the per-date spend
 *   aggregates outright, so they never touch the row data.
 * - gam_data:  (date, site) for the per-date revenue aggregates.
 * - gam_hourly: (date, site, hour) for GAM Check's by-date view; the old
 *   (site, date) index is dropped — it duplicates the unique key's prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_data', function (Blueprint $table) {
            $table->index(['date', 'campaign_name', 'amount_spent'], 'idx_meta_date_campaign');
        });
        Schema::table('gam_data', function (Blueprint $table) {
            $table->index(['date', 'site'], 'idx_gam_date_site');
        });
        Schema::table('gam_hourly', function (Blueprint $table) {
            $table->dropIndex('gam_hourly_site_date_index');
            $table->index(['date', 'site', 'hour'], 'idx_gam_hourly_date_site_hour');
        });
    }

    public function down(): void
    {
        Schema::table('meta_data', fn (Blueprint $table) => $table->dropIndex('idx_meta_date_campaign'));
        Schema::table('gam_data', fn (Blueprint $table) => $table->dropIndex('idx_gam_date_site'));
        Schema::table('gam_hourly', function (Blueprint $table) {
            $table->dropIndex('idx_gam_hourly_date_site_hour');
            $table->index(['site', 'date']);
        });
    }
};
