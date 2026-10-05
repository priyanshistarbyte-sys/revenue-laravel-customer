<?php

namespace App\Http\Controllers;

use App\Services\DeploymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Subdomains (links) — maps Meta campaigns to GAM sites, optionally grouped
 * under a main Domain (managed on the Domains page). Includes duplicate
 * (double-counting) detection. Admin only. Uses PRG on write.
 */
class LinksController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Links';
        $activePage = 'links';
        $pdo = getDB();

        $flash = session('flash');

        if ($request->isMethod('post')) {
            $redirect = $this->handlePost($request, $pdo, $flash);
            if ($redirect) return $redirect;
        }

        // Domains for the picker / filter (shared across users).
        $domains    = $pdo->query("SELECT * FROM domains ORDER BY name")->fetchAll();
        $adxOptions = $pdo->query("SELECT id, name FROM adx WHERE active=1 ORDER BY name")->fetchAll();
        $customerOptions = $pdo->query("SELECT id, name, login_id, active FROM customers ORDER BY name")->fetchAll();

        // ── Filters ──
        $fUser   = trim((string) $request->query('f_user', ''));
        $fDomain = $request->query('f_domain', '');           // '' all | '0' ungrouped | id
        $fAdx    = trim((string) $request->query('f_adx', ''));
        // Defaults to Active - the list is about live subdomains; picking "All status"
        // in the filter bar submits an empty f_status, which turns the default off.
        $fStatus = $request->query('f_status', '1');          // '' all | '1' active | '0' paused
        $statusDefaulted = !$request->has('f_status');
        $fQ      = trim((string) $request->query('f_q', ''));
        $personFilter = ($fUser !== '' && canSeeAllUsers());
        $domainFilter = ($fDomain !== '' && $fDomain !== null);
        $adxFilter    = ($fAdx !== '');
        $statusFilter = ($fStatus === '0' || $fStatus === '1');
        $searchActive = ($fQ !== '');
        // The default Active filter isn't "filtering" as far as the badge / Clear link
        // are concerned - only a status the user actually picked counts.
        $filterActive = ($personFilter || $domainFilter || $adxFilter || $searchActive
                         || ($statusFilter && !$statusDefaulted));

        $lWhere  = [linkScopeWhere('l', 'd')];
        $lParams = [];
        if ($personFilter) { $lWhere[] = 'l.user_id = ?'; $lParams[] = (int) $fUser; }
        if ($statusFilter) { $lWhere[] = 'l.active = ?'; $lParams[] = (int) $fStatus; }
        if ($domainFilter) {
            $lWhere[] = 'COALESCE(l.domain_id, 0) = ?';
            $lParams[] = (int) $fDomain;
        }
        if ($adxFilter) {
            // A link's effective ADX is its domain's ADX, else the link's own.
            $lWhere[] = 'COALESCE(d.adx_id, l.adx_id) = ?';
            $lParams[] = (int) $fAdx;
        }
        if ($searchActive) {
            $lWhere[] = '(l.link_name LIKE ? OR l.meta_campaign LIKE ? OR l.meta_url LIKE ? OR l.gam_url LIKE ?
                          OR EXISTS (SELECT 1 FROM domains dd WHERE dd.id = l.domain_id AND dd.name LIKE ?))';
            $like = '%' . $fQ . '%';
            array_push($lParams, $like, $like, $like, $like, $like);
        }
        // ── Sorting (whitelisted column + direction; default groups by domain) ──
        $sort = (string) $request->query('sort', '');
        $dir  = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $sortMap = [
            'user'   => 'u.name',
            'date'   => 'l.created_at',
            'camp'   => 'l.meta_campaign',
            'meta'   => 'l.meta_url',
            'gam'    => 'l.gam_url',
            'adx'    => 'ax.name',
            'status' => 'l.active',
        ];
        $orderBy = isset($sortMap[$sort])
            ? "{$sortMap[$sort]} $dir, l.link_name"
            : 'd.name IS NULL, d.name, l.link_name';

        $linkStmt = $pdo->prepare("SELECT l.*, u.name AS owner_name, d.name AS domain_name,
                                          ax.name AS adx_name
                                   FROM links l
                                   LEFT JOIN users u ON u.id=l.user_id
                                   LEFT JOIN domains d ON d.id=l.domain_id
                                   LEFT JOIN adx ax ON ax.id = COALESCE(d.adx_id, l.adx_id)
                                   WHERE " . implode(' AND ', $lWhere) . " ORDER BY $orderBy");
        $linkStmt->execute($lParams);
        $links = $linkStmt->fetchAll();

        // ── Duplicate detection (whole scope, filter-independent; deactivated links ignored) ──
        $allLinks = $pdo->query("SELECT id, user_id, link_name, meta_campaign, gam_url
                                 FROM links WHERE active = 1 AND " . scopeSQL('user_id'))->fetchAll();
        $campUse = []; $gamUse = [];
        foreach ($allLinks as $al) {
            $uid = (int) $al['user_id'];
            foreach (array_filter(array_map('trim', explode(',', $al['meta_campaign']))) as $cp) {
                $campUse[$uid][$cp][$al['id']] = $al['link_name'];
            }
            foreach (array_filter(array_map('trim', explode(',', $al['gam_url']))) as $gs) {
                $gamUse[$uid][$gs][$al['id']] = $al['link_name'];
            }
        }
        $dupCampaigns = []; $dupGam = []; $dupCampSet = []; $dupGamSet = [];
        foreach ($campUse as $uid => $byCp) {
            foreach ($byCp as $cp => $lnks) {
                if (count($lnks) > 1) {
                    $dupCampaigns[] = ['owner' => userName($uid), 'value' => $cp, 'links' => array_values($lnks)];
                    $dupCampSet["$uid|$cp"] = true;
                }
            }
        }
        foreach ($gamUse as $uid => $byGs) {
            foreach ($byGs as $gs => $lnks) {
                if (count($lnks) > 1) {
                    $dupGam[] = ['owner' => userName($uid), 'value' => $gs, 'links' => array_values($lnks)];
                    $dupGamSet["$uid|$gs"] = true;
                }
            }
        }
        $hasDuplicates = !empty($dupCampaigns) || !empty($dupGam);

        // ── Edit prefill ──
        $editLink = null;
        if ($request->has('edit')) {
            $eStmt = $pdo->prepare("SELECT * FROM links WHERE id=? AND " . scopeSQL());
            $eStmt->execute([(int) $request->query('edit')]);
            $editLink = $eStmt->fetch() ?: null;
        }

        // Open the add modal with a domain preselected (from the Domains page).
        $addDomainId = (int) $request->query('domain', 0);
        $openAdd     = $request->has('add') || $addDomainId > 0;
        $openDeploy  = (int) $request->query('deploy', 0);
        // Only active zips (and their older versions) can be deployed.
        $zips = $pdo->query("SELECT * FROM zips WHERE active = 1 ORDER BY name")->fetchAll();

        // Archived older versions of each zip (for the deploy modal's "show older versions").
        try {
            $zipVersions = $pdo->query("SELECT zv.id, zv.zip_id, zv.name, z.name AS zip_name, z.types
                                        FROM zip_versions zv JOIN zips z ON z.id = zv.zip_id
                                        WHERE z.active = 1
                                        ORDER BY z.name, zv.id DESC")->fetchAll();
        } catch (\Throwable $e) {
            $zipVersions = [];
        }

        // Last zip each host was deployed with → "linkId|host" => [zip_id, zip_name],
        // shown as info text in the Deploy modal (not pre-selected).
        $deployZips = [];
        $linkIds = array_map(fn ($l) => (int) $l['id'], $links);
        if ($linkIds) {
            $ph  = implode(',', array_fill(0, count($linkIds), '?'));
            $dsS = $pdo->prepare("SELECT ds.link_id, ds.host, ds.zip_id, z.name AS zip_name
                                  FROM deploy_selections ds
                                  LEFT JOIN zips z ON z.id = ds.zip_id
                                  WHERE ds.link_id IN ($ph)");
            $dsS->execute($linkIds);
            foreach ($dsS->fetchAll() as $ds) {
                $deployZips[$ds['link_id'] . '|' . $ds['host']] = [
                    'zip_id'   => (int) $ds['zip_id'],
                    'zip_name' => $ds['zip_name'],
                ];
            }
        }

        // Active servers to choose from when deploying.
        $servers = $pdo->query("SELECT id, name, ssh_host FROM servers WHERE active = 1 ORDER BY name")->fetchAll();

        // Which server each host is already deployed on → "host" => [server_id, server_name],
        // shown as info text in the modal (and used to block cross-server conflicts).
        $siteServers = [];
        foreach ($pdo->query("SELECT ss.host, ss.server_id, s.name AS server_name, s.ssh_host
                              FROM server_sites ss
                              LEFT JOIN servers s ON s.id = ss.server_id")->fetchAll() as $ss) {
            $siteServers[$ss['host']] = [
                'server_id'   => (int) $ss['server_id'],
                'server_name' => $ss['server_name'],
                'ssh_host'    => $ss['ssh_host'],
            ];
        }

        $colspan = canSeeAllUsers() ? 11 : 10;

        return view('links', compact(
            'pageTitle', 'activePage', 'flash', 'domains', 'adxOptions', 'customerOptions', 'links',
            'dupCampaigns', 'dupGam', 'dupCampSet', 'dupGamSet', 'hasDuplicates',
            'editLink', 'fUser', 'fDomain', 'fAdx', 'fStatus', 'fQ', 'sort', 'dir',
            'personFilter', 'domainFilter', 'adxFilter', 'statusFilter', 'searchActive', 'filterActive',
            'addDomainId', 'openAdd', 'openDeploy', 'colspan', 'zips', 'zipVersions', 'deployZips', 'servers', 'siteServers'
        ));
    }

    private function resolveAdxId(Request $request): ?int
    {
        foreach (['adx_id', 'new_domain_adx_id'] as $field) {
            $raw = trim((string) $request->input($field, ''));
            if ($raw === '') continue;
            $value = (int) $raw;
            if ($value > 0) return $value;
        }

        return null;
    }

    /**
     * The link's effective ADX: the parent domain's ADX takes precedence when the
     * domain has one set (so a subdomain inherits it automatically). Only when the
     * domain has no ADX does the picker value ($pickerAdx) apply.
     */
    /**
     * Create GAM ad units for every subdomain GAM host on a link
     * (ab1.alopino.com → ab1alopino1..N). Main-domain hosts are skipped — their
     * units belong to the Domains row. Hosts that already have units are skipped,
     * so a failed attempt is retried on the next save. Honours the ADX's
     * "auto-create ad units" toggle. Returns a note for the flash message.
     */
    private function ensureHostAdUnits(\PDO $pdo, string $gamUrl, ?int $adxId): string
    {
        if (!$adxId) return '';
        try {
            $stmt = $pdo->prepare("SELECT network_code, key_file, line_item_id, auto_ad_unit FROM adx WHERE id = ?");
            $stmt->execute([$adxId]);
            $adx = $stmt->fetch();
            if (!$adx || empty($adx['auto_ad_unit'])) return '';

            $have = $pdo->prepare("SELECT ad_unit_id FROM gam_host_ad_units WHERE host = ?");
            $save = $pdo->prepare("INSERT INTO gam_host_ad_units (host, adx_id, ad_unit_id, ad_unit_code, error, synced_at, created_at, updated_at)
                                   VALUES (?,?,?,?,?,?,NOW(),NOW())
                                   ON DUPLICATE KEY UPDATE adx_id=VALUES(adx_id),
                                       ad_unit_id=COALESCE(VALUES(ad_unit_id), ad_unit_id),
                                       ad_unit_code=COALESCE(VALUES(ad_unit_code), ad_unit_code),
                                       error=VALUES(error), synced_at=COALESCE(VALUES(synced_at), synced_at), updated_at=NOW()");
        } catch (\Throwable $e) {
            return ' (Ad units not created — run migrations: ' . $e->getMessage() . ')';
        }

        $svc   = new \App\Services\GamAdUnit();
        $notes = [];
        foreach (array_unique(array_filter(array_map('trim', explode(',', $gamUrl)))) as $raw) {
            $host = preg_replace('/^www\./', '', normalizeUrl($raw));
            if ($host === '' || $host === registrableDomain($host)) continue;   // main domain

            $have->execute([$host]);
            if ((string) $have->fetchColumn() !== '') continue;                // already created

            $res = $svc->createForDomain(['name' => $host], $adx, false);
            if ($res['ok']) {
                $save->execute([$host, $adxId, $res['ad_unit_id'], $res['ad_unit_code'] ?? null, null, date('Y-m-d H:i:s')]);
            } else {
                $save->execute([$host, $adxId, null, null, mb_substr($res['msg'], 0, 500), null]);
            }
            $notes[] = "$host: " . $res['msg'];
        }
        return $notes ? ' Ad units — ' . implode(' · ', $notes) : '';
    }

    private function effectiveAdxId(\PDO $pdo, ?int $domainId, ?int $pickerAdx): ?int
    {
        if ($domainId) {
            $stmt = $pdo->prepare("SELECT adx_id FROM domains WHERE id = ?");
            $stmt->execute([(int) $domainId]);
            $domAdx = (int) ($stmt->fetchColumn() ?: 0);
            if ($domAdx > 0) return $domAdx;
        }
        return $pickerAdx;
    }

    /**
     * Start (active) / stop (paused) all of a link's deployed sites in aaPanel, using
     * server_sites to know which host lives on which server. Best-effort; returns a
     * short summary for the flash message.
     */
    private function toggleAapanelSites(\PDO $pdo, int $linkId, bool $on): string
    {
        $stmt = $pdo->prepare("SELECT host, server_id FROM server_sites WHERE link_id = ?");
        $stmt->execute([$linkId]);
        $sites = $stmt->fetchAll();
        if (!$sites) return '';

        // Load each involved active server once.
        $serverIds = array_values(array_unique(array_map(fn ($r) => (int) $r['server_id'], $sites)));
        $servers = [];
        $ph = implode(',', array_fill(0, count($serverIds), '?'));
        $q = $pdo->prepare("SELECT * FROM servers WHERE id IN ($ph) AND active = 1");
        $q->execute($serverIds);
        foreach ($q->fetchAll() as $s) $servers[(int) $s['id']] = $s;

        @set_time_limit(0);
        $svcCache = []; $ok = 0; $fail = 0; $errs = [];
        foreach ($sites as $site) {
            $srvId = (int) $site['server_id'];
            if (!isset($servers[$srvId])) { $fail++; continue; }
            if (!array_key_exists($srvId, $svcCache)) {
                try { $svcCache[$srvId] = new \App\Services\AapanelService(serverConfig($servers[$srvId])); }
                catch (\Throwable $e) { $svcCache[$srvId] = null; }
            }
            $svc = $svcCache[$srvId];
            if (!$svc) { $fail++; continue; }
            $r = $svc->setSiteRunning((string) $site['host'], $on);
            if (!empty($r['ok'])) $ok++;
            else { $fail++; if (count($errs) < 2) $errs[] = $site['host'] . ': ' . ($r['error'] ?? 'failed'); }
        }

        if (!$ok && !$fail) return '';
        $verb = $on ? 'started' : 'stopped';
        return "{$ok} site(s) {$verb}" . ($fail ? ", {$fail} failed" . ($errs ? ' (' . implode('; ', $errs) . ')' : '') : '') . ' in aaPanel.';
    }

    /**
     * Release a host from the server it is currently recorded on, so it can be
     * re-deployed elsewhere. $deleteFiles false stops the old site (files kept);
     * true deletes it outright. The server_sites row is left alone - the deploy
     * re-points it via ON DUPLICATE KEY once the new deploy succeeds.
     */
    private function releaseSiteFromServer(\PDO $pdo, int $oldServerId, string $host, bool $deleteFiles): array
    {
        $st = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
        $st->execute([$oldServerId]);
        $old = $st->fetch();
        if (!$old) return ['ok' => false, 'mode' => '', 'error' => 'old server not found'];

        try {
            $svc = new \App\Services\AapanelService(serverConfig($old));
        } catch (\Throwable $e) {
            return ['ok' => false, 'mode' => '', 'error' => 'panel connection failed: ' . $e->getMessage()];
        }

        return $svc->removeSite($host, $deleteFiles);
    }

    /**
     * name.com A-records for the given hosts -> the chosen server's IP. The add flow
     * passes just the new subdomain (one record); the deploy points each host it
     * actually deploys. Best-effort so DNS never blocks the action.
     * Best-effort: returns ['msg' => string, 'errors' => string[]] and never throws.
     */
    private function pointHostsAtServer(\PDO $pdo, int $serverId, array $hosts): array
    {
        $hosts = array_values(array_unique(array_filter(array_map('normalizeUrl', $hosts))));
        if ($serverId <= 0 || !$hosts) return ['msg' => '', 'errors' => []];

        $ncUser = getSetting('namecom_username', '');
        $ncTok  = getSetting('namecom_token', '');
        if ($ncUser === '' || $ncTok === '') {
            return ['msg' => '', 'errors' => ['DNS skipped - name.com credentials are not configured (Servers page).']];
        }

        $st = $pdo->prepare("SELECT ssh_host FROM servers WHERE id = ? AND active = 1");
        $st->execute([$serverId]);
        $ip = (string) ($st->fetchColumn() ?: '');
        if ($ip === '') return ['msg' => '', 'errors' => ['DNS skipped - server not found or inactive.']];

        try {
            $namecom = new \App\Services\NameComService($ncUser, \Illuminate\Support\Facades\Crypt::decryptString($ncTok));
        } catch (\Throwable $e) {
            return ['msg' => '', 'errors' => ['DNS skipped - name.com token could not be read.']];
        }

        $done = 0; $errs = [];
        foreach ($hosts as $host) {
            $r = $namecom->pointHost($host, $ip);
            if (!empty($r['ok'])) {
                $done += (int) $r['added'] + (int) $r['updated'] + (int) $r['skipped'];
            } else {
                foreach (($r['errors'] ?? []) as $e) if (count($errs) < 3) $errs[] = "{$host}: {$e}";
            }
        }

        return [
            'msg'    => $done > 0 ? " DNS: {$done} record(s) pointed at {$ip}." : '',
            'errors' => $errs ? ['DNS: ' . implode(' | ', $errs)] : [],
        ];
    }

    /**
     * Back to the Links page with the active filters/sort intact - modal forms carry
     * them in their action query string, so an add or deploy no longer dumps the user
     * back on an unfiltered list. $extra adds one-off params (e.g. deploy=<id>).
     */
    private function linksRedirect(Request $request, array $extra = [])
    {
        $q = array_diff_key($request->query(), array_flip(['add', 'edit', 'deploy'])) + $extra;
        $qs = http_build_query($q);
        return redirect('/links' . ($qs ? '?' . $qs : ''));
    }

    private function handlePost(Request $request, \PDO $pdo, &$flash)
    {
        $action = $request->input('action', '');

        $need = ['add' => 'add', 'edit' => 'edit', 'toggle' => 'edit', 'delete' => 'delete',
                 'bulk_pause' => 'edit', 'bulk_delete' => 'delete',
                 // Deploying / uploading a site changes a link's live server state.
                 'upload_site' => 'edit', 'deploy_multi' => 'edit', 'upload_zip' => 'edit'];
        if (isset($need[$action]) && !userCan('links', $need[$action])) {
            abort(403, 'You do not have permission to ' . $need[$action] . ' links.');
        }

        if ($action === 'add') {
            $metaUrl      = trim((string) $request->input('meta_url', ''));
            $gamUrl       = implode(',', array_filter(array_map('trim', explode(',', $request->input('gam_url', '')))));
            $notes        = trim((string) $request->input('notes', ''));
            $domainId     = (int) $request->input('domain_id', 0) ?: null;
            $customerId   = $this->resolveCustomerId($pdo, $request);
            $metaCampaign = implode(',', array_filter(array_map('trim', explode(',', $request->input('meta_campaign', '')))));
            $linkName     = normalizeUrl(explode(',', $gamUrl)[0] ?? '');   // subdomain = first GAM host
            if (!$metaCampaign || !$metaUrl || !$gamUrl) {
                $flash = ['type' => 'error', 'msg' => 'Meta campaign, Meta URL and GAM URL are required.'];
            } else {
                try {
                    $adxId    = $this->resolveAdxId($request);
                    $autoNote = '';
                    $domainId = $this->resolveDomainId($pdo, $domainId, $gamUrl, $adxId, $linkName, $autoNote);
                    // Subdomain inherits its domain's ADX when the domain has one set.
                    $adxId    = $this->effectiveAdxId($pdo, $domainId, $adxId);
                    $extraDomains = $this->ensureGamDomains($pdo, $gamUrl, $adxId, $linkName);
                    if ($extraDomains) $autoNote .= ' Added to Domains: ' . implode(', ', $extraDomains) . '.';
                    // Remembered so the Deploy modal defaults to the same server.
                    $dnsServerId = (int) $request->input('dns_server_id', 0) ?: null;
                    $pdo->prepare("INSERT INTO links (user_id, domain_id, adx_id, customer_id, link_name, meta_campaign, meta_url, meta_host, gam_url, notes, dns_server_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
                        ->execute([resolveOwnerId(), $domainId, $adxId, $customerId, $linkName, $metaCampaign, $metaUrl, normalizeUrl($metaUrl), $gamUrl, $notes, $dnsServerId]);
                    $newId = (int) $pdo->lastInsertId();   // read before any further queries

                    @set_time_limit(0);
                    $autoNote .= $this->ensureHostAdUnits($pdo, $gamUrl, $adxId);

                    // Optional: one A record per host on the link — the Meta URL sub-site
                    // and each GAM host — so DNS is already propagating by the time they
                    // are deployed. A host with a subdomain label (ct1.crmtechio.com) gets
                    // a single 'ct1' record; a bare apex (crmadvx.com) gets @ + www.
                    $dns  = $this->pointHostsAtServer(
                        $pdo,
                        (int) $dnsServerId,
                        array_merge([$metaUrl], explode(',', $gamUrl))
                    );
                    $type = empty($dns['errors']) ? 'success' : 'warning';
                    $note = $autoNote . $dns['msg'] . ($dns['errors'] ? ' ' . implode(' ', $dns['errors']) : '');
                    // Deploying is nearly always the next step, so re-open on the new
                    // subdomain's Deploy modal.
                    return $this->linksRedirect($request, $newId ? ['deploy' => $newId] : [])
                        ->with('flash', ['type' => $type, 'msg' => "Subdomain '$linkName' added." . $note]);
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'delete') {
            $id = (int) $request->input('id', 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM links WHERE id = ? AND " . scopeSQL());
                $stmt->execute([$id]);
                // Only clean up related rows if this user actually owned & deleted the link.
                if ($stmt->rowCount() > 0) {
                    $pdo->prepare("DELETE FROM deploy_selections WHERE link_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM server_sites WHERE link_id = ?")->execute([$id]);
                }
                return $this->linksRedirect($request)->with('flash', ['type' => 'success', 'msg' => 'Subdomain deleted.']);
            }
        }

        if ($action === 'toggle') {
            $id = (int) $request->input('id', 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE links SET active = 1 - active WHERE id = ? AND " . scopeSQL())->execute([$id]);
                $st = $pdo->prepare("SELECT active FROM links WHERE id = ?");
                $st->execute([$id]);
                $nowActive = ((int) $st->fetchColumn()) === 1;
                // Mirror the state onto the link's deployed sites in aaPanel.
                $siteMsg = $this->toggleAapanelSites($pdo, $id, $nowActive);
                $msg = ($nowActive ? 'Activated' : 'Paused') . '.' . ($siteMsg !== '' ? ' ' . $siteMsg : '');
                return $this->linksRedirect($request)->with('flash', ['type' => 'success', 'msg' => $msg]);
            }
        }

        // ── Bulk pause / delete of selected subdomains ──
        if ($action === 'bulk_pause' || $action === 'bulk_delete') {
            $ids = $request->input('ids', []);
            $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), fn ($x) => $x > 0)));
            if (!$ids) return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'No subdomains selected.']);

            // Restrict to rows the current user actually owns / can see.
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $own = $pdo->prepare("SELECT id FROM links WHERE id IN ($ph) AND " . scopeSQL());
            $own->execute($ids);
            $ids = array_map('intval', $own->fetchAll(\PDO::FETCH_COLUMN));
            if (!$ids) return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'No matching subdomains.']);
            $ph = implode(',', array_fill(0, count($ids), '?'));

            if ($action === 'bulk_delete') {
                $pdo->prepare("DELETE FROM links WHERE id IN ($ph)")->execute($ids);
                $pdo->prepare("DELETE FROM deploy_selections WHERE link_id IN ($ph)")->execute($ids);
                $pdo->prepare("DELETE FROM server_sites WHERE link_id IN ($ph)")->execute($ids);
                return $this->linksRedirect($request)->with('flash', ['type' => 'success', 'msg' => count($ids) . ' subdomain(s) deleted.']);
            }

            // bulk_pause → set inactive and stop their aaPanel sites (best-effort).
            $pdo->prepare("UPDATE links SET active = 0 WHERE id IN ($ph)")->execute($ids);
            @set_time_limit(0);
            foreach ($ids as $lid) $this->toggleAapanelSites($pdo, $lid, false);
            return $this->linksRedirect($request)->with('flash', ['type' => 'success', 'msg' => count($ids) . ' subdomain(s) paused.']);
        }

        if ($action === 'edit') {
            $id           = (int) $request->input('id', 0);
            $metaUrl      = trim((string) $request->input('meta_url', ''));
            $gamUrl       = implode(',', array_filter(array_map('trim', explode(',', $request->input('gam_url', '')))));
            $notes        = trim((string) $request->input('notes', ''));
            $domainId     = (int) $request->input('domain_id', 0) ?: null;
            $customerId   = $this->resolveCustomerId($pdo, $request);
            $metaCampaign = implode(',', array_filter(array_map('trim', explode(',', $request->input('meta_campaign', '')))));
            $linkName     = normalizeUrl(explode(',', $gamUrl)[0] ?? '');   // subdomain = first GAM host
            if ($id && $metaCampaign && $metaUrl && $gamUrl) {
                try {
                    // If the GAM URL change alters the derived MAIN_URL, the deployed
                    // Meta sub-site is now stale → flag it so the UI prompts a re-deploy.
                    // (GREATEST keeps an already-pending flag set.)
                    $oldStmt = $pdo->prepare("SELECT gam_url, adx_id FROM links WHERE id = ? AND " . scopeSQL());
                    $oldStmt->execute([$id]);
                    $oldRow      = $oldStmt->fetch() ?: [];
                    $oldGam      = (string) ($oldRow['gam_url'] ?? '');
                    $newMainUrl  = resolveMainUrl($gamUrl);
                    $mainChanged = resolveMainUrl($oldGam) !== $newMainUrl;

                    // ADX comes from the always-visible picker (pre-filled with the
                    // link's current ADX), so the submitted value is authoritative.
                    $adxId    = $this->resolveAdxId($request);
                    $autoNote = '';
                    $domainId = $this->resolveDomainId($pdo, $domainId, $gamUrl, $adxId, $linkName, $autoNote);
                    // Subdomain inherits its domain's ADX when the domain has one set.
                    $adxId    = $this->effectiveAdxId($pdo, $domainId, $adxId);
                    $extraDomains = $this->ensureGamDomains($pdo, $gamUrl, $adxId, $linkName);
                    if ($extraDomains) $autoNote .= ' Added to Domains: ' . implode(', ', $extraDomains) . '.';
                    $pdo->prepare("UPDATE links SET user_id=?, domain_id=?, adx_id=?, customer_id=?, link_name=?, meta_campaign=?, meta_url=?, meta_host=?, gam_url=?, notes=?, needs_redeploy=GREATEST(needs_redeploy, ?), updated_at=NOW() WHERE id=? AND " . scopeSQL())
                        ->execute([resolveOwnerId(), $domainId, $adxId, $customerId, $linkName, $metaCampaign, $metaUrl, normalizeUrl($metaUrl), $gamUrl, $notes, $mainChanged ? 1 : 0, $id]);

                    @set_time_limit(0);
                    $autoNote .= $this->ensureHostAdUnits($pdo, $gamUrl, $adxId);

                    $note = $mainChanged
                        ? " MAIN_URL is now '{$newMainUrl}' — re-deploy the Meta sub-site to apply."
                        : '';
                    return $this->linksRedirect($request)->with('flash', ['type' => 'success', 'msg' => 'Subdomain updated.' . $autoNote . $note]);
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'upload_site') {
            $id = (int) $request->input('id', 0);

            if ($id <= 0) {
                return null;
            }

            $stmt = $pdo->prepare("SELECT * FROM links WHERE id = ? AND " . scopeSQL());
            $stmt->execute([$id]);
            $link = $stmt->fetch();

            if (!$link) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'Subdomain not found.']);
            }

            // Domain to deploy under = host of the Meta URL
            // e.g. "https://ewire1.blogonbudget.xyz" -> "ewire1.blogonbudget.xyz"
            $metaUrlNormalized = $link['meta_url'];
            if (!preg_match('#^https?://#i', $metaUrlNormalized)) {
                $metaUrlNormalized = 'https://' . $metaUrlNormalized;
            }
            $domain = strtolower((string) parse_url($metaUrlNormalized, PHP_URL_HOST));
            $domain = preg_replace('/^www\./', '', $domain);

            // Main URL placeholder value = host of the first GAM URL
            // (gam_url can be a comma-separated list - use the first one)
            $gamFirst = trim(explode(',', $link['gam_url'])[0] ?? '');
            if ($gamFirst !== '' && !preg_match('#^https?://#i', $gamFirst)) {
                $gamFirst = 'https://' . $gamFirst;
            }
            $mainUrl = strtolower((string) (parse_url($gamFirst, PHP_URL_HOST) ?: $gamFirst));
            $mainUrl = preg_replace('/^www\./', '', $mainUrl);

            if ($domain === '' || $mainUrl === '') {
                return $this->linksRedirect($request)->with('flash', [
                    'type' => 'error',
                    'msg'  => "Could not determine domain or Main URL from '{$link['link_name']}'s Meta URL / GAM URL.",
                ]);
            }

            // Fixed template zip already sitting in storage - reused
            // for every subdomain deploy, regardless of its own filename.
            $zipPath = Storage::disk('local')->path('uploads/active1.smartblogsite.xyz.zip');

            if (!file_exists($zipPath)) {
                return $this->linksRedirect($request)->with('flash', [
                    'type' => 'error',
                    'msg'  => "Template zip not found at: {$zipPath}",
                ]);
            }

            // Deploys involve several SSH round-trips + a Let's Encrypt
            // request, which can take longer than PHP's default 60s limit.
            set_time_limit(300);
            ini_set('max_execution_time', 300);

            $deployment = new DeploymentService();
            $warnings = [];

            try {
                $siteResult = $deployment->registerSite($domain);
                $alreadyExisted = $siteResult['already_exists'] ?? false;

                if ($alreadyExisted) {
                    $warnings[] = "Domain '{$domain}' already exists on the server - existing files were overwritten.";
                }

                $deployment->uploadZip($domain, $zipPath);

                // Folder creation, extraction, flatten, [MAIN_URL]
                // replace, and rewrite rules - all in one SSH round-trip.
                $deployment->deployFiles($domain, 'MAIN_URL', $mainUrl);

                try {
                    $deployment->applySsl($domain);
                } catch (Throwable $e) {
                    $warnings[] = 'SSL installation failed: ' . $e->getMessage();
                }

                $message = empty($warnings)
                    ? "Deployed '{$link['link_name']}' successfully to {$domain}."
                    : "Deployed '{$link['link_name']}' with warning(s): " . implode(' | ', $warnings);

                return $this->linksRedirect($request)->with('flash', ['type' => 'success', 'msg' => $message]);

            } catch (Throwable $e) {
                return $this->linksRedirect($request)->with('flash', [
                    'type' => 'error',
                    'msg'  => "Deployment failed for '{$link['link_name']}': " . $e->getMessage(),
                ]);
            }
        }

        if ($action === 'deploy_multi') {
            $id   = (int) $request->input('id', 0);
            $rows = $request->input('rows', []);

            if ($id <= 0 || empty($rows)) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'No rows to deploy.']);
            }

            $stmt = $pdo->prepare("SELECT * FROM links WHERE id = ? AND " . scopeSQL());
            $stmt->execute([$id]);
            $link = $stmt->fetch();
            if (!$link) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'Subdomain not found.']);
            }

            // Which server to deploy to — its credentials drive the whole deploy.
            $serverId = (int) $request->input('server_id', 0);
            $srvStmt  = $pdo->prepare("SELECT * FROM servers WHERE id = ? AND active = 1");
            $srvStmt->execute([$serverId]);
            $server = $srvStmt->fetch();
            if (!$server) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'Please choose a server to deploy to.']);
            }
            $cfg = serverConfig($server);

            // name.com DNS — point each deployed domain's A @ / A www at this server's
            // IP (best-effort; only if credentials are configured on the Servers page).
            $namecom = null;
            $ncUser = getSetting('namecom_username', '');
            $ncTok  = getSetting('namecom_token', '');
            if ($ncUser !== '' && $ncTok !== '') {
                try { $namecom = new \App\Services\NameComService($ncUser, \Illuminate\Support\Facades\Crypt::decryptString($ncTok)); }
                catch (\Throwable $e) { $namecom = null; }
            }
            $serverIp = (string) ($server['ssh_host'] ?? '');
            $dnsCount = 0; $dnsErrs = [];

            // "Move sites to this server": a host already deployed elsewhere is normally
            // skipped; with this ticked the old site is stopped (or deleted, when the
            // stronger option is chosen) and the host is re-deployed here, DNS included.
            $moveSites  = (bool) $request->input('move_sites', false);
            $moveDelete = $moveSites && $request->input('move_mode') === 'delete';
            if ($moveDelete && !userCan('links', 'delete')) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error',
                    'msg' => 'Deleting the old site needs the links delete permission — use "stop the old site" instead.']);
            }
            $moved = [];

            $mainUrl = resolveMainUrl($link['gam_url']);   // .xyz sub-site → MAIN_URL (link-wide)
            // .com AD_UNIT is derived per-host inside the loop (each .com its own SLD).

            // [ADX] in main-site files → the link's ADX prefix (falls back to the
            // network code when the prefix is blank, e.g. on older rows).
            $adxCode = '';
            $adxTxt  = '';   // ads.txt content of the link's ADX → written to /ads.txt on main-sites
            if (!empty($link['adx_id'])) {
                $axStmt = $pdo->prepare("SELECT COALESCE(NULLIF(adx_prefix, ''), network_code) AS code, ads_txt FROM adx WHERE id = ?");
                $axStmt->execute([(int) $link['adx_id']]);
                if ($ax = $axStmt->fetch()) {
                    $adxCode = (string) ($ax['code'] ?? '');
                    $adxTxt  = (string) ($ax['ads_txt'] ?? '');
                }
            }

            // The Meta sub-site is the one the needs_redeploy flag tracks; when it
            // is set, wipe that site's old files on deploy so no stale code with the
            // previous MAIN_URL survives.
            $metaDomain    = preg_replace('/^www\./', '', normalizeUrl($link['meta_url']));
            $wipeMetaFiles = !empty($link['needs_redeploy']);

            // Deploys are several SSH round-trips + a Let's Encrypt request each,
            // so lift the time limit and run every selected host in turn.
            set_time_limit(0);
            ini_set('max_execution_time', '0');

            $errors = []; $successes = [];

            // foreach ($rows as $row) {
            foreach ($rows as $key => $row) {
                $host   = trim((string) ($row['host'] ?? ''));
                $zipVal = trim((string) ($row['zip_id'] ?? ''));
                if ($host === '' || $zipVal === '') continue;

                // A "v<id>" value is an archived older version; a plain id is a current zip.
                if (str_starts_with($zipVal, 'v')) {
                    $zStmt = $pdo->prepare("SELECT zv.file, zv.zip_id, z.active
                                            FROM zip_versions zv JOIN zips z ON z.id = zv.zip_id
                                            WHERE zv.id = ?");
                    $zStmt->execute([(int) substr($zipVal, 1)]);
                    $zip = $zStmt->fetch();
                    $zipId = $zip ? (int) $zip['zip_id'] : 0;   // parent zip id for deploy_selections
                } else {
                    $zipId = (int) $zipVal;
                    if ($zipId <= 0) continue;
                    $zStmt = $pdo->prepare("SELECT * FROM zips WHERE id = ?");
                    $zStmt->execute([$zipId]);
                    $zip = $zStmt->fetch();
                }
                if (!$zip) { $errors[] = "Zip not found for {$host}."; continue; }
                if (!$zip['active']) { $errors[] = "Zip for {$host} is inactive — activate it on ZIP Master first."; continue; }

                $zipPath = Storage::disk('public')->path($zip['file']);
                if (!file_exists($zipPath)) { $errors[] = "Zip file missing on disk for {$host}."; continue; }

                $hostNorm  = normalizeUrl($host);
                $tld       = strtolower(ltrim(strrchr($hostNorm, '.'), '.'));
                $isComSite = ($tld === 'com');
                $isComSite = ($key != 'meta');
                $domain    = preg_replace('/^www\./', '', $hostNorm);

                // A site can live on only one server — block deploying it to a different one.
                $ssStmt = $pdo->prepare("SELECT server_id FROM server_sites WHERE host = ?");
                $ssStmt->execute([$domain]);
                $existingServerId = (int) $ssStmt->fetchColumn();
                if ($existingServerId && $existingServerId !== $serverId) {
                    $onName = (string) ($pdo->query("SELECT name FROM servers WHERE id = " . $existingServerId)->fetchColumn() ?: "server #{$existingServerId}");
                    if (!$moveSites) {
                        $errors[] = "{$domain} is already deployed on '{$onName}' — skipped (tick 'Move sites to this server' to migrate it).";
                        continue;
                    }
                    // Take it off the old panel first, so the two servers never serve
                    // the same host once DNS switches over.
                    $rel = $this->releaseSiteFromServer($pdo, $existingServerId, $domain, $moveDelete);
                    if (empty($rel['ok'])) {
                        $errors[] = "Move of {$domain} from '{$onName}' failed: " . ($rel['error'] ?? 'unknown error') . " — not re-deployed.";
                        continue;
                    }
                    $moved[] = "{$domain} ({$onName} → {$server['name']}, old site {$rel['mode']})";
                }

                // Clean (delete old files) only for the flagged Meta sub-site.
                $clean = ($wipeMetaFiles && $domain === $metaDomain);

                try {
                    // Point this domain's A @ / A www at the server via name.com first —
                    // Let's Encrypt validates over HTTP, so DNS must already resolve here
                    // when applySsl() runs below.
                    if ($namecom && $serverIp !== '') {
                        $dns = $namecom->pointHost($domain, $serverIp);
                        if (!empty($dns['ok'])) {
                            $dnsCount += (int) $dns['added'] + (int) $dns['updated'];
                        } else {
                            foreach (($dns['errors'] ?? []) as $de) if (count($dnsErrs) < 3) $dnsErrs[] = "{$domain}: {$de}";
                        }
                    }

                    if ($isComSite) {
                        // AD_UNIT is per-.com-host (its own SLD), not link-wide.
                        $svc = new \App\Services\MainSiteDeploymentService($cfg);
                        $svc->registerSite($domain);
                        $svc->uploadZip($domain, $zipPath);
                        $svc->deployFiles($domain, 'AD_UNIT', resolveAdUnit($host), $clean, ['ADX' => $adxCode], $adxTxt);
                        try { $svc->applySsl($domain); } catch (\Throwable $e) { $errors[] = "SSL failed for {$domain}: " . $e->getMessage(); }
                    } else {
                        $svc = new \App\Services\DeploymentService($cfg);
                        $svc->registerSite($domain);
                        $svc->uploadZip($domain, $zipPath);
                        $svc->deployFiles($domain, 'MAIN_URL', $mainUrl, $clean);
                        try { $svc->applySsl($domain); } catch (\Throwable $e) { $errors[] = "SSL failed for {$domain}: " . $e->getMessage(); }
                    }
                    $successes[] = $domain;

                    // Remember the zip used for this host so the modal can
                    // pre-select it next time.
                    $pdo->prepare(
                        "INSERT INTO deploy_selections (link_id, host, zip_id, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE zip_id = VALUES(zip_id), updated_at = NOW()"
                    )->execute([$id, $domain, $zipId]);

                    // Record which server this host now lives on.
                    $pdo->prepare(
                        "INSERT INTO server_sites (server_id, host, link_id, kind, created_at, updated_at)
                         VALUES (?, ?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE server_id = VALUES(server_id), link_id = VALUES(link_id), kind = VALUES(kind), updated_at = NOW()"
                    )->execute([$serverId, $domain, $id, $isComSite ? 'main' : 'sub']);
                } catch (\Throwable $e) {
                    $errors[] = "Deploy failed for {$domain}: " . $e->getMessage();
                }
            }

            // Whatever server the link was last deployed to becomes its default for
            // the next deploy (and for any DNS records created from the Links page).
            if (!empty($successes)) {
                $pdo->prepare("UPDATE links SET dns_server_id = ? WHERE id = ? AND " . scopeSQL())
                    ->execute([$serverId, $id]);
            }

            // The needs_redeploy flag is about the Meta URL sub-site's MAIN_URL, so
            // only clear it when that specific sub-site was actually (re)deployed.
            if ($metaDomain !== '' && in_array($metaDomain, $successes, true)) {
                $pdo->prepare("UPDATE links SET needs_redeploy = 0 WHERE id = ? AND " . scopeSQL())->execute([$id]);
            }

            $msg = empty($successes) ? '' : 'Deployed: ' . implode(', ', $successes) . '.';
            if (!empty($moved)) $msg .= ' Moved: ' . implode('; ', $moved) . '.';
            if ($dnsCount > 0) $msg .= " DNS: {$dnsCount} name.com record(s) set.";
            if (!empty($dnsErrs)) $errors[] = 'DNS: ' . implode(' | ', $dnsErrs);
            if (!empty($errors)) $msg .= ' Errors: ' . implode(' | ', $errors);
            $type = empty($errors) ? 'success' : (empty($successes) ? 'error' : 'warning');
            return $this->linksRedirect($request)->with('flash', ['type' => $type, 'msg' => trim($msg) ?: 'Nothing was deployed.']);
        }

        if ($action === 'upload_zip') {
            $id     = (int) $request->input('id', 0);
            $zipId  = (int) $request->input('zip_id', 0);

            if ($id <= 0 || $zipId <= 0) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'Please select a zip file to upload.']);
            }

            $stmt = $pdo->prepare("SELECT * FROM links WHERE id = ? AND " . scopeSQL());
            $stmt->execute([$id]);
            $link = $stmt->fetch();

            if (!$link) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'Subdomain not found.']);
            }

            $zStmt = $pdo->prepare("SELECT * FROM zips WHERE id = ?");
            $zStmt->execute([$zipId]);
            $zip = $zStmt->fetch();

            if ($zip && !$zip['active']) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'Selected zip is inactive — activate it on ZIP Master first.']);
            }
            if (!$zip) {
                return $this->linksRedirect($request)->with('flash', ['type' => 'error', 'msg' => 'Selected zip file not found.']);
            }

            // Domain to deploy under = host of the Meta URL
            $metaUrlNormalized = $link['meta_url'];
            if (!preg_match('#^https?://#i', $metaUrlNormalized)) {
                $metaUrlNormalized = 'https://' . $metaUrlNormalized;
            }
            $domain = strtolower((string) parse_url($metaUrlNormalized, PHP_URL_HOST));
            $domain = preg_replace('/^www\./', '', $domain);

            // Main URL placeholder value = host of the first GAM URL
            $gamFirst = trim(explode(',', $link['gam_url'])[0] ?? '');
            if ($gamFirst !== '' && !preg_match('#^https?://#i', $gamFirst)) {
                $gamFirst = 'https://' . $gamFirst;
            }
            $mainUrl  = strtolower((string) (parse_url($gamFirst, PHP_URL_HOST) ?: $gamFirst));
            $mainUrl  = preg_replace('/^www\./', '', $mainUrl);

            if ($domain === '' || $mainUrl === '') {
                return $this->linksRedirect($request)->with('flash', [
                    'type' => 'error',
                    'msg'  => "Could not determine domain or Main URL from '{$link['link_name']}'s Meta URL / GAM URL.",
                ]);
            }

            // Selected zip's actual file on disk (uploaded via Zip Master page)
            $zipPath = Storage::disk('public')->path($zip['file']);

            if (!file_exists($zipPath)) {
                return $this->linksRedirect($request)->with('flash', [
                    'type' => 'error',
                    'msg'  => "Zip file not found on server: {$zipPath}",
                ]);
            }

            set_time_limit(300);
            ini_set('max_execution_time', 300);

            $deployment = new DeploymentService();
            $warnings = [];

            try {
                $siteResult = $deployment->registerSite($domain);
                $alreadyExisted = $siteResult['already_exists'] ?? false;

                if ($alreadyExisted) {
                    $warnings[] = "Domain '{$domain}' already exists on the server - existing files were overwritten.";
                }

                $deployment->uploadZip($domain, $zipPath);
                $deployment->deployFiles($domain, 'MAIN_URL', $mainUrl);

                try {
                    $deployment->applySsl($domain);
                } catch (Throwable $e) {
                    $warnings[] = 'SSL installation failed: ' . $e->getMessage();
                }

                $message = empty($warnings)
                    ? "Deployed '{$zip['name']}' to '{$link['link_name']}' ({$domain}) successfully."
                    : "Deployed '{$zip['name']}' to '{$link['link_name']}' with warning(s): " . implode(' | ', $warnings);

                return $this->linksRedirect($request)->with('flash', ['type' => 'success', 'msg' => $message]);

            } catch (Throwable $e) {
                return $this->linksRedirect($request)->with('flash', [
                    'type' => 'error',
                    'msg'  => "Deployment failed for '{$link['link_name']}': " . $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Decide which main domain a subdomain belongs to. If the user explicitly
     * picked one, keep it. Otherwise derive it from the first GAM host: match an
     * existing main domain, or auto-create one (using the selected ADX) so every
     * GAM site lands under a domain in the Domains list.
     */
    private function resolveDomainId(\PDO $pdo, ?int $domainId, string $gamUrl, ?int $adxId, string $linkName, string &$autoNote): ?int
    {
        if ($domainId) return $domainId;

        $hosts = array_filter(array_map('trim', explode(',', $gamUrl)));
        $host  = normalizeUrl($hosts[0] ?? '');
        if ($host === '') return null;

        if ($match = matchDomainRow($pdo, $host)) {
            return (int) $match['id'];
        }

        $rootName = registrableDomain($host);
        if ($rootName === '') return null;

        $pdo->prepare("INSERT INTO domains (name, adx_id, notes, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())")
            ->execute([$rootName, $adxId, 'Auto-created from subdomain ' . $linkName]);
        $autoNote = " New main domain '$rootName' was created.";
        return (int) $pdo->lastInsertId();
    }

    /**
     * Ensure EVERY GAM host's registrable domain exists in the Domains list —
     * auto-creating any that are missing. This covers extra GAM URLs (including
     * ones added on edit) that resolveDomainId (which only uses the first host)
     * would otherwise skip. Returns the names of domains created.
     */
    private function ensureGamDomains(\PDO $pdo, string $gamUrl, ?int $adxId, string $linkName): array
    {
        $created = [];
        foreach (array_filter(array_map('trim', explode(',', $gamUrl))) as $raw) {
            $host = normalizeUrl($raw);
            if ($host === '') continue;
            if (matchDomainRow($pdo, $host)) continue;      // already covered by a domain

            $rootName = registrableDomain($host);
            if ($rootName === '') continue;

            // Guard against duplicates within this same list (two hosts, one root).
            $chk = $pdo->prepare("SELECT id FROM domains WHERE name = ?");
            $chk->execute([$rootName]);
            if ($chk->fetchColumn()) continue;

            $pdo->prepare("INSERT INTO domains (name, adx_id, notes, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())")
                ->execute([$rootName, $adxId, 'Auto-created from subdomain ' . $linkName]);
            $created[] = $rootName;
        }
        return $created;
    }

    /** Optional assigned customer from the form; anything blank or unknown → NULL. */
    private function resolveCustomerId(\PDO $pdo, Request $request): ?int
    {
        $id = (int) $request->input('customer_id', 0);
        if ($id <= 0) return null;
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ? $id : null;
    }
}
