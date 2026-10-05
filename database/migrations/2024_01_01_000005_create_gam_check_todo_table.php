<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAM Check "TODO" state — lets a user mark a flagged link as resolved
 * (issue fixed / URL changed) and jot a note. One row per link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gam_check_todo')) return;

        Schema::create('gam_check_todo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('link_id')->unique();
            $table->boolean('done')->default(false);
            $table->string('note', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gam_check_todo');
    }
};
