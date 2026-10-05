<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ad-account / page / pixel / media names can exceed 255 chars (long ad-image
 * names and video titles especially), which broke the assets sync with
 * "Data too long for column 'name'". Widen them to 512.
 */
return new class extends Migration
{
    private array $tables = ['meta_ad_accounts', 'meta_pages', 'meta_pixels', 'meta_media'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasColumn($t, 'name')) {
                DB::statement("ALTER TABLE `$t` MODIFY `name` VARCHAR(512) NULL");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasColumn($t, 'name')) {
                DB::statement("ALTER TABLE `$t` MODIFY `name` VARCHAR(255) NULL");
            }
        }
    }
};
