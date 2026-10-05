<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ad Exchange Site × Date × Country report (GLOBAL) — customer Country Wise report. */
    public function up(): void
    {
        Schema::create('gam_country', function (Blueprint $table) {
            $table->id();
            $table->string('site', 300);
            $table->date('date');
            $table->string('country', 100);
            $table->decimal('ctr', 8, 4)->default(0)->comment('Ad Exchange CTR (%)');
            $table->decimal('revenue', 14, 6)->default(0)->comment('Ad Exchange revenue (report currency)');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('total_requests')->default(0);
            $table->string('gam_currency', 3)->nullable();
            $table->timestamps();

            $table->unique(['site', 'date', 'country'], 'uniq_site_date_country');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gam_country');
    }
};
