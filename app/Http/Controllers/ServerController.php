<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Server Master — deployment targets (SSH + aaPanel credentials). Admin only.
 * The SSH password and panel key are encrypted at rest; on edit they are left
 * blank and only overwritten when a new value is entered.
 */
class ServerController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Server-Masters';
        $activePage = 'server-masters';
        $pdo   = getDB();
        $flash = session('flash');

        // Diagnostic: /server-masters?diag_categories=<serverId> — dumps the panel's
        // site-category list (before/after resolving "sub"/"main") as plain text.
        if ($request->has('diag_categories')) {
            if (!userCan('server_masters', 'edit')) abort(403, 'You do not have permission to edit servers.');
            $s = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
            $s->execute([(int) $request->query('diag_categories')]);
            $server = $s->fetch();
            if (!$server) {
                return response("Server not found.\n", 404)->header('Content-Type', 'text/plain; charset=utf-8');
            }
            try {
                $svc = new \App\Services\AapanelService(serverConfig($server));
                $dump = $svc->debugCategories(
                    trim((string) $request->query('site', '')) ?: null,
                    trim((string) $request->query('cat', 'sub')) ?: 'sub'
                );
            } catch (\Throwable $e) {
                $dump = ['fatal' => $e->getMessage()];
            }
            return response(
                "=== {$server['name']} ({$server['bt_panel_url']}) ===\n" .
                json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                200
            )->header('Content-Type', 'text/plain; charset=utf-8');
        }

        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'categorize' => 'edit', 'save_namecom' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('server_masters', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' servers.');
            }

            // Backfill aaPanel site categories for every site deployed to this server,
            // using the role recorded at deploy time (server_sites.kind: main/sub).
            if ($action === 'categorize') {
                $sid = (int) $request->input('id', 0);
                $srv = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
                $srv->execute([$sid]);
                $server = $srv->fetch();
                if (!$server) {
                    return redirect('/server-masters')->with('flash', ['type' => 'error', 'msg' => 'Server not found.']);
                }

                $rows = $pdo->prepare("SELECT host, kind FROM server_sites WHERE server_id = ?");
                $rows->execute([$sid]);
                $sites = $rows->fetchAll();
                if (!$sites) {
                    return redirect('/server-masters')->with('flash', ['type' => 'error', 'msg' => "No deployed sites recorded for '{$server['name']}'."]);
                }

                try {
                    $svc = new \App\Services\AapanelService(serverConfig($server));
                } catch (\Throwable $e) {
                    return redirect('/server-masters')->with('flash', ['type' => 'error', 'msg' => 'Panel connection failed: ' . $e->getMessage()]);
                }

                @set_time_limit(0);
                $main = $sub = $skipped = $failed = 0; $errs = [];
                foreach ($sites as $row) {
                    $host = trim((string) $row['host']);
                    $kind = strtolower(trim((string) $row['kind']));
                    if ($host === '' || !in_array($kind, ['main', 'sub'], true)) { $skipped++; continue; }
                    $r = $svc->setCategory($host, $kind);
                    if (!empty($r['ok'])) { $kind === 'main' ? $main++ : $sub++; }
                    else { $failed++; if (count($errs) < 3) $errs[] = "{$host}: " . ($r['error'] ?? 'failed'); }
                }
                $msg = "Categorised '{$server['name']}': {$main} → Main, {$sub} → Sub"
                     . ($skipped ? ", {$skipped} skipped" : '')
                     . ($failed ? ", {$failed} failed" . ($errs ? ' (' . implode('; ', $errs) . ')' : '') : '') . '.';
                return redirect('/server-masters')->with('flash', ['type' => $failed ? 'error' : 'success', 'msg' => $msg]);
            }

            if ($action === 'add' || $action === 'edit') {
                $id         = (int) $request->input('id', 0);
                $name       = trim((string) $request->input('name', ''));
                $sshHost    = trim((string) $request->input('ssh_host', ''));
                $sshPort    = (int) $request->input('ssh_port', 22) ?: 22;
                $sshUser    = trim((string) $request->input('ssh_username', ''));
                $sshPass    = (string) $request->input('ssh_password', '');
                $panelUrl   = trim((string) $request->input('bt_panel_url', ''));
                $panelKey   = (string) $request->input('bt_panel_key', '');
                $phpVersion = trim((string) $request->input('bt_panel_php_version', '74')) ?: '74';
                $active     = $request->input('active') ? 1 : 0;

                if ($name === '' || $sshHost === '' || $sshUser === '' || $panelUrl === '') {
                    $flash = ['type' => 'error', 'msg' => 'Name, SSH host, SSH username and panel URL are required.'];
                } elseif ($action === 'add' && ($sshPass === '' || $panelKey === '')) {
                    $flash = ['type' => 'error', 'msg' => 'SSH password and panel key are required.'];
                } else {
                    try {
                        if ($action === 'add') {
                            $pdo->prepare(
                                "INSERT INTO servers (name, ssh_host, ssh_port, ssh_username, ssh_password, bt_panel_url, bt_panel_key, bt_panel_php_version, active, created_at, updated_at)
                                 VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())"
                            )->execute([
                                $name, $sshHost, $sshPort, $sshUser,
                                Crypt::encryptString($sshPass), $panelUrl,
                                Crypt::encryptString($panelKey), $phpVersion, $active,
                            ]);
                            $msg = "Server '$name' created.";
                        } else {
                            // Only overwrite secrets when a new value was entered.
                            $sets   = ['name=?', 'ssh_host=?', 'ssh_port=?', 'ssh_username=?', 'bt_panel_url=?', 'bt_panel_php_version=?', 'active=?'];
                            $params = [$name, $sshHost, $sshPort, $sshUser, $panelUrl, $phpVersion, $active];
                            if ($sshPass !== '') { $sets[] = 'ssh_password=?'; $params[] = Crypt::encryptString($sshPass); }
                            if ($panelKey !== '') { $sets[] = 'bt_panel_key=?'; $params[] = Crypt::encryptString($panelKey); }
                            $sets[] = 'updated_at=NOW()';
                            $params[] = $id;
                            $pdo->prepare("UPDATE servers SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
                            $msg = "Server '$name' updated.";
                        }
                        return redirect('/server-masters')->with('flash', ['type' => 'success', 'msg' => $msg]);
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => 'Could not save the server: ' . $e->getMessage()];
                    }
                }
            }

            if ($action === 'delete') {
                $id = (int) $request->input('id', 0);
                if ($id) {
                    $pdo->prepare("DELETE FROM servers WHERE id=?")->execute([$id]);
                    return redirect('/server-masters')->with('flash', ['type' => 'success', 'msg' => 'Server deleted.']);
                }
            }

            // name.com DNS credentials (global) — used to auto-create A records on deploy.
            if ($action === 'save_namecom') {
                $user  = trim((string) $request->input('namecom_username', ''));
                $token = (string) $request->input('namecom_token', '');
                $this->putSetting($pdo, 'namecom_username', $user, 'name.com API username');
                if ($token !== '') {   // only overwrite the token when a new one is entered
                    $this->putSetting($pdo, 'namecom_token', Crypt::encryptString($token), 'name.com API token (encrypted)');
                }
                return redirect('/server-masters')->with('flash', ['type' => 'success', 'msg' => 'name.com credentials saved.']);
            }
        }

        $servers = $pdo->query("SELECT * FROM servers ORDER BY name")->fetchAll();

        // name.com credentials for the form (token shown only as "set / not set").
        $namecomUser = getSetting('namecom_username', '');
        $namecomHasToken = getSetting('namecom_token', '') !== '';

        // Per-server deployed-site counts, for display.
        $siteCounts = [];
        foreach ($pdo->query("SELECT server_id, COUNT(*) c FROM server_sites GROUP BY server_id")->fetchAll() as $r) {
            $siteCounts[(int) $r['server_id']] = (int) $r['c'];
        }

        $editServer = null;
        if ($request->has('edit')) {
            $eid = (int) $request->query('edit');
            foreach ($servers as $s) {
                if ((int) $s['id'] === $eid) { $editServer = $s; break; }
            }
        }

        return view('server', compact('pageTitle', 'activePage', 'flash', 'servers', 'siteCounts', 'editServer',
            'namecomUser', 'namecomHasToken'));
    }

    /** Upsert a key/value into the settings table. */
    private function putSetting(\PDO $pdo, string $key, string $value, string $label = ''): void
    {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, label, created_at, updated_at)
                       VALUES (?,?,?,NOW(),NOW())
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
            ->execute([$key, $value, $label]);
    }
}
