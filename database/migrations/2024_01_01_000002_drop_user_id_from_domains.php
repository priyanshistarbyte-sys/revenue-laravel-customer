<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domains are shared across all (admin) users — remove the per-user owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('domains', 'user_id')) {
            return;
        }

        // Drop whatever index sits on user_id (name varies: idx_user on the
        // original PHP DB, domains_user_id_index on Laravel-created ones).
        foreach (DB::select("SHOW INDEX FROM `domains` WHERE Column_name = 'user_id'") as $idx) {
            DB::statement("ALTER TABLE `domains` DROP INDEX `{$idx->Key_name}`");
        }

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('domains', 'user_id')) {
            Schema::table('domains', function (Blueprint $table) {
                $table->integer('user_id')->default(0)->index()->after('id');
            });
        }
    }
};
