<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Site Checker — verifies that deployed sites have their placeholders replaced.
 * Sub-sites (.xyz): [MAIN_URL].  Main-sites (.com): [AD_UNIT] and [ADX].
 * The list is built from Links; checks run on demand by SSH-grepping the deployed
 * files on the site's server (reads the source, so it catches leftovers even in
 * conditional PHP blocks that HTTP rendering never shows).
 */
class CheckerController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Site Checker';
        $activePage = 'site-checker';
        $pdo = getDB();

        // ADX id → prefix (fallback network_code), for the [ADX] expected value.
        // Also id → name for the ADX filter dropdown.
        $adxCodes = [];
        $adxNames = [];
        foreach ($pdo->query("SELECT id, name, COALESCE(NULLIF(adx_prefix, ''), network_code) AS code FROM adx ORDER BY name")->fetchAll() as $ax) {
            $adxCodes[(int) $ax['id']] = (string) $ax['code'];
            $adxNames[(int) $ax['id']] = (string) $ax['name'];
        }

        $stmt = $pdo->query("SELECT id, link_name, meta_url, gam_url, adx_id FROM links WHERE " . scopeSQL('user_id') . " ORDER BY link_name");
        $links = $stmt->fetchAll();

        $subSites = [];   // Meta URL → check [MAIN_URL]
        $mainSites = [];  // GAM hosts → check [AD_UNIT] + [ADX]

        $usedAdx = [];    // adx_id → name, only those actually present (for the filter)

        foreach ($links as $l) {
            $mainUrlVal = resolveMainUrl($l['gam_url']);
            $adxId      = (int) ($l['adx_id'] ?? 0);
            $adxCode    = $adxCodes[$adxId] ?? '';
            $adxName    = $adxNames[$adxId] ?? '';
            if ($adxId > 0 && $adxName !== '') $usedAdx[$adxId] = $adxName;

            // Categorise by ROLE, not TLD (a Meta URL / sub-site can be .com too,
            // and this mirrors the deploy pipeline exactly).
            // The Meta URL is the sub-site → [MAIN_URL].
            $metaHost = preg_replace('/^www\./', '', normalizeUrl($l['meta_url']));
            if ($metaHost !== '' && !isset($subSites[$metaHost])) {
                $subSites[$metaHost] = [
                    'host'     => $metaHost,
                    'link'     => $l['link_name'],
                    'main_url' => $mainUrlVal,
                    'adx_id'   => $adxId,
                ];
            }

            // Each GAM URL host is a main-site → [AD_UNIT] + [ADX].
            foreach (array_filter(array_map('trim', explode(',', $l['gam_url']))) as $g) {
                $host = preg_replace('/^www\./', '', normalizeUrl($g));
                if ($host === '' || isset($mainSites[$host])) continue;
                $mainSites[$host] = [
                    'host'     => $host,
                    'link'     => $l['link_name'],
                    'ad_unit'  => resolveAdUnit($host),
                    'adx'      => $adxCode,
                    'adx_id'   => $adxId,
                ];
            }
        }

        $subSites  = array_values($subSites);
        $mainSites = array_values($mainSites);

        // ADX filter options — only networks that appear on a checked site.
        $adxOptions = [];
        foreach ($usedAdx as $id => $name) $adxOptions[] = ['id' => $id, 'name' => $name];
        usort($adxOptions, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return view('checker', compact('pageTitle', 'activePage', 'subSites', 'mainSites', 'adxOptions'));
    }

    /**
     * Check a single host: fetch the live page and report, per placeholder token,
     * whether the raw [TOKEN] is still present (not replaced) and whether the
     * expected value is visible. Called via AJAX (one host per call).
     */
    public function check(Request $request)
    {
        $host = preg_replace('/^www\./', '', normalizeUrl((string) $request->query('host', '')));
        $type = $request->query('type') === 'main' ? 'main' : 'sub';

        if ($host === '') {
            return response()->json(['ok' => false, 'error' => 'Missing host.'], 400);
        }

        $tokens = $type === 'main' ? ['AD_UNIT', 'ADX'] : ['MAIN_URL'];

        $pdo = getDB();

        // Which server is this host deployed on? (from server_sites, else the sole
        // active server if there's exactly one.)
        $s = $pdo->prepare("SELECT s.* FROM server_sites ss JOIN servers s ON s.id = ss.server_id WHERE ss.host = ? AND s.active = 1");
        $s->execute([$host]);
        $server = $s->fetch();
        if (!$server) {
            $act = $pdo->query("SELECT * FROM servers WHERE active = 1")->fetchAll();
            if (count($act) === 1) $server = $act[0];
        }
        if (!$server) {
            return response()->json(['ok' => true, 'state' => 'unknown', 'tokens' => []]);
        }

        // Build a script that greps the deployed files for each raw [TOKEN]. This
        // reads the SOURCE, so it catches leftovers even inside conditional PHP
        // blocks that HTTP rendering would never show.
        $dir   = '/www/wwwroot/' . $host;
        $lines = ['DIR=' . escapeshellarg($dir), 'if [ ! -d "$DIR" ]; then echo NODIR; exit 0; fi'];
        foreach ($tokens as $t) {
            $pat     = escapeshellarg("[{$t}]");
            $lines[] = "if grep -rqF -- {$pat} \"\$DIR\" 2>/dev/null; then echo '{$t}=PRESENT'; else echo '{$t}=ABSENT'; fi";
        }
        // SSL: is a (non-expired) cert installed for this domain?
        $lines[] = 'CERT=' . escapeshellarg('/www/server/panel/vhost/cert/' . $host . '/fullchain.pem');
        $lines[] = 'if [ -s "$CERT" ]; then if openssl x509 -checkend 0 -noout -in "$CERT" >/dev/null 2>&1; then echo SSL=INSTALLED; else echo SSL=EXPIRED; fi; else echo SSL=MISSING; fi';

        $script = implode("\n", $lines);
        $cmd    = 'echo ' . escapeshellarg(base64_encode($script)) . ' | base64 -d | bash';

        try {
            $out = (string) (new \App\Services\SSHService(serverConfig($server)))->execute($cmd);
        } catch (\Throwable $e) {
            return response()->json(['ok' => true, 'state' => 'error', 'tokens' => [], 'error' => $e->getMessage()]);
        }

        if (str_contains($out, 'NODIR')) {
            return response()->json(['ok' => true, 'state' => 'notfound', 'tokens' => []]);
        }

        $results = [];
        foreach ($tokens as $t) {
            $present     = str_contains($out, "{$t}=PRESENT");
            $results[$t] = ['state' => $present ? 'pending' : 'ok'];
        }

        $ssl = str_contains($out, 'SSL=INSTALLED') ? 'installed'
             : (str_contains($out, 'SSL=EXPIRED') ? 'expired' : 'missing');

        return response()->json(['ok' => true, 'state' => 'checked', 'tokens' => $results, 'ssl' => $ssl]);
    }
}
