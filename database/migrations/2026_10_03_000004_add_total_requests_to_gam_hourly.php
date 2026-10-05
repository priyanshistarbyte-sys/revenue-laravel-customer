<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ad Exchange total requests per site/hour — for the customer Hourly Wise report. */
    public function up(): void
    {
        Schema::table('gam_hourly', function (Blueprint $table) {
            $table->unsignedBigInteger('total_requests')->default(0)->after('impressions')
                  ->comment('Ad Exchange total requests');
        });
    }

    public function down(): void
    {
        Schema::table('gam_hourly', function (Blueprint $table) {
            $table->dropColumn('total_requests');
        });
    }
};
