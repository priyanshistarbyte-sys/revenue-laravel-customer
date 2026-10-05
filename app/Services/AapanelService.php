<?php

namespace App\Services;

use AzozzALFiras\AAPanelAPI\AaPanel;
use AzozzALFiras\AAPanelAPI\Exceptions\AaPanelException;
use Exception;

class AapanelService
{
    protected AaPanel $panel;
    protected string $phpVersion;

    /** Panel credentials from the selected server ($cfg), falling back to .env. */
    public function __construct(array $cfg = [])
    {
        $this->panel = new AaPanel(
            $cfg['bt_panel_key'] ?? env('BT_PANEL_KEY'),
            $cfg['bt_panel_url'] ?? env('BT_PANEL_URL'),
            [
                'verify_ssl' => false,
            ]
        );

        $this->phpVersion = $cfg['bt_panel_php_version'] ?? env('BT_PANEL_PHP_VERSION', '74');
    }

    /** Cache of resolved site-category ids (lower-cased name => id) for this run. */
    protected array $typeIds = [];

    /**
     * Create a site in aaPanel under a category. $category is the aaPanel site-type
     * name — "sub" for Meta-URL sub-sites, "main" for main sites — created on the
     * panel if it doesn't exist yet.
     */
    public function addSite(string $domain, string $category = 'main'): array
    {
        $typeId = $this->categoryId($category);

        try {
            $result = $this->panel->website()->php()->create(
                $domain,
                "/www/wwwroot/{$domain}",
                $domain,
                [],
                $typeId,
                $this->phpVersion,
                80,
                false, '', '',
                false, '', ''
            );

            // aaPanel returns a normal HTTP 200 with {"status":false,"msg":"..."}
            // when AddSite fails (e.g. PHP version not installed, port in use,
            // domain already bound). The library does NOT throw for that, so a
            // failed create would otherwise be treated as success and the deploy
            // would carry on with no site/vhost actually created. Detect it here.
            $status = $result['status'] ?? null;
            $failed = ($status === false || $status === 'false' || $status === 0 || $status === -1);

            if ($failed) {
                $msg = (string) ($result['msg'] ?? 'unknown error');
                if (str_contains(strtolower($msg), 'exist')) {
                    $result['already_exists'] = true;
                } else {
                    throw new Exception('aaPanel AddSite failed: ' . $msg);
                }
            } else {
                $result['already_exists'] = false;
            }
        } catch (AaPanelException $e) {
            if (str_contains(strtolower($e->getMessage()), 'exist')) {
                $result = [
                    'msg' => $e->getMessage(),
                    'status' => true,
                    'already_exists' => true,
                ];
            } else {
                throw new Exception('aaPanel AddSite failed: ' . $e->getMessage());
            }
        }

        // AddSite doesn't reliably apply type_id, so assign the category explicitly
        // (runs for new AND existing sites; non-fatal).
        $assign = $this->setCategory($domain, $category);
        $result['category']    = $category;
        $result['category_id'] = $assign['category_id'];
        $result['category_set'] = $assign['ok'];
        if (!$assign['ok'] && !empty($assign['error'])) {
            $result['category_error'] = $assign['error'];
        }

        return $result;
    }

    /**
     * Assign an existing site to a category ("sub"/"main"), creating the category on
     * the panel if needed. Uses set_site_type with aaPanel's real params (category id
     * + JSON list of site ids — the library's setSiteType() sends the wrong ones).
     * Returns ['ok'=>bool, 'category'=>string, 'category_id'=>int, 'site_id'=>?, 'error'=>?].
     */
    public function setCategory(string $domain, string $category): array
    {
        $tid = $this->categoryId($category);
        $out = ['ok' => false, 'category' => $category, 'category_id' => $tid, 'site_id' => null];
        if ($tid <= 0) {
            $out['error'] = 'category not resolved';
            return $out;
        }
        try {
            $sid = $this->getSiteId($domain);
            $out['site_id'] = $sid;
            if (!$sid) {
                $out['error'] = 'site not found on panel';
                return $out;
            }
            $this->panel->getHttpClient()->post(
                '/site?action=set_site_type',
                ['id' => $tid, 'site_ids' => json_encode([$sid])]
            );
            $out['ok'] = true;
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /**
     * Diagnostic: the panel's site-category list before + after resolving "sub"/"main"
     * (which creates them if missing) and the ids they resolve to. Shows exactly what
     * the panel returns so parsing / assignment can be matched to the real shape.
     */
    public function debugCategories(?string $testDomain = null, string $testCategory = 'sub'): array
    {
        $php = $this->panel->website()->php();
        $out = [];

        try { $out['before'] = $php->getSiteTypes(); }
        catch (\Throwable $e) { $out['before_error'] = $e->getMessage(); }

        $out['resolved'] = [];
        foreach (['sub', 'main'] as $c) {
            try { $out['resolved'][$c] = $this->categoryId($c); }
            catch (\Throwable $e) { $out['resolved'][$c] = 'ERR: ' . $e->getMessage(); }
        }

        // Optionally test the real assignment on an existing site (moves it to $testCategory).
        if ($testDomain) {
            $tid = $this->categoryId($testCategory);
            $sid = $this->getSiteId($testDomain);
            $out['assign_test'] = ['domain' => $testDomain, 'category' => $testCategory, 'category_id' => $tid, 'site_id' => $sid];

            if ($sid && $tid > 0) {
                try {
                    $out['assign_test']['response'] = $this->panel->getHttpClient()->post(
                        '/site?action=set_site_type',
                        ['id' => $tid, 'site_ids' => json_encode([$sid])]
                    );
                } catch (\Throwable $e) {
                    $out['assign_test']['error'] = $e->getMessage();
                }
                // Verify by filtering the site list by category id (getList doesn't
                // return type_id, so membership in the category is the real proof).
                $out['assign_test']['in_category_after'] = $this->inCategory($testDomain, $tid);
            }
        }

        try { $out['after'] = $php->getSiteTypes(); }
        catch (\Throwable $e) { $out['after_error'] = $e->getMessage(); }

        return $out;
    }

    /**
     * Resolve an aaPanel site-category id by name, creating the category if it does
     * not exist yet. Returns 0 (the panel's default category) if categories can't be
     * read or created, so site creation is never blocked by categorisation.
     */
    protected function categoryId(string $name): int
    {
        $key = strtolower($name);
        if (array_key_exists($key, $this->typeIds)) {
            return $this->typeIds[$key];
        }

        try {
            $id = $this->findTypeId($name);
            if ($id === null) {
                $this->panel->website()->php()->addSiteType($name);   // create it
                $id = $this->findTypeId($name);                       // re-read to get its id
            }

            return $this->typeIds[$key] = (int) ($id ?? 0);
        } catch (\Throwable $e) {
            return $this->typeIds[$key] = 0;
        }
    }

    /** Find an existing aaPanel site-category id by name (case-insensitive), or null. */
    protected function findTypeId(string $name): ?int
    {
        $res = $this->panel->website()->php()->getSiteTypes();

        // aaPanel returns a plain list [{id,name},…]; some builds nest it under a key.
        $list = $res;
        foreach (['data', 'message', 'types'] as $k) {
            if (isset($res[$k]) && is_array($res[$k])) {
                $list = $res[$k];
                break;
            }
        }
        if (!is_array($list)) {
            return null;
        }

        $want = strtolower(trim($name));
        foreach ($list as $t) {
            if (is_array($t) && strtolower(trim((string) ($t['name'] ?? ''))) === $want) {
                return (int) ($t['id'] ?? 0);
            }
        }

        return null;
    }

    /**
     * Look up the numeric site ID aaPanel assigned to this domain -
     * needed for SSL and other per-site API calls.
     */
    public function getSiteId(string $domain): ?int
    {
        $result = $this->panel->website()->php()->getList(10, 1, $domain);

        $data = $result['data'] ?? [];

        foreach ($data as $site) {
            if (($site['name'] ?? null) === $domain) {
                return (int) $site['id'];
            }
        }

        return null;
    }

    /**
     * Start or stop a deployed site in aaPanel (SiteStart / SiteStop). Used to mirror
     * a link's active/paused state onto its sites. Returns ['ok'=>bool, 'error'=>?].
     */
    public function setSiteRunning(string $domain, bool $on): array
    {
        try {
            $sid = $this->getSiteId($domain);
            if (!$sid) {
                return ['ok' => false, 'error' => 'site not found on panel'];
            }
            $php = $this->panel->website()->php();
            $on ? $php->start($sid, $domain) : $php->stop($sid, $domain);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Take a site off this panel - used when a host is moved to another server.
     * $deleteFiles false (default) just stops the site (SiteStop), leaving the
     * vhost and /www/wwwroot files intact; true issues aaPanel's DeleteSite, which
     * removes the site, its web root and its database and cannot be undone.
     * Returns ['ok'=>bool, 'mode'=>'stopped'|'deleted', 'error'=>?].
     */
    public function removeSite(string $domain, bool $deleteFiles = false): array
    {
        try {
            $sid = $this->getSiteId($domain);
            if (!$sid) return ['ok' => false, 'mode' => '', 'error' => 'site not found on panel'];

            if (!$deleteFiles) {
                $this->panel->website()->php()->stop($sid, $domain);
                return ['ok' => true, 'mode' => 'stopped'];
            }

            // aaPanel answers HTTP 200 with {"status":false,"msg":"..."} on failure,
            // same as AddSite - check the payload rather than trusting the request.
            $res = $this->panel->getHttpClient()->post('/site?action=DeleteSite', [
                'id' => $sid, 'webname' => $domain, 'path' => 1, 'database' => 1, 'ftp' => 1,
            ]);
            $status = $res['status'] ?? null;
            if ($status === false || $status === 'false' || $status === 0) {
                return ['ok' => false, 'mode' => '', 'error' => (string) ($res['msg'] ?? 'DeleteSite failed')];
            }
            return ['ok' => true, 'mode' => 'deleted'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mode' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * Whether a site currently belongs to a category, checked by filtering aaPanel's
     * site list by category id (getData supports a `type` filter). Returns true/false,
     * or a diagnostic string on error. The site list omits type_id, so this membership
     * check is the reliable way to confirm a category assignment stuck.
     */
    private function inCategory(string $domain, int $typeId)
    {
        try {
            $res = $this->panel->getHttpClient()->post('/data?action=getData', [
                'table' => 'sites', 'limit' => 2000, 'p' => 1, 'type' => $typeId,
                'order' => '', 'tojs' => 'get_site_list',
            ]);
        } catch (\Throwable $e) {
            return 'ERR: ' . $e->getMessage();
        }
        foreach (($res['data'] ?? []) as $site) {
            if (($site['name'] ?? '') === $domain) return true;
        }
        return false;
    }

    /**
     * Applies a free Let's Encrypt certificate and deploys it to
     * the site in one step. Requires the domain's DNS to already
     * point at this server, since Let's Encrypt verifies ownership
     * over HTTP.
     */
    public function applySsl(string $domain): array
    {
        // Skip if the site already has a certificate — don't re-issue on every deploy.
        if ($this->hasSsl($domain)) {
            return ['status' => true, 'already_applied' => true, 'msg' => "SSL already applied for {$domain}."];
        }

        $siteId = $this->getSiteId($domain);

        if (!$siteId) {
            throw new Exception("Could not find aaPanel site ID for {$domain} to apply SSL.");
        }

        return $this->panel->ssl()->applyAndDeploy($domain, $siteId);
    }

    /**
     * Whether the site already has an SSL certificate applied (aaPanel GetSSL).
     * A configured cert comes back with cert_data / key populated. On any read
     * error we return false so applySsl() still attempts issuance.
     */
    public function hasSsl(string $domain): bool
    {
        try {
            $res = $this->panel->ssl()->getSSL($domain);
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($res)) {
            return false;
        }

        return !empty($res['cert_data'])
            || (!empty($res['key']) && !empty($res['csr']))
            || !empty($res['endtime']);   // days-to-expiry, only present with a cert
    }
}