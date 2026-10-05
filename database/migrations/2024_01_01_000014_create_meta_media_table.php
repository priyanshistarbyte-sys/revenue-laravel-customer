<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creative-Hub media for the campaign builder's Video/Image pickers.
 *   - source = 'meta'   → synced from the Graph API (adimages / advideos)
 *   - source = 'upload' → uploaded by an admin, stored under storage/app/public
 * `media_ref` is what a draft stores (image hash / video id / upload uuid).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meta_media')) return;

        Schema::create('meta_media', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8)->comment('image | video');
            $table->string('media_ref', 191)->comment('image hash / video id / upload uuid — stored in drafts');
            $table->string('name', 255)->nullable();
            $table->text('url')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('source', 8)->default('meta');
            $table->unsignedBigInteger('meta_account_id')->nullable();
            $table->string('act_id', 64)->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'media_ref'], 'uniq_meta_media');
            $table->index(['source', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_media');
    }
};
