<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of accounts.php — Meta account credential vault (admin only).
 */
class AccountsController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Meta Accounts';
        $activePage = 'accounts';
        $pdo   = getDB();
        $flash = session('flash'); // e.g. from a /meta_sync redirect

        $hasMetaApi = in_array('owner_user_id', array_column($pdo->query("SHOW COLUMNS FROM meta_accounts")->fetchAll(), 'Field'));
        // Editing the Meta API fields (and running the sync from here) needs Accounts edit.
        $metaEdit   = $hasMetaApi && userCan('accounts', 'edit');

        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'toggle' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('accounts', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' accounts.');
            }

            if ($action === 'add') {
                $d = $this->sanitizeAccount($request);
                if (!$d['name']) {
                    $flash = ['type' => 'error', 'msg' => 'Name is required.'];
                } else {
                    $cols = array_keys($d);
                    $vals = array_values($d);
                    if ($metaEdit) {
                        $cols[] = 'owner_user_id'; $vals[] = currentUserId();
                        $cols[] = 'api_token';     $vals[] = trim((string) $request->input('api_token', ''));
                    }
                    $cols[] = 'created_at'; $vals[] = now();
                    $cols[] = 'updated_at'; $vals[] = now();
                    $colSql = '`' . implode('`,`', $cols) . '`';
                    $phSql  = implode(',', array_fill(0, count($vals), '?'));
                    $pdo->prepare("INSERT INTO meta_accounts ($colSql) VALUES ($phSql)")->execute($vals);
                    $flash = ['type' => 'success', 'msg' => "Account '{$d['name']}' added."];
                }
            }

            if ($action === 'edit') {
                $id = (int) $request->input('id', 0);
                $d  = $this->sanitizeAccount($request);
                if ($id && $d['name']) {
                    $sets = []; $vals = [];
                    foreach ($d as $k => $v) { $sets[] = "`$k`=?"; $vals[] = $v; }
                    if ($metaEdit) {
                        $sets[] = "`owner_user_id` = IF(`owner_user_id` > 0, `owner_user_id`, ?)"; $vals[] = currentUserId();
                        $tok = trim((string) $request->input('api_token', ''));
                        if ($tok !== '') { $sets[] = "`api_token`=?"; $vals[] = $tok; }
                    }
                    $sets[] = "`updated_at`=?"; $vals[] = now();
                    $vals[] = $id;
                    $pdo->prepare("UPDATE meta_accounts SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
                    $flash = ['type' => 'success', 'msg' => 'Account updated.'];
                }
            }

            if ($action === 'delete') {
                $id = (int) $request->input('id', 0);
                if ($id) {
                    $pdo->prepare("DELETE FROM meta_accounts WHERE id=?")->execute([$id]);
                    $flash = ['type' => 'success', 'msg' => 'Account deleted.'];
                }
            }

            if ($action === 'toggle') {
                $id = (int) $request->input('id', 0);
                if ($id) {
                    $pdo->prepare("UPDATE meta_accounts SET active = 1 - active WHERE id=?")->execute([$id]);
                    $flash = ['type' => 'success', 'msg' => 'Status toggled.'];
                }
            }
        }

        $search   = trim((string) $request->query('q', ''));
        $accounts = $pdo->query("SELECT * FROM meta_accounts ORDER BY name, id")->fetchAll();
        if ($search) {
            $accounts = array_filter($accounts, fn ($a) =>
                stripos($a['name'] . $a['username'] . $a['email'] . $a['bm'] . $a['ad_account_ids'], $search) !== false
            );
        }
        $accounts = array_values($accounts);

        $editAcc = null;
        if ($request->has('edit')) {
            $eid = (int) $request->query('edit');
            foreach ($accounts as $a) { if ($a['id'] == $eid) { $editAcc = $a; break; } }
            if (!$editAcc) {
                $s = $pdo->prepare("SELECT * FROM meta_accounts WHERE id=?");
                $s->execute([$eid]); $editAcc = $s->fetch() ?: null;
            }
        }

        return view('accounts', compact('pageTitle', 'activePage', 'flash', 'metaEdit', 'accounts', 'editAcc', 'search'));
    }

    private function normTags(string $raw): string
    {
        return implode(',', array_values(array_filter(array_map('trim', explode(',', $raw)))));
    }

    private function sanitizeAccount(Request $request): array
    {
        return [
            'name'              => trim((string) $request->input('name', '')),
            'username'          => trim((string) $request->input('username', '')),
            'password'          => trim((string) $request->input('password', '')),
            'auth'              => trim((string) $request->input('auth', '')),
            'email'             => trim((string) $request->input('email', '')),
            'email_password'    => trim((string) $request->input('email_password', '')),
            'recovery_email'    => trim((string) $request->input('recovery_email', '')),
            'recovery_password' => trim((string) $request->input('recovery_password', '')),
            'bm'                => $this->normTags((string) $request->input('bm', '')),
            'ad_account_ids'    => $this->normTags((string) $request->input('ad_account_ids', '')),
            'page_url'          => trim((string) $request->input('page_url', '')),
            'notes'             => trim((string) $request->input('notes', '')),
        ];
    }
}
