<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // Server picked when the subdomain was added (for the name.com DNS
            // records). Pre-selects the server in the Deploy modal, and is kept in
            // sync with the last server the link was actually deployed to.
            $table->unsignedBigInteger('dns_server_id')->nullable()->after('needs_redeploy');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('dns_server_id');
        });
    }
};
