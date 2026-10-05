<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly GAM (Ad Exchange) per-site report — powers the GAM Check page.
 * One row per site × date × hour. Global (matched by site), like gam_data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gam_hourly')) return;

        Schema::create('gam_hourly', function (Blueprint $table) {
            $table->id();
            $table->string('site', 300);
            $table->date('date');
            $table->unsignedTinyInteger('hour')->comment('0–23, GAM report time zone');
            $table->decimal('ctr', 8, 4)->default(0)->comment('Ad Exchange CTR (%)');
            $table->decimal('revenue', 14, 6)->default(0)->comment('Ad Exchange revenue (report currency)');
            $table->unsignedBigInteger('impressions')->default(0)->comment('Ad server impressions');
            $table->string('gam_currency', 3)->nullable();
            $table->timestamps();

            $table->unique(['site', 'date', 'hour'], 'uniq_site_date_hour');
            $table->index(['site', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gam_hourly');
    }
};
