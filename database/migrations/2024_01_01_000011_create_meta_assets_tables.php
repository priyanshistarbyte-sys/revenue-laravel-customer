<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta assets synced from the Graph API to populate the campaign-builder
 * dropdowns: ad accounts, pages, pixels — each tied to a meta_accounts row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meta_ad_accounts')) {
            Schema::create('meta_ad_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meta_account_id');
                $table->string('act_id', 64)->comment('e.g. act_1451129893448122');
                $table->string('account_id', 64)->nullable();
                $table->string('name', 255)->nullable();
                $table->string('currency', 8)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique(['meta_account_id', 'act_id'], 'uniq_meta_act');
            });
        }

        if (!Schema::hasTable('meta_pages')) {
            Schema::create('meta_pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meta_account_id');
                $table->string('page_id', 64);
                $table->string('name', 255)->nullable();
                $table->timestamps();
                $table->unique(['meta_account_id', 'page_id'], 'uniq_meta_page');
            });
        }

        if (!Schema::hasTable('meta_pixels')) {
            Schema::create('meta_pixels', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meta_account_id');
                $table->string('act_id', 64)->comment('ad account this pixel belongs to');
                $table->string('pixel_id', 64);
                $table->string('name', 255)->nullable();
                $table->timestamps();
                $table->unique(['meta_account_id', 'pixel_id'], 'uniq_meta_pixel');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_pixels');
        Schema::dropIfExists('meta_pages');
        Schema::dropIfExists('meta_ad_accounts');
    }
};
