<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft campaigns for the Meta campaign builder. One row per campaign; its ad
 * sets / ads and all field values live in the `data` JSON so the nested grid
 * can evolve without schema churn. Normalized into real Meta objects at publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meta_campaign_drafts')) return;

        Schema::create('meta_campaign_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->default(0);
            $table->integer('position')->default(0);
            $table->string('status', 20)->default('draft');   // draft | publishing | live | failed
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_campaign_drafts');
    }
};
