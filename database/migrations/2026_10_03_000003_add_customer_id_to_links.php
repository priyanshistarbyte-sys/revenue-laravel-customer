<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Optional customer a subdomain is assigned to (Admin → Customers); NULL = none. */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->integer('customer_id')->nullable()->default(null)->index()
                  ->comment('FK to customers.id (optional)');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });
    }
};
