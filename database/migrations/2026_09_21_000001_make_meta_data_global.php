<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta spend becomes GLOBAL like gam_data: one row per (campaign, date, url),
     * user_id always 0. Previously the same report uploaded by two users (or
     * synced for two link owners) was stored twice and summed twice in reports.
     *
     * Duplicates are collapsed to the most recently updated row.
     */
    public function up(): void
    {
        DB::statement("
            DELETE m FROM meta_data m
            JOIN meta_data k
              ON k.campaign_name = m.campaign_name
             AND k.`date`        = m.`date`
             AND k.website_url   = m.website_url
             AND (COALESCE(k.updated_at, '1970-01-01') > COALESCE(m.updated_at, '1970-01-01')
                  OR (COALESCE(k.updated_at, '1970-01-01') = COALESCE(m.updated_at, '1970-01-01') AND k.id > m.id))
        ");
        DB::table('meta_data')->where('user_id', '<>', 0)->update(['user_id' => 0]);

        Schema::table('meta_data', function (Blueprint $table) {
            $table->dropUnique('uq_user_campaign_date_url');
            $table->unique(['campaign_name', 'date', 'website_url'], 'uq_campaign_date_url');
        });
    }

    public function down(): void
    {
        Schema::table('meta_data', function (Blueprint $table) {
            $table->dropUnique('uq_campaign_date_url');
            $table->unique(['user_id', 'campaign_name', 'date', 'website_url'], 'uq_user_campaign_date_url');
        });
    }
};
