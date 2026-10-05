<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Records which server each deployed host lives on. `host` is unique so a
        // site can only exist on one server — prevents deploying the same site to
        // two servers.
        Schema::create('server_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->string('host')->unique();
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('kind', 10)->nullable();   // 'main' (.com) | 'sub'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_sites');
    }
};
