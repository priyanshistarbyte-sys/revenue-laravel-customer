<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remembers which zip each host was last deployed with, so the Deploy
        // modal can pre-select it (and leave changed hosts blank on purpose).
        Schema::create('deploy_selections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('link_id');
            $table->string('host');              // normalized, www-stripped host
            $table->unsignedBigInteger('zip_id');
            $table->timestamps();
            $table->unique(['link_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_selections');
    }
};
