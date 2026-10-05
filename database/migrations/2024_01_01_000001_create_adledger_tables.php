<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amaira domain schema.
 *
 * This recreates the FINAL state of the original hand-rolled migrations
 * (setup.php 001–033): every ALTER folded into the CREATE. Column comments
 * preserve the original intent. Standard Laravel timestamps are used
 * throughout for Eloquent convenience.
 *
 * User scoping (original migrations 024/025/030):
 *   - links, meta_data are per-user (user_id owner).
 *   - gam_data is GLOBAL: one row per (site, date), user_id always 0.
 *   - adx, meta_accounts, expenses, invoices, currencies are shared.
 *   - domains are per-user.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── currencies (Currency Master) ──
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique()->comment('ISO 4217 code, e.g. INR, USD');
            $table->string('symbol', 5);
            $table->string('name', 50);
            $table->decimal('rate_to_default', 18, 6)->default(1)
                  ->comment('1 unit of this currency = X units of default currency');
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── settings (key/value) ──
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100)->unique();
            $table->string('setting_value', 500)->default('');
            $table->string('label', 200)->nullable();
            $table->timestamps();
        });

        // ── adx (each ADX network = a GAM account) ──
        Schema::create('adx', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->string('network_code', 50)->default('')->comment('GAM network code');
            $table->string('key_file', 500)->default('')->comment('path to service-account JSON key');
            $table->string('saved_report_id', 100)->default('')->comment('GAM saved report ID');
            $table->string('gam_currency', 3)->default('USD');
            $table->dateTime('last_sync')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // ── domains (main domains that group subdomains/links) — shared across users ──
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->comment('main domain name');
            $table->integer('adx_id')->nullable()->comment('FK to adx.id (ADX network for this domain)');
            $table->text('notes')->nullable();
            $table->boolean('approved')->default(false)->comment('approval status flag');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── links (subdomains) ──
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->integer('adx_id')->nullable()->comment('FK to adx.id (legacy per-link ADX)');
            $table->integer('user_id')->default(0)->index()->comment('FK to users.id (data owner)');
            $table->integer('domain_id')->nullable()->index()->comment('FK to domains.id (parent main domain)');
            $table->string('link_name', 150);
            $table->text('meta_campaign')->comment('comma-separated campaign names from Meta CSV');
            $table->string('meta_url', 300);
            $table->string('meta_host', 255)->default('')
                  ->comment('normalized hostname of meta_url, used to match meta_data.website_url');
            $table->text('gam_url')->comment('comma-separated GAM site host(s)');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── meta_data (Meta Ads spend, per-user) ──
        Schema::create('meta_data', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->default(0)->index()->comment('FK to users.id (data owner)');
            $table->string('campaign_name', 150);
            $table->date('date');
            $table->decimal('amount_spent', 12, 4)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->integer('results')->default(0);
            $table->decimal('frequency', 10, 6)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->decimal('amount_spent_raw', 14, 4)->default(0)
                  ->comment('amount before conversion to default currency');
            $table->string('website_url', 300)->default('')
                  ->comment('protocol-stripped Website URL from Meta CSV, used to auto-link campaigns');
            $table->timestamps();
            $table->unique(['user_id', 'campaign_name', 'date', 'website_url'], 'uq_user_campaign_date_url');
        });

        // ── gam_data (Google Ad Manager revenue, GLOBAL) ──
        Schema::create('gam_data', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->default(0)->index()->comment('always 0 — GAM data is global');
            $table->string('site', 300);
            $table->date('date');
            $table->decimal('revenue_usd', 14, 6)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('total_requests')->default(0);
            $table->decimal('match_rate', 8, 4)->default(0);
            $table->decimal('ctr', 8, 4)->default(0);
            $table->decimal('ecpm', 10, 4)->default(0);
            $table->string('gam_currency', 3)->default('USD')
                  ->comment('original currency of report; revenue_usd stored as USD equivalent');
            $table->timestamps();
            $table->unique(['site', 'date'], 'uq_site_date');
        });

        // ── meta_accounts (Meta/Facebook ad accounts) ──
        Schema::create('meta_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('username', 150)->default('');
            $table->string('password', 255)->default('');
            $table->string('auth', 500)->default('');
            $table->string('email', 255)->default('');
            $table->string('email_password', 255)->default('');
            $table->string('recovery_email', 255)->default('');
            $table->string('recovery_password', 255)->default('');
            $table->text('bm')->comment('comma-separated BM IDs');
            $table->text('ad_account_ids')->comment('comma-separated Ad Account IDs');
            $table->string('page_url', 500)->default('');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('owner_user_id')->default(0)->comment('app user whose P&L synced spend belongs to');
            $table->text('api_token')->nullable()->comment('Meta System User access token (ads_read)');
            $table->dateTime('last_sync')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // ── expenses ──
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('item_name', 200);
            $table->string('action', 500)->default('');
            $table->enum('currency', ['USD', 'INR'])->default('INR');
            $table->decimal('price_raw', 14, 2)->default(0)->comment('amount in original currency');
            $table->decimal('price_inr', 14, 2)->default(0)->comment('amount in INR');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ── invoices ──
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('title', 300)->default('');
            $table->string('file_name', 300)->comment('stored filename on disk');
            $table->string('original_name', 300)->comment('original uploaded filename');
            $table->integer('file_size')->default(0);
            $table->string('mime_type', 100)->default('');
            $table->text('notes')->nullable();
            $table->string('account', 300)->default('');
            $table->timestamps();
        });

        $this->seed();
    }

    /**
     * Seed defaults (original setup.php migrations 013 & 015).
     */
    private function seed(): void
    {
        $now = now();

        \DB::table('settings')->insertOrIgnore([
            ['setting_key' => 'usd_inr_rate', 'setting_value' => '95', 'label' => 'USD to INR Exchange Rate', 'created_at' => $now, 'updated_at' => $now],
            ['setting_key' => 'gst_rate',     'setting_value' => '18', 'label' => 'GST Rate (%)',            'created_at' => $now, 'updated_at' => $now],
        ]);

        if (\DB::table('currencies')->count() === 0) {
            $usdRate = (float) (\DB::table('settings')->where('setting_key', 'usd_inr_rate')->value('setting_value') ?: 95);
            \DB::table('currencies')->insert([
                ['code' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee', 'rate_to_default' => 1,        'is_default' => 1, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar',    'rate_to_default' => $usdRate, 'is_default' => 0, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'expenses', 'meta_accounts', 'gam_data', 'meta_data', 'links', 'domains', 'adx', 'settings', 'currencies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
