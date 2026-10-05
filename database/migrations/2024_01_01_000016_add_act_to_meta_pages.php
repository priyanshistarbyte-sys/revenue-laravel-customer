<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pages derived from an account's ads are specific to that ad account, so the
 * Fan Page picker can show only the selected account's pages. Add act_id
 * (''=global / directly-managed) and widen the unique key to include it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('meta_pages', 'act_id')) {
            DB::statement("ALTER TABLE meta_pages ADD COLUMN act_id VARCHAR(64) NOT NULL DEFAULT '' AFTER meta_account_id");
        }
        try { DB::statement("ALTER TABLE meta_pages DROP INDEX uniq_meta_page"); } catch (\Throwable $e) {}
        try { DB::statement("ALTER TABLE meta_pages ADD UNIQUE uniq_meta_page (meta_account_id, page_id, act_id)"); } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        try { DB::statement("ALTER TABLE meta_pages DROP INDEX uniq_meta_page"); } catch (\Throwable $e) {}
        try { DB::statement("ALTER TABLE meta_pages ADD UNIQUE uniq_meta_page (meta_account_id, page_id)"); } catch (\Throwable $e) {}
        if (Schema::hasColumn('meta_pages', 'act_id')) DB::statement("ALTER TABLE meta_pages DROP COLUMN act_id");
    }
};
