<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Permission role templates — reusable page × action permission grids that an
 * admin can apply to a user (as a starting point) from the Users page.
 * Super-admin only (gated by the `admin` middleware on the route).
 */
class RolesController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Roles';
        $activePage = 'roles';
        $pdo   = getDB();
        $flash = session('flash');

        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('roles', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' roles.');
            }

            if ($action === 'add' || $action === 'edit') {
                $id   = (int) $request->input('id', 0);
                $name = trim((string) $request->input('name', ''));
                if ($name === '') {
                    $flash = ['type' => 'error', 'msg' => 'Role name is required.'];
                } else {
                    try {
                        if ($action === 'add') {
                            $pdo->prepare("INSERT INTO permission_roles (name, created_at, updated_at) VALUES (?,NOW(),NOW())")->execute([$name]);
                            $id  = (int) $pdo->lastInsertId();
                            $msg = "Role '$name' created.";
                        } else {
                            $pdo->prepare("UPDATE permission_roles SET name=?, updated_at=NOW() WHERE id=?")->execute([$name, $id]);
                            $msg = "Role '$name' updated.";
                        }
                        $this->savePerms($pdo, $id, $request);
                        return redirect('/roles')->with('flash', ['type' => 'success', 'msg' => $msg]);
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => 'Could not save — a role with that name may already exist.'];
                    }
                }
            }

            if ($action === 'delete') {
                $id = (int) $request->input('id', 0);
                if ($id) {
                    $pdo->prepare("DELETE FROM role_permissions WHERE role_id=?")->execute([$id]);
                    $pdo->prepare("DELETE FROM permission_roles WHERE id=?")->execute([$id]);
                    $pdo->prepare("UPDATE users SET role_id=NULL WHERE role_id=?")->execute([$id]);
                    return redirect('/roles')->with('flash', ['type' => 'success', 'msg' => 'Role deleted.']);
                }
            }
        }

        $roles = $pdo->query("SELECT * FROM permission_roles ORDER BY name")->fetchAll();

        $rolePerms = [];
        foreach ($pdo->query("SELECT role_id, page, can_view, can_add, can_edit, can_delete FROM role_permissions")->fetchAll() as $r) {
            $rolePerms[(int) $r['role_id']][$r['page']] = [
                'view'   => (int) $r['can_view'],
                'add'    => (int) $r['can_add'],
                'edit'   => (int) $r['can_edit'],
                'delete' => (int) $r['can_delete'],
            ];
        }

        $usage = [];
        foreach ($pdo->query("SELECT role_id, COUNT(*) c FROM users WHERE role_id IS NOT NULL GROUP BY role_id")->fetchAll() as $r) {
            $usage[(int) $r['role_id']] = (int) $r['c'];
        }

        $editRole = null;
        if ($request->has('edit')) {
            $eid = (int) $request->query('edit');
            foreach ($roles as $r) {
                if ((int) $r['id'] === $eid) { $editRole = $r; break; }
            }
        }

        return view('roles', compact('pageTitle', 'activePage', 'flash', 'roles', 'rolePerms', 'usage', 'editRole'));
    }

    private function savePerms(\PDO $pdo, int $roleId, Request $request): void
    {
        $pdo->prepare("DELETE FROM role_permissions WHERE role_id=?")->execute([$roleId]);
        $perm = (array) $request->input('perm', []);
        $ins  = $pdo->prepare("INSERT INTO role_permissions (role_id, page, can_view, can_add, can_edit, can_delete, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())");
        foreach (permissionPages() as $page => $meta) {
            $p    = (array) ($perm[$page] ?? []);
            $acts = $meta['actions'];
            $view = !empty($p['view']) ? 1 : 0;
            $add  = (in_array('add', $acts, true)    && !empty($p['add']))    ? 1 : 0;
            $edit = (in_array('edit', $acts, true)   && !empty($p['edit']))   ? 1 : 0;
            $del  = (in_array('delete', $acts, true) && !empty($p['delete'])) ? 1 : 0;
            if ($add || $edit || $del) $view = 1;
            if ($view || $add || $edit || $del) {
                $ins->execute([$roleId, $page, $view, $add, $edit, $del]);
            }
        }
    }
}
