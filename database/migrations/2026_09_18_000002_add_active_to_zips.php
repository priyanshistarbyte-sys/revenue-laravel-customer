<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Active/inactive flag for zips. Inactive zips stay on the ZIP Master page
     * (with their files and older versions) but are hidden from the Deploy picker.
     * Existing zips start out active.
     */
    public function up(): void
    {
        Schema::table('zips', function (Blueprint $table) {
            // No ->after(): original_name is optional and missing on some installs.
            $table->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('zips', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
