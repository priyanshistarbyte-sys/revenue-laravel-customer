<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Date Wise / Date Range used to ride on the 'monthly' permission; they now have
 * their own 'date_wise' page. Grant it to every user and role that can view
 * Monthly today (and has no date_wise row yet) so nobody loses access.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::insert("
            INSERT INTO user_permissions (user_id, page, can_view, can_add, can_edit, can_delete, created_at, updated_at)
            SELECT m.user_id, 'date_wise', 1, 0, 0, 0, NOW(), NOW()
            FROM user_permissions m
            WHERE m.page = 'monthly' AND m.can_view = 1
              AND NOT EXISTS (SELECT 1 FROM user_permissions d WHERE d.user_id = m.user_id AND d.page = 'date_wise')
        ");
        DB::insert("
            INSERT INTO role_permissions (role_id, page, can_view, can_add, can_edit, can_delete, created_at, updated_at)
            SELECT m.role_id, 'date_wise', 1, 0, 0, 0, NOW(), NOW()
            FROM role_permissions m
            WHERE m.page = 'monthly' AND m.can_view = 1
              AND NOT EXISTS (SELECT 1 FROM role_permissions d WHERE d.role_id = m.role_id AND d.page = 'date_wise')
        ");

        // GAM Check to-dos (resolve / note) now need 'edit'; anyone who could
        // view the page could use them before, so carry that over.
        DB::update("UPDATE user_permissions SET can_edit = 1 WHERE page = 'gam_check' AND can_view = 1");
        DB::update("UPDATE role_permissions SET can_edit = 1 WHERE page = 'gam_check' AND can_view = 1");
    }

    public function down(): void
    {
        // Additive grant only; nothing to undo safely.
    }
};
