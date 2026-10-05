<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adx', function (Blueprint $table) {
            // ads.txt content for this ADX network (LONGTEXT — ads.txt can be large).
            $table->longText('ads_txt')->nullable()->after('adx_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('adx', function (Blueprint $table) {
            $table->dropColumn('ads_txt');
        });
    }
};
