<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adx', function (Blueprint $table) {
            // ADX prefix — defaults to the network code value, but editable.
            $table->string('adx_prefix', 50)->default('')->after('network_code');
        });
    }

    public function down(): void
    {
        Schema::table('adx', function (Blueprint $table) {
            $table->dropColumn('adx_prefix');
        });
    }
};
