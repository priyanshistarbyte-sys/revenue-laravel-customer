<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amaira users — per-user 6-digit PIN authentication (ported from the
 * original config.php / setup.php migrations 023 & 033). No email/password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('pin_hash', 255);
            $table->string('pin2_hash', 255)->nullable()
                  ->comment('second PIN hash — 2-step verification for admins');
            $table->boolean('is_admin')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Kept so the app can optionally switch SESSION_DRIVER=database.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
