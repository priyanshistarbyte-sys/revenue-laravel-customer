<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Main domains (the parent that groups subdomains/links). Split out from the
 * original combined links.php page. Admin only. Uses PRG on write.
 */
class DomainsController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Domains';
        $activePage = 'domains';
        $pdo = getDB();

        $adxOptions = $pdo->query("SELECT id, name FROM adx WHERE active=1 ORDER BY name")->fetchAll();
        $flash = session('flash');

        if ($request->isMethod('post')) {
            $redirect = $this->handlePost($request, $pdo, $flash);
            if ($redirect) return $redirect;
        }

        // ── Filters ── (domains are shared — no per-owner filter)
        $fAdx      = trim((string) $request->query('f_adx', ''));
        $fApproved = trim((string) $request->query('f_approved', ''));   // '' = all statuses
        $fSub      = $request->query('f_sub') === 'none';
        $fQ        = trim((string) $request->query('f_q', ''));
        $filterActive = ($fAdx !== '' || $fApproved !== '' || $fSub || $fQ !== '');

        $dWhere  = ['1=1'];
        $dParams = [];
        if ($fAdx !== '')      { $dWhere[] = 'd.adx_id = ?';   $dParams[] = (int) $fAdx; }
        if ($fApproved !== '') { $dWhere[] = 'd.approved = ?'; $dParams[] = (int) $fApproved; }
        if ($fSub)             { $dWhere[] = 'NOT EXISTS (SELECT 1 FROM links l2 WHERE l2.domain_id = d.id)'; }
        if ($fQ !== '')        { $dWhere[] = 'd.name LIKE ?';  $dParams[] = '%' . $fQ . '%'; }

        $domStmt = $pdo->prepare("SELECT d.*, a.name AS adx_name,
                                         a.network_code AS adx_network, a.key_file AS adx_key,
                                         (SELECT COUNT(*) FROM links l WHERE l.domain_id = d.id) AS link_count
                                  FROM domains d
                                  LEFT JOIN adx a ON a.id=d.adx_id
                                  WHERE " . implode(' AND ', $dWhere) . " ORDER BY d.name");
        $domStmt->execute($dParams);
        $domains = $domStmt->fetchAll();

        // ── Edit prefill ──
        $editDomain = null;
        if ($request->has('edit')) {
            $eStmt = $pdo->prepare("SELECT * FROM domains WHERE id=?");
            $eStmt->execute([(int) $request->query('edit')]);
            $editDomain = $eStmt->fetch() ?: null;
        }

        return view('domains', compact(
            'pageTitle', 'activePage', 'flash', 'adxOptions', 'domains', 'editDomain',
            'fAdx', 'fApproved', 'fSub', 'fQ', 'filterActive'
        ));
    }

    private function handlePost(Request $request, \PDO $pdo, &$flash)
    {
        $action = $request->input('action', '');

        $need = ['add_domain' => 'add', 'edit_domain' => 'edit', 'toggle_approved' => 'edit', 'delete_domain' => 'delete',
                 'create_ad_unit' => 'edit', 'sync_sites' => 'edit'];
        if (isset($need[$action]) && !userCan('domains', $need[$action])) {
            abort(403, 'You do not have permission to ' . $need[$action] . ' domains.');
        }

        if ($action === 'add_domain') {
            $rawName = trim((string) $request->input('name', ''));
            $notes   = trim((string) $request->input('notes', ''));
            $adxId   = (int) $request->input('adx_id', 0) ?: null;

            // A comma-separated list adds one domain per entry.
            $names = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $rawName)),
                fn ($n) => $n !== ''
            )));

            if (empty($names)) {
                $flash = ['type' => 'error', 'msg' => 'Domain name is required.'];
            } else {
                $autoAdx = false;
                if ($adxId) {
                    $c = $pdo->prepare("SELECT auto_ad_unit FROM adx WHERE id=?");
                    $c->execute([$adxId]);
                    $autoAdx = ((int) $c->fetchColumn() === 1);
                }

                $ins    = $pdo->prepare("INSERT INTO domains (name, adx_id, notes, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())");
                $dupChk = $pdo->prepare("SELECT COUNT(*) FROM domains WHERE LOWER(name)=LOWER(?)");
                $added = []; $skipped = []; $unitNotes = [];

                foreach ($names as $name) {
                    $dupChk->execute([$name]);
                    if ((int) $dupChk->fetchColumn() > 0) { $skipped[] = $name; continue; }
                    $ins->execute([$name, $adxId, $notes]);
                    $newId = (int) $pdo->lastInsertId();
                    $added[] = $name;
                    if ($autoAdx) {
                        $au = $this->createAdUnitsFor($pdo, $newId);
                        if (!$au['ok']) $unitNotes[] = "$name — " . $au['msg'];
                    }
                }

                $parts = [];
                if ($added)     $parts[] = count($added) . ' domain(s) added (' . implode(', ', $added) . ')';
                if ($skipped)   $parts[] = count($skipped) . ' skipped as duplicate (' . implode(', ', $skipped) . ')';
                if ($unitNotes) $parts[] = 'Ad unit issues: ' . implode(' | ', $unitNotes);

                return redirect('/domains')->with('flash', [
                    'type' => $added ? 'success' : 'error',
                    'msg'  => ($parts ? implode('. ', $parts) . '.' : 'Nothing added.'),
                ]);
            }
        }

        if ($action === 'edit_domain') {
            $id      = (int) $request->input('id', 0);
            $name    = trim((string) $request->input('name', ''));
            $notes   = trim((string) $request->input('notes', ''));
            $adxId   = (int) $request->input('adx_id', 0) ?: null;
            if ($id && $name) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM domains WHERE LOWER(name)=LOWER(?) AND id<>?");
                $chk->execute([$name, $id]);
                if ((int) $chk->fetchColumn() > 0) {
                    $flash = ['type' => 'error', 'msg' => "Another main domain named '$name' already exists — not renamed."];
                } else {
                    $pdo->prepare("UPDATE domains SET name=?, adx_id=?, notes=?, updated_at=NOW() WHERE id=?")
                        ->execute([$name, $adxId, $notes, $id]);
                    return redirect('/domains')->with('flash', ['type' => 'success', 'msg' => 'Domain updated.']);
                }
            }
        }

        if ($action === 'toggle_approved') {
            $id = (int) $request->input('id', 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE domains SET approved = 1 - approved WHERE id=?")->execute([$id]);
                return redirect('/domains')->with('flash', ['type' => 'success', 'msg' => 'Domain approval toggled.']);
            }
        }

        if ($action === 'create_ad_unit') {
            $id = (int) $request->input('id', 0);
            if ($id > 0) {
                $res = $this->createAdUnitsFor($pdo, $id);
                return redirect('/domains')->with('flash', [
                    'type' => $res['ok'] ? 'success' : 'error',
                    'msg'  => ($res['ok'] ? '' : 'Ad units failed: ') . $res['msg'],
                ]);
            }
        }

        if ($action === 'sync_sites') {
            \Illuminate\Support\Facades\Artisan::call('domains:sync-sites');
            $lines   = array_values(array_filter(array_map('trim', explode("\n", \Illuminate\Support\Facades\Artisan::output()))));
            $summary = end($lines) ?: 'Done.';
            return redirect('/domains')->with('flash', ['type' => 'success', 'msg' => 'GAM site ID sync — ' . $summary]);
        }

        if ($action === 'delete_domain') {
            $id = (int) $request->input('id', 0);
            if ($id > 0) {
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE domain_id=?");
                $cnt->execute([$id]);
                if ((int) $cnt->fetchColumn() > 0) {
                    return redirect('/domains')->with('flash', ['type' => 'error', 'msg' => 'Cannot delete: this domain still has subdomains. Move or delete them first.']);
                }
                $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$id]);
                return redirect('/domains')->with('flash', ['type' => 'success', 'msg' => 'Domain deleted.']);
            }
        }

        return null;
    }

    /**
     * Create the GAM ad units for a domain and persist the result. Shared by the
     * "Create" button and the auto-create on add. Returns the service result.
     */
    private function createAdUnitsFor(\PDO $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT d.*, a.network_code AS adx_network, a.key_file AS adx_key,
                                      a.name AS adx_name, a.line_item_id AS adx_line_item
                               FROM domains d LEFT JOIN adx a ON a.id=d.adx_id WHERE d.id=?");
        $stmt->execute([$domainId]);
        $dom = $stmt->fetch();
        if (!$dom) return ['ok' => false, 'msg' => 'Domain not found.'];
        if (empty($dom['adx_id'])) return ['ok' => false, 'msg' => "Assign an ADX network to '{$dom['name']}' first."];

        $res = (new \App\Services\GamAdUnit())->createForDomain($dom, [
            'network_code' => $dom['adx_network'], 'key_file' => $dom['adx_key'],
            'line_item_id' => $dom['adx_line_item'],
        ]);

        if ($res['ok']) {
            $pdo->prepare("UPDATE domains SET gam_ad_unit_id=?, gam_ad_unit_code=?, gam_site_id=?, gam_ad_unit_synced_at=NOW(), gam_ad_unit_error=NULL WHERE id=?")
                ->execute([$res['ad_unit_id'], $res['ad_unit_code'] ?? null, $res['site_id'] ?? null, $domainId]);
        } else {
            $pdo->prepare("UPDATE domains SET gam_ad_unit_error=? WHERE id=?")->execute([mb_substr($res['msg'], 0, 500), $domainId]);
        }
        return $res;
    }
}
