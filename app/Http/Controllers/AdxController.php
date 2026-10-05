<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of adx.php — ADX Master. Each ADX network doubles as a GAM account for
 * the API sync. Admin only.
 */
class AdxController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'ADX Master';
        $activePage = 'adx';
        $pdo   = getDB();
        $flash = session('flash'); // e.g. from a /gam_sync redirect

        // GAM API fields exist (migration 031 baked into schema).
        $hasGam  = in_array('network_code', array_column($pdo->query("SHOW COLUMNS FROM `adx`")->fetchAll(), 'Field'), true);
        // Editing the GAM API fields (and running the sync from here) needs ADX edit.
        $gamEdit = $hasGam && userCan('adx', 'edit');

        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'toggle' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('adx', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' ADX networks.');
            }

            if ($action === 'add') {
                $name   = trim((string) $request->input('name', ''));
                $notes  = trim((string) $request->input('notes', ''));
                $adsTxt = (string) $request->input('ads_txt', '');
                if (!$name) {
                    $flash = ['type' => 'error', 'msg' => 'ADX name is required.'];
                } else {
                    try {
                        if ($gamEdit) {
                            $g = $this->gamFields($request);
                            $pdo->prepare("INSERT INTO adx (name, notes, ads_txt, network_code, adx_prefix, key_file, saved_report_id, hourly_report_id, auto_ad_unit, line_item_id, gam_currency, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
                                ->execute([$name, $notes, $adsTxt, $g['network_code'], $g['adx_prefix'], $g['key_file'], $g['saved_report_id'], $g['hourly_report_id'], $g['auto_ad_unit'], $g['line_item_id'], $g['gam_currency']]);
                        } else {
                            $pdo->prepare("INSERT INTO adx (name, notes, ads_txt, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())")->execute([$name, $notes, $adsTxt]);
                        }
                        $flash = ['type' => 'success', 'msg' => "ADX '$name' added."];
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
                    }
                }
            }

            if ($action === 'edit') {
                $id     = (int) $request->input('id', 0);
                $name   = trim((string) $request->input('name', ''));
                $notes  = trim((string) $request->input('notes', ''));
                $adsTxt = (string) $request->input('ads_txt', '');
                if ($id && $name) {
                    try {
                        if ($gamEdit) {
                            $g = $this->gamFields($request);
                            $pdo->prepare("UPDATE adx SET name=?, notes=?, ads_txt=?, network_code=?, adx_prefix=?, key_file=?, saved_report_id=?, hourly_report_id=?, auto_ad_unit=?, line_item_id=?, gam_currency=?, updated_at=NOW() WHERE id=?")
                                ->execute([$name, $notes, $adsTxt, $g['network_code'], $g['adx_prefix'], $g['key_file'], $g['saved_report_id'], $g['hourly_report_id'], $g['auto_ad_unit'], $g['line_item_id'], $g['gam_currency'], $id]);
                        } else {
                            $pdo->prepare("UPDATE adx SET name=?, notes=?, ads_txt=?, updated_at=NOW() WHERE id=?")->execute([$name, $notes, $adsTxt, $id]);
                        }
                        $flash = ['type' => 'success', 'msg' => 'ADX updated.'];
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
                    }
                }
            }

            if ($action === 'toggle') {
                $id = (int) $request->input('id', 0);
                if ($id > 0) {
                    $pdo->prepare("UPDATE adx SET active = 1 - active WHERE id=?")->execute([$id]);
                    $flash = ['type' => 'success', 'msg' => 'Status toggled.'];
                }
            }

            if ($action === 'delete') {
                $id = (int) $request->input('id', 0);
                if ($id > 0) {
                    $used = (int) $pdo->query("SELECT COUNT(*) FROM domains WHERE adx_id=$id")->fetchColumn();
                    if ($used > 0) {
                        $flash = ['type' => 'error', 'msg' => "Cannot delete: $used domain(s) still use this ADX. Re-assign them first."];
                    } else {
                        $pdo->prepare("DELETE FROM adx WHERE id=?")->execute([$id]);
                        $flash = ['type' => 'success', 'msg' => 'ADX deleted.'];
                    }
                }
            }
        }

        $adxList = $pdo->query("SELECT a.*, (SELECT COUNT(*) FROM domains WHERE adx_id=a.id) AS link_count FROM adx a ORDER BY a.name")->fetchAll();

        $editAdx = null;
        if ($request->has('edit')) {
            $eid = (int) $request->query('edit');
            foreach ($adxList as $a) {
                if ($a['id'] == $eid) { $editAdx = $a; break; }
            }
        }

        return view('adx', compact('pageTitle', 'activePage', 'flash', 'hasGam', 'gamEdit', 'adxList', 'editAdx'));
    }

    /** Read POST GAM fields; a newly uploaded key overrides the path. */
    private function gamFields(Request $request): array
    {
        $keyFile = trim((string) $request->input('key_file', ''));
        if ($request->hasFile('key_upload')) {
            $keyFile = $this->saveGamKey($request->file('key_upload'));
        }
        $networkCode = trim((string) $request->input('network_code', ''));
        // ADX prefix defaults to the network code when left blank.
        $adxPrefix   = trim((string) $request->input('adx_prefix', ''));
        if ($adxPrefix === '') $adxPrefix = $networkCode;

        return [
            'network_code'     => $networkCode,
            'adx_prefix'       => $adxPrefix,
            'key_file'         => $keyFile,
            'saved_report_id'  => trim((string) $request->input('saved_report_id', '')),
            'hourly_report_id' => trim((string) $request->input('hourly_report_id', '')),
            'auto_ad_unit'     => $request->boolean('auto_ad_unit') ? 1 : 0,
            'line_item_id'     => trim((string) $request->input('line_item_id', '')),
            'gam_currency'     => strtoupper(trim((string) $request->input('gam_currency', 'USD'))) ?: 'USD',
        ];
    }

    /** Store an uploaded service-account JSON key in a protected dir; return its path. */
    private function saveGamKey($file): string
    {
        if (!$file || !$file->isValid()) throw new \Exception('Key upload failed.');
        if ($file->getSize() > 64 * 1024) throw new \Exception('Key file too large (max 64 KB).');
        $json = json_decode((string) file_get_contents($file->getRealPath()), true);
        if (empty($json['client_email']) || empty($json['private_key'])) {
            throw new \Exception('Not a valid service-account JSON key (missing client_email / private_key).');
        }
        $dir = storage_path('app/private/gam_keys');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new \Exception('Could not create key directory.');
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.html', '');
        $dest = 'gam_' . bin2hex(random_bytes(8)) . '.json';
        $file->move($dir, $dest);
        @chmod($dir . '/' . $dest, 0600);
        return $dir . '/' . $dest;
    }
}
