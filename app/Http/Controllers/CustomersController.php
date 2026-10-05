<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Customer master (Admin → Customers): name, login ID, password and a
 * page × action permission grid per customer.
 */
class CustomersController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Customers';
        $activePage = 'customers';
        $pdo   = getDB();
        $flash = session('flash');

        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'toggle' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('customers', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' customers.');
            }

            if ($action === 'add' || $action === 'edit') {
                $id       = (int) $request->input('id', 0);
                $name     = trim((string) $request->input('name', ''));
                $loginId  = trim((string) $request->input('login_id', ''));
                $password = (string) $request->input('password', '');
                $confirm  = (string) $request->input('password_confirm', '');
                $shareRaw = trim((string) $request->input('share_percentage', ''));
                $share    = round((float) $shareRaw, 2);
                $isAdd    = $action === 'add';

                $dup = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE login_id = ? AND id <> ?");
                $dup->execute([$loginId, $id]);

                if ($name === '' || $loginId === '') {
                    $flash = ['type' => 'error', 'msg' => 'Name and ID are required.'];
                } elseif (!preg_match('/^[A-Za-z0-9._@-]{3,100}$/', $loginId)) {
                    $flash = ['type' => 'error', 'msg' => 'ID must be 3–100 characters: letters, numbers, . _ @ -'];
                } elseif (!is_numeric($shareRaw) || $share < 0 || $share > 100) {
                    $flash = ['type' => 'error', 'msg' => 'Share percentage must be a number between 0 and 100.'];
                } elseif ((int) $dup->fetchColumn() > 0) {
                    $flash = ['type' => 'error', 'msg' => "The ID '$loginId' is already taken by another customer."];
                } elseif (($isAdd || $password !== '') && strlen($password) < 6) {
                    $flash = ['type' => 'error', 'msg' => 'Password must be at least 6 characters.'];
                } elseif (($isAdd || $password !== '') && $password !== $confirm) {
                    $flash = ['type' => 'error', 'msg' => 'Passwords do not match.'];
                } else {
                    if ($isAdd) {
                        $pdo->prepare("INSERT INTO customers (name, login_id, share_percentage, password_hash, active, created_at, updated_at) VALUES (?,?,?,?,1,NOW(),NOW())")
                            ->execute([$name, $loginId, $share, password_hash($password, PASSWORD_BCRYPT)]);
                        $id  = (int) $pdo->lastInsertId();
                        $msg = "Customer '$name' added.";
                    } elseif ($password !== '') {
                        $pdo->prepare("UPDATE customers SET name=?, login_id=?, share_percentage=?, password_hash=?, updated_at=NOW() WHERE id=?")
                            ->execute([$name, $loginId, $share, password_hash($password, PASSWORD_BCRYPT), $id]);
                        $msg = "Customer '$name' updated. New password set.";
                    } else {
                        $pdo->prepare("UPDATE customers SET name=?, login_id=?, share_percentage=?, updated_at=NOW() WHERE id=?")
                            ->execute([$name, $loginId, $share, $id]);
                        $msg = "Customer '$name' updated.";
                    }
                    $this->savePerms($pdo, $id, $request);
                    return redirect('/customers')->with('flash', ['type' => 'success', 'msg' => $msg]);
                }
            }

            if ($action === 'toggle') {
                $id = (int) $request->input('id', 0);
                if ($id > 0) {
                    $pdo->prepare("UPDATE customers SET active = 1 - active, updated_at=NOW() WHERE id = ?")->execute([$id]);
                    return redirect('/customers')->with('flash', ['type' => 'success', 'msg' => 'Customer status toggled.']);
                }
            }

            if ($action === 'delete') {
                $id = (int) $request->input('id', 0);
                if ($id > 0) {
                    $pdo->prepare("DELETE FROM customer_permissions WHERE customer_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
                    $pdo->prepare("UPDATE links SET customer_id = NULL WHERE customer_id = ?")->execute([$id]);
                    return redirect('/customers')->with('flash', ['type' => 'success', 'msg' => 'Customer deleted.']);
                }
            }
        }

        $customers = $pdo->query("SELECT * FROM customers ORDER BY name")->fetchAll();

        $customerPerms = [];
        foreach ($pdo->query("SELECT customer_id, page, can_view, can_add, can_edit, can_delete FROM customer_permissions")->fetchAll() as $r) {
            $customerPerms[(int) $r['customer_id']][$r['page']] = [
                'view'   => (int) $r['can_view'],
                'add'    => (int) $r['can_add'],
                'edit'   => (int) $r['can_edit'],
                'delete' => (int) $r['can_delete'],
            ];
        }

        $editCustomer = null;
        if ($request->has('edit')) {
            $eid = (int) $request->query('edit');
            foreach ($customers as $c) {
                if ((int) $c['id'] === $eid) { $editCustomer = $c; break; }
            }
        }

        return view('customers', compact('pageTitle', 'activePage', 'flash', 'customers', 'customerPerms', 'editCustomer'));
    }

    private function savePerms(\PDO $pdo, int $customerId, Request $request): void
    {
        $pdo->prepare("DELETE FROM customer_permissions WHERE customer_id=?")->execute([$customerId]);
        $perm = (array) $request->input('perm', []);
        $ins  = $pdo->prepare("INSERT INTO customer_permissions (customer_id, page, can_view, can_add, can_edit, can_delete, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())");
        foreach (permissionPages() as $page => $meta) {
            $p    = (array) ($perm[$page] ?? []);
            $acts = $meta['actions'];
            $view = !empty($p['view']) ? 1 : 0;
            $add  = (in_array('add', $acts, true)    && !empty($p['add']))    ? 1 : 0;
            $edit = (in_array('edit', $acts, true)   && !empty($p['edit']))   ? 1 : 0;
            $del  = (in_array('delete', $acts, true) && !empty($p['delete'])) ? 1 : 0;
            if ($add || $edit || $del) $view = 1;
            if ($view || $add || $edit || $del) {
                $ins->execute([$customerId, $page, $view, $add, $edit, $del]);
            }
        }
    }
}
