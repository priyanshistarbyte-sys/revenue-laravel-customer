<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers (Admin → Customers): a name, a unique login ID, a hashed password
 * and their own page × action permission grid (same shape as user_permissions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('login_id', 100)->unique();
            $table->string('password_hash', 255);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_permissions', function (Blueprint $table) {
            $table->id();
            $table->integer('customer_id')->index();
            $table->string('page', 50);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();
            $table->unique(['customer_id', 'page'], 'uq_customer_page');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_permissions');
        Schema::dropIfExists('customers');
    }
};
