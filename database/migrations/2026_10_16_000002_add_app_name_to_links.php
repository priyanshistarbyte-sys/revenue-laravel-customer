<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Display name of the mobile app when link_type = app. */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->string('app_name', 255)->nullable()->default(null)->after('app_id')
                  ->comment('App name when link_type = app');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('app_name');
        });
    }
};
