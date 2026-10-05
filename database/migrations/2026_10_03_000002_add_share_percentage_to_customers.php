<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Customer's revenue share, as a percent (0–100). */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('share_percentage', 5, 2)->default(0)->after('login_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('share_percentage');
        });
    }
};
