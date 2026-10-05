<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user access control.
 *
 * Adds a page × action permission grid per user (and reusable role templates),
 * plus a per-user data-visibility scope (own | adx | all). `is_admin` users are
 * unaffected — they bypass every check in code (see helpers.php can()).
 *
 * Backfill preserves current behaviour for existing non-admins: they keep
 * Dashboard (view/edit) + Monthly (view) and own-data scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('data_scope', 10)->default('own')
                  ->comment('own | adx | all — how much data this user may see');
            $table->integer('role_id')->nullable()
                  ->comment('last permission-role template applied (informational)');
        });

        // Per-user page × action grid.
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->index();
            $table->string('page', 50);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'page'], 'uq_user_page');
        });

        // ADX networks a user may see when data_scope = 'adx'.
        Schema::create('user_allowed_adx', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->index();
            $table->integer('adx_id');
            $table->timestamps();
            $table->unique(['user_id', 'adx_id'], 'uq_user_adx');
        });

        // Reusable permission templates.
        Schema::create('permission_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->integer('role_id')->index();
            $table->string('page', 50);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();
            $table->unique(['role_id', 'page'], 'uq_role_page');
        });

        $this->backfill();
    }

    /** Grant existing active non-admins their current effective access. */
    private function backfill(): void
    {
        $now = now();
        $nonAdmins = DB::table('users')->where('is_admin', 0)->where('active', 1)->pluck('id');

        $grants = [
            'dashboard' => ['view' => 1, 'edit' => 1],
            'monthly'   => ['view' => 1],
        ];

        foreach ($nonAdmins as $uid) {
            foreach ($grants as $page => $acts) {
                DB::table('user_permissions')->updateOrInsert(
                    ['user_id' => $uid, 'page' => $page],
                    [
                        'can_view'   => $acts['view']   ?? 0,
                        'can_add'    => $acts['add']    ?? 0,
                        'can_edit'   => $acts['edit']   ?? 0,
                        'can_delete' => $acts['delete'] ?? 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permission_roles');
        Schema::dropIfExists('user_allowed_adx');
        Schema::dropIfExists('user_permissions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['data_scope', 'role_id']);
        });
    }
};
