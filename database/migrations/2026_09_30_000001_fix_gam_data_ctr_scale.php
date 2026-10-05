<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The daily GAM sync stored the API's CTR as a 0..1 ratio (0.0356) instead of a
 * percent (3.56). Recompute it from the same report's Ad Exchange clicks and
 * impressions — that is how GAM defines CTR.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update('UPDATE gam_data SET ctr = ROUND(clicks / impressions * 100, 4) WHERE impressions > 0');
    }

    public function down(): void
    {
        // Irreversible data fix.
    }
};
