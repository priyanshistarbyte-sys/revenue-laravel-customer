<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of users.php — user management (admin only).
 */
class UsersController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Users';
        $activePage = 'users';
        $pdo   = getDB();
        $flash = null;

        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'toggle' => 'edit', 'clear_pin2' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('users', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' users.');
            }
            // Only an admin may grant admin, or change an admin or their own account
            // here — otherwise Users access would be a path to escalate privileges.
            if (!isAdmin()) {
                if ($request->boolean('is_admin')) {
                    abort(403, 'Only an admin can grant admin rights.');
                }
                $targetId = (int) $request->input('id', 0);
                if ($targetId > 0 && $action !== 'add') {
                    if ($targetId === currentUserId()) {
                        abort(403, 'You cannot change your own account here — use Settings for your PIN.');
                    }
                    $t = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
                    $t->execute([$targetId]);
                    if ((int) $t->fetchColumn() === 1) {
                        abort(403, 'Only an admin can change an admin account.');
                    }
                }
            }

            if ($action === 'add') {
                $name    = trim((string) $request->input('name', ''));
                $pin     = trim((string) $request->input('pin', ''));
                $confirm = trim((string) $request->input('pin_confirm', ''));
                $isAdmin = (bool) $request->input('is_admin');

                if ($name === '') {
                    $flash = ['type' => 'error', 'msg' => 'Name is required.'];
                } elseif (!preg_match('/^\d{6}$/', $pin)) {
                    $flash = ['type' => 'error', 'msg' => 'PIN must be exactly 6 digits.'];
                } elseif ($pin !== $confirm) {
                    $flash = ['type' => 'error', 'msg' => 'PINs do not match.'];
                } elseif (pinInUse($pin)) {
                    $flash = ['type' => 'error', 'msg' => 'This PIN is already used by another user. Every user needs a unique PIN.'];
                } else {
                    $newId = createUser($name, $pin, $isAdmin);
                    $this->savePermissions($pdo, $newId, $request);
                    $flash = ['type' => 'success', 'msg' => "User '$name' added." . ($isAdmin ? ' (admin)' : '')];
                }
            }

            if ($action === 'edit') {
                $id      = (int) $request->input('id', 0);
                $name    = trim((string) $request->input('name', ''));
                $pin     = trim((string) $request->input('pin', ''));
                $isAdmin = (bool) $request->input('is_admin');

                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();

                $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND active = 1")->fetchColumn();

                if (!$target || $name === '') {
                    $flash = ['type' => 'error', 'msg' => 'User not found or name missing.'];
                } elseif ($pin !== '' && !preg_match('/^\d{6}$/', $pin)) {
                    $flash = ['type' => 'error', 'msg' => 'New PIN must be exactly 6 digits (leave blank to keep the current PIN).'];
                } elseif ($pin !== '' && pinInUse($pin, $id)) {
                    $flash = ['type' => 'error', 'msg' => 'This PIN is already used by another user.'];
                } elseif ((int) $target['is_admin'] === 1 && !$isAdmin && $adminCount <= 1) {
                    $flash = ['type' => 'error', 'msg' => 'Cannot remove admin rights from the last admin.'];
                } else {
                    if ($pin !== '') {
                        $pdo->prepare("UPDATE users SET name=?, is_admin=?, pin_hash=? WHERE id=?")
                            ->execute([$name, $isAdmin ? 1 : 0, password_hash($pin, PASSWORD_BCRYPT), $id]);
                    } else {
                        $pdo->prepare("UPDATE users SET name=?, is_admin=? WHERE id=?")
                            ->execute([$name, $isAdmin ? 1 : 0, $id]);
                    }
                    $this->savePermissions($pdo, $id, $request);
                    $flash = ['type' => 'success', 'msg' => "User '$name' updated." . ($pin !== '' ? ' New PIN set.' : '')];
                }
            }

            if ($action === 'toggle') {
                $id = (int) $request->input('id', 0);
                if ($id === currentUserId()) {
                    $flash = ['type' => 'error', 'msg' => 'You cannot deactivate your own account.'];
                } elseif ($id > 0) {
                    $pdo->prepare("UPDATE users SET active = 1 - active WHERE id = ?")->execute([$id]);
                    $flash = ['type' => 'success', 'msg' => 'User status toggled.'];
                }
            }

            if ($action === 'clear_pin2') {
                $id = (int) $request->input('id', 0);
                if ($id > 0) {
                    $pdo->prepare("UPDATE users SET pin2_hash = NULL WHERE id = ?")->execute([$id]);
                    $flash = ['type' => 'success', 'msg' => 'Two-step verification cleared. That user signs in with their PIN alone until they set a new second PIN.'];
                }
            }

            if ($action === 'delete') {
                $id = (int) $request->input('id', 0);
                if ($id === currentUserId()) {
                    $flash = ['type' => 'error', 'msg' => 'You cannot delete your own account.'];
                } elseif ($id > 0) {
                    $owned = 0;
                    foreach (['links', 'meta_data', 'gam_data'] as $t) {
                        $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE user_id = ?");
                        $s->execute([$id]);
                        $owned += (int) $s->fetchColumn();
                    }
                    if ($owned > 0) {
                        $flash = ['type' => 'error', 'msg' => "Cannot delete: this user still owns $owned data record(s). Deactivate the user instead, or delete their data first."];
                    } else {
                        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                        $flash = ['type' => 'success', 'msg' => 'User deleted.'];
                    }
                }
            }
        }

        $users = $pdo->query("SELECT * FROM users ORDER BY is_admin DESC, name ASC")->fetchAll();

        $counts = [];
        foreach (['links', 'meta_data', 'gam_data'] as $t) {
            foreach ($pdo->query("SELECT user_id, COUNT(*) AS c FROM `$t` GROUP BY user_id")->fetchAll() as $r) {
                $counts[(int) $r['user_id']][$t] = (int) $r['c'];
            }
        }

        $editUser = null;
        if ($request->has('edit')) {
            $eid = (int) $request->query('edit');
            foreach ($users as $u) {
                if ((int) $u['id'] === $eid) { $editUser = $u; break; }
            }
        }

        // ── Permission UI data ──
        $adxList = $pdo->query("SELECT id, name FROM adx WHERE active = 1 ORDER BY name")->fetchAll();
        $roles   = $pdo->query("SELECT id, name FROM permission_roles ORDER BY name")->fetchAll();

        // Per-user access summary for the list column.
        $roleNames = [];
        foreach ($roles as $r) $roleNames[(int) $r['id']] = $r['name'];
        $viewCounts = [];
        foreach ($pdo->query("SELECT user_id, COUNT(*) c FROM user_permissions WHERE can_view = 1 GROUP BY user_id")->fetchAll() as $r) {
            $viewCounts[(int) $r['user_id']] = (int) $r['c'];
        }
        $adxCounts = [];
        foreach ($pdo->query("SELECT user_id, COUNT(*) c FROM user_allowed_adx GROUP BY user_id")->fetchAll() as $r) {
            $adxCounts[(int) $r['user_id']] = (int) $r['c'];
        }

        // role_id → page → actions map (fed to JS so a template pre-fills the grid)
        $rolePerms = [];
        foreach ($pdo->query("SELECT role_id, page, can_view, can_add, can_edit, can_delete FROM role_permissions")->fetchAll() as $r) {
            $rolePerms[(int) $r['role_id']][$r['page']] = [
                'view'   => (int) $r['can_view'],
                'add'    => (int) $r['can_add'],
                'edit'   => (int) $r['can_edit'],
                'delete' => (int) $r['can_delete'],
            ];
        }

        // Prefill for the edit modal.
        $editPerms = []; $editAllowedAdx = [];
        if ($editUser) {
            $ps = $pdo->prepare("SELECT page, can_view, can_add, can_edit, can_delete FROM user_permissions WHERE user_id = ?");
            $ps->execute([(int) $editUser['id']]);
            foreach ($ps->fetchAll() as $r) {
                $editPerms[$r['page']] = [
                    'view'   => (int) $r['can_view'],
                    'add'    => (int) $r['can_add'],
                    'edit'   => (int) $r['can_edit'],
                    'delete' => (int) $r['can_delete'],
                ];
            }
            $as = $pdo->prepare("SELECT adx_id FROM user_allowed_adx WHERE user_id = ?");
            $as->execute([(int) $editUser['id']]);
            $editAllowedAdx = array_map('intval', $as->fetchAll(\PDO::FETCH_COLUMN));
        }

        return view('users', compact(
            'pageTitle', 'activePage', 'flash', 'users', 'counts', 'editUser',
            'adxList', 'roles', 'rolePerms', 'editPerms', 'editAllowedAdx',
            'roleNames', 'viewCounts', 'adxCounts'
        ));
    }

    /**
     * Persist a user's data scope, allowed ADX networks and permission grid from
     * the add/edit form. Admins bypass these at runtime, but we still store the
     * grid so it survives an admin→user demotion.
     *
     * A non-admin editor can only grant what they hold themselves: any page
     * action, data scope or ADX network beyond their own keeps the target's
     * existing value instead of taking the submitted one.
     */
    private function savePermissions(\PDO $pdo, int $userId, Request $request): void
    {
        $limited = !isAdmin();

        $cur = $pdo->prepare("SELECT data_scope FROM users WHERE id = ?");
        $cur->execute([$userId]);
        $oldScope = (string) ($cur->fetchColumn() ?: 'own');

        $scope = (string) $request->input('data_scope', 'own');
        if (!in_array($scope, ['own', 'adx', 'all'], true)) $scope = 'own';
        $rank = ['own' => 0, 'adx' => 1, 'all' => 2];
        if ($limited && $rank[$scope] > $rank[userDataScope()]) $scope = $oldScope;
        $roleId = (int) $request->input('role_id', 0) ?: null;

        $pdo->prepare("UPDATE users SET data_scope = ?, role_id = ? WHERE id = ?")->execute([$scope, $roleId, $userId]);

        // ADX networks the editor may hand out (all, unless they are ADX-scoped).
        $grantableAdx = ($limited && userDataScope() === 'adx') ? userAllowedAdx() : null;
        $old = $pdo->prepare("SELECT adx_id FROM user_allowed_adx WHERE user_id = ?");
        $old->execute([$userId]);
        $keptAdx = $grantableAdx === null ? [] : array_diff(array_map('intval', $old->fetchAll(\PDO::FETCH_COLUMN)), $grantableAdx);

        // Allowed ADX (only when scope = adx).
        $pdo->prepare("DELETE FROM user_allowed_adx WHERE user_id = ?")->execute([$userId]);
        if ($scope === 'adx') {
            $requested = array_unique(array_filter(array_map('intval', (array) $request->input('allowed_adx', []))));
            if ($grantableAdx !== null) {
                $requested = array_unique(array_merge(array_intersect($requested, $grantableAdx), $keptAdx));
            }
            if ($requested) {
                $valid = array_map('intval', $pdo->query("SELECT id FROM adx")->fetchAll(\PDO::FETCH_COLUMN));
                $ins = $pdo->prepare("INSERT INTO user_allowed_adx (user_id, adx_id, created_at, updated_at) VALUES (?,?,NOW(),NOW())");
                foreach ($requested as $aid) {
                    if (in_array($aid, $valid, true)) $ins->execute([$userId, $aid]);
                }
            }
        }

        // Permission grid.
        $oldPerms = [];
        $op = $pdo->prepare("SELECT page, can_view, can_add, can_edit, can_delete FROM user_permissions WHERE user_id = ?");
        $op->execute([$userId]);
        foreach ($op->fetchAll() as $r) {
            $oldPerms[$r['page']] = ['view' => $r['can_view'], 'add' => $r['can_add'], 'edit' => $r['can_edit'], 'delete' => $r['can_delete']];
        }
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$userId]);
        $perm = (array) $request->input('perm', []);
        if ($limited) {
            foreach (permissionPages() as $page => $meta) {
                foreach (['view', 'add', 'edit', 'delete'] as $act) {
                    if (!userCan($page, $act)) {
                        if (!empty($oldPerms[$page][$act])) $perm[$page][$act] = 1;
                        else unset($perm[$page][$act]);
                    }
                }
            }
        }
        $ins  = $pdo->prepare("INSERT INTO user_permissions (user_id, page, can_view, can_add, can_edit, can_delete, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())");
        foreach (permissionPages() as $page => $meta) {
            $p    = (array) ($perm[$page] ?? []);
            $acts = $meta['actions'];
            $view = !empty($p['view']) ? 1 : 0;
            $add  = (in_array('add', $acts, true)    && !empty($p['add']))    ? 1 : 0;
            $edit = (in_array('edit', $acts, true)   && !empty($p['edit']))   ? 1 : 0;
            $del  = (in_array('delete', $acts, true) && !empty($p['delete'])) ? 1 : 0;
            if ($add || $edit || $del) $view = 1; // any write implies view
            if ($view || $add || $edit || $del) {
                $ins->execute([$userId, $page, $view, $add, $edit, $del]);
            }
        }
    }
}
