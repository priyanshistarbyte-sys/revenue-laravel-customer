<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gam_host_ad_units')) return;

        Schema::create('gam_host_ad_units', function (Blueprint $table) {
            // GAM ad units created for a subdomain GAM host from the Links page
            // (ab1.alopino.com → ab1alopino1..N). Main domains keep theirs on the
            // domains row; this covers hosts that have no row of their own.
            $table->id();
            $table->string('host')->unique();
            $table->unsignedBigInteger('adx_id')->nullable();
            $table->text('ad_unit_id')->nullable()->comment('comma-separated GAM ad unit ids');
            $table->text('ad_unit_code')->nullable()->comment('comma-separated ad unit codes');
            $table->string('error', 500)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gam_host_ad_units');
    }
};
