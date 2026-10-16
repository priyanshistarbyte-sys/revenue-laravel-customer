<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Subdomain kind: 'web' (GAM site hosts) or 'app' (mobile App ID instead of hosts). */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->string('link_type', 10)->default('web')->after('link_name')
                  ->comment("'web' = GAM site hosts, 'app' = mobile app");
            $table->string('app_id', 255)->nullable()->default(null)->after('link_type')
                  ->comment('App ID / bundle when link_type = app');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn(['link_type', 'app_id']);
        });
    }
};
