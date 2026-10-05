<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A domain now gets several ad units, so gam_ad_unit_id / _code hold a CSV list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('domains', 'gam_ad_unit_id')) return;
        DB::statement("ALTER TABLE `domains` MODIFY `gam_ad_unit_id` VARCHAR(255) NULL");
        DB::statement("ALTER TABLE `domains` MODIFY `gam_ad_unit_code` VARCHAR(500) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `domains` MODIFY `gam_ad_unit_id` VARCHAR(64) NULL");
        DB::statement("ALTER TABLE `domains` MODIFY `gam_ad_unit_code` VARCHAR(255) NULL");
    }
};
