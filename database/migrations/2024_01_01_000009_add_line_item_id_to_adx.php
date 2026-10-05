<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional GAM line item id per ADX network. When set, ad units created for a
 * domain on this network are added to that line item's inventory targeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('adx') || Schema::hasColumn('adx', 'line_item_id')) return;

        Schema::table('adx', function (Blueprint $table) {
            $table->string('line_item_id', 64)->nullable()->after('auto_ad_unit')
                  ->comment('GAM line item to add new ad units to (optional)');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('adx', 'line_item_id')) {
            Schema::table('adx', function (Blueprint $table) {
                $table->dropColumn('line_item_id');
            });
        }
    }
};
