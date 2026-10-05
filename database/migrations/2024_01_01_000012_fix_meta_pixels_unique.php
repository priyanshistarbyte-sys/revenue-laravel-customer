<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A pixel can be assigned to more than one ad account, and the builder shows
 * pixels per selected account — so key pixels by (account, ad account, pixel).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meta_pixels')) return;
        foreach (DB::select("SHOW INDEX FROM `meta_pixels` WHERE Key_name = 'uniq_meta_pixel'") as $_) {
            DB::statement("ALTER TABLE `meta_pixels` DROP INDEX `uniq_meta_pixel`");
            break;
        }
        DB::statement("ALTER TABLE `meta_pixels` ADD UNIQUE `uniq_meta_pixel_act` (`meta_account_id`, `act_id`, `pixel_id`)");
    }

    public function down(): void
    {
        if (!Schema::hasTable('meta_pixels')) return;
        foreach (DB::select("SHOW INDEX FROM `meta_pixels` WHERE Key_name = 'uniq_meta_pixel_act'") as $_) {
            DB::statement("ALTER TABLE `meta_pixels` DROP INDEX `uniq_meta_pixel_act`");
            break;
        }
        DB::statement("ALTER TABLE `meta_pixels` ADD UNIQUE `uniq_meta_pixel` (`meta_account_id`, `pixel_id`)");
    }
};
