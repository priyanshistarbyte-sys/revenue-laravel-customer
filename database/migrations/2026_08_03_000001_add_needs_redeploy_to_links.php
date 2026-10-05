<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // Set when a link edit changes the derived MAIN_URL, so the UI can
            // prompt re-deploying the Meta URL sub-site. Cleared on next deploy.
            $table->boolean('needs_redeploy')->default(false)->after('gam_url');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('needs_redeploy');
        });
    }
};
