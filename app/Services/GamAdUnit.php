<?php

namespace App\Services;

/**
 * Creates a GAM ad unit for a domain via the Ad Manager SOAP API
 * (InventoryService.createAdUnits) using the domain's ADX service-account key.
 *
 * The REST API this app uses for reports cannot create inventory, so this uses
 * SOAP (pure cURL — no SDK). Needs the `dfp` OAuth scope and an inventory-write
 * role on the service account. When GAM_MOCK is on, returns a fake id so the UI
 * flow can be exercised without live credentials.
 */
class GamAdUnit
{
    private array $cfg;
    private array $au;

    public function __construct()
    {
        $this->cfg = config('adledger.gam');
        $this->au  = $this->cfg['ad_unit'] ?? [];
    }

    /**
     * Create the configured number of ad units for $domain under its ADX network.
     * They are named <base>1..<base>N where base is every host label except the
     * TLD (erpnewswire.com → erpnewswire1, …; ab1.alopino.com → ab1alopino1, …).
     * $registerSite=false skips the Ad Exchange Site step — used for subdomain
     * hosts, which are covered by their main domain's site.
     * Returns ['ok'=>bool, 'msg'=>string, 'ad_unit_id'=>?csv, 'ad_unit_code'=>?csv].
     */
    public function createForDomain(array $domain, array $adx, bool $registerSite = true): array
    {
        $name = trim((string) ($domain['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'msg' => 'Domain has no name.'];

        $base  = $this->baseLabel($name);
        $count = max(1, (int) ($this->au['count'] ?? 5));
        $codes = [];
        for ($i = 1; $i <= $count; $i++) $codes[] = $base . $i;

        $lineItemId = preg_replace('/\D/', '', (string) ($adx['line_item_id'] ?? ''));

        if (!empty($this->cfg['mock'])) {
            $ids = array_map(fn () => 'MOCK-' . random_int(100000, 999999), $codes);
            $siteId = $registerSite ? 'MOCK-' . random_int(100000, 999999) : null;
            $msg = "[MOCK] {$count} ad units created (" . implode(', ', $codes) . ").";
            if ($lineItemId !== '') $msg .= " [MOCK] added to line item $lineItemId.";
            if ($registerSite) $msg .= " [MOCK] registered site '$name' (id $siteId).";
            return ['ok' => true, 'ad_unit_id' => implode(',', $ids), 'ad_unit_code' => implode(',', $codes),
                    'site_id' => $siteId, 'msg' => $msg];
        }

        $network = trim((string) ($adx['network_code'] ?? ''));
        $keyFile = trim((string) ($adx['key_file'] ?? ''));
        if ($network === '' || $keyFile === '') {
            return ['ok' => false, 'msg' => "ADX network is missing a network code or service-account key."];
        }
        if (!is_file($keyFile)) {
            return ['ok' => false, 'msg' => "Service-account key file not found: $keyFile"];
        }

        try {
            $token   = $this->accessToken($keyFile);
            $appName = (string) ($this->au['application_name'] ?? 'Amaira');
            $parentId = trim((string) ($this->au['parent_id'] ?? '')) ?: $this->rootAdUnitId($token, $network, $appName);

            $createdIds = []; $createdCodes = []; $errors = [];
            foreach ($codes as $code) {
                try {
                    $createdIds[]   = $this->createAdUnit($token, $network, $appName, $parentId, $code, $code);
                    $createdCodes[] = $code;
                } catch (\Throwable $e) {
                    $errors[] = "$code: " . $e->getMessage();
                }
            }

            if (empty($createdIds)) {
                return ['ok' => false, 'msg' => 'No ad units created. ' . implode(' | ', $errors)];
            }
            $msg = count($createdIds) . " ad unit(s) created (" . implode(', ', $createdCodes) . ").";
            if ($errors) $msg .= ' Skipped: ' . implode(' | ', $errors);

            // Optionally add the new ad units to a line item's targeting (non-fatal).
            if ($lineItemId !== '') {
                try {
                    $n = $this->addAdUnitsToLineItem($token, $network, $appName, $lineItemId, $createdIds);
                    $msg .= " Added $n ad unit(s) to line item $lineItemId.";
                } catch (\Throwable $e) {
                    $msg .= " (Line item $lineItemId not updated — " . $e->getMessage() . ")";
                }
            }

            // Register the domain as a GAM Ad Exchange Site (Inventory → Sites), non-fatal.
            $siteId = null;
            if ($registerSite) {
                try {
                    $siteId = $this->createSite($token, $network, $appName, $name);
                    $msg .= " Registered site '$name' (id $siteId).";
                } catch (\Throwable $e) {
                    $msg .= " (Site '$name' not registered — " . $e->getMessage() . ")";
                }
            }

            return ['ok' => true, 'ad_unit_id' => implode(',', $createdIds), 'ad_unit_code' => implode(',', $createdCodes),
                    'site_id' => $siteId, 'msg' => $msg];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Test the SOAP connection for an ADX network (NetworkService.getCurrentNetwork).
     * Confirms the key, API access and network resolve; returns network details.
     */
    public function testNetwork(array $adx): array
    {
        $network = trim((string) ($adx['network_code'] ?? ''));
        $keyFile = trim((string) ($adx['key_file'] ?? ''));
        if ($network === '' || $keyFile === '') return ['ok' => false, 'msg' => 'Missing network code or service-account key.'];
        if (!is_file($keyFile))                  return ['ok' => false, 'msg' => "Service-account key file not found: $keyFile"];

        if (!empty($this->cfg['mock'])) {
            return ['ok' => true, 'msg' => "[MOCK] connection OK for network $network (mock mode — no real call made)."];
        }

        try {
            $token   = $this->accessToken($keyFile);
            $appName = (string) ($this->au['application_name'] ?? 'Amaira');
            $resp    = $this->soap('NetworkService', $token, $network, $appName, '<getCurrentNetwork xmlns="%NS%"/>');

            $code = $this->extract($resp, 'networkCode');
            $disp = $this->extract($resp, 'displayName');
            $root = $this->extract($resp, 'effectiveRootAdUnitId');
            return ['ok' => true, 'msg' => "Connected \u{2713} network $code" . ($disp ? " ($disp)" : '') . ($root ? " · root ad unit $root" : '')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /** Domain → its base label (part before the first dot), alphanumeric only. */
    private function baseLabel(string $name): string
    {
        $base = resolveAdUnit($name);
        return $base !== '' ? $base : 'adunit';
    }

    // ── SOAP calls ──────────────────────────────────────────────────────

    private function rootAdUnitId(string $token, string $network, string $appName): string
    {
        $resp = $this->soap('NetworkService', $token, $network, $appName, '<getCurrentNetwork xmlns="%NS%"/>');
        $root = $this->extract($resp, 'effectiveRootAdUnitId');
        if ($root === null) throw new \Exception('Could not read the network root ad unit id.');
        return $root;
    }

    private function createAdUnit(string $token, string $network, string $appName, string $parentId, string $name, string $code): string
    {
        $target = (string) ($this->au['target_window'] ?? 'BLANK');
        $sizes  = '';
        foreach (($this->au['sizes'] ?? [[300, 250]]) as $wh) {
            [$w, $h] = $wh;
            $sizes .= "<adUnitSizes><size><width>{$w}</width><height>{$h}</height><isAspectRatio>false</isAspectRatio></size>"
                    . "<environmentType>BROWSER</environmentType></adUnitSizes>";
        }
        // Child elements MUST follow the GAM AdUnit XSD order:
        // parentId → name → description → targetWindow → status → adUnitCode → adUnitSizes.
        $body = '<createAdUnits xmlns="%NS%"><adUnits>'
              . '<parentId>' . $this->xml($parentId) . '</parentId>'
              . '<name>' . $this->xml($name) . '</name>'
              . '<targetWindow>' . $this->xml($target) . '</targetWindow>'
              . '<adUnitCode>' . $this->xml($code) . '</adUnitCode>'
              . $sizes
              . '</adUnits></createAdUnits>';

        $resp = $this->soap('InventoryService', $token, $network, $appName, $body);
        $id   = $this->extract($resp, 'id');
        if ($id === null) throw new \Exception('Ad unit created but no id returned by GAM.');
        return $id;
    }

    /**
     * Read the GAM approval status of a site (SiteService.getSitesByStatement).
     * Returns ['ok'=>bool, 'status'=>?string (e.g. APPROVED), 'msg'=>string].
     */
    public function siteApprovalStatus(array $adx, string $siteId): array
    {
        $siteId = preg_replace('/\D/', '', $siteId);
        if ($siteId === '') return ['ok' => false, 'msg' => 'no site id'];

        if (!empty($this->cfg['mock'])) {
            return ['ok' => true, 'status' => 'APPROVED', 'msg' => '[MOCK] APPROVED'];
        }

        $network = trim((string) ($adx['network_code'] ?? ''));
        $keyFile = trim((string) ($adx['key_file'] ?? ''));
        if ($network === '' || $keyFile === '') return ['ok' => false, 'msg' => 'ADX network missing network code or key'];
        if (!is_file($keyFile))                  return ['ok' => false, 'msg' => "key file not found: $keyFile"];

        try {
            $token   = $this->accessToken($keyFile);
            $appName = (string) ($this->au['application_name'] ?? 'Amaira');
            $body = '<getSitesByStatement xmlns="%NS%"><filterStatement>'
                  . '<query>WHERE id = ' . $siteId . ' LIMIT 1</query>'
                  . '</filterStatement></getSitesByStatement>';
            $resp = $this->soap('SiteService', $token, $network, $appName, $body);

            $status = $this->extract($resp, 'approvalStatus');
            return ['ok' => true, 'status' => $status, 'msg' => $status ?? 'no status returned'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /** Dump the raw GAM Site record for a site id — diagnostic, to see all fields. */
    public function siteRaw(array $adx, string $siteId): array
    {
        $siteId = preg_replace('/\D/', '', $siteId);
        if ($siteId === '') return ['ok' => false, 'msg' => 'no site id'];

        $network = trim((string) ($adx['network_code'] ?? ''));
        $keyFile = trim((string) ($adx['key_file'] ?? ''));
        if ($network === '' || $keyFile === '') return ['ok' => false, 'msg' => 'ADX network missing network code or key'];
        if (!is_file($keyFile))                  return ['ok' => false, 'msg' => "key file not found: $keyFile"];

        try {
            $token   = $this->accessToken($keyFile);
            $appName = (string) ($this->au['application_name'] ?? 'Amaira');
            $body = '<getSitesByStatement xmlns="%NS%"><filterStatement>'
                  . '<query>WHERE id = ' . $siteId . ' LIMIT 1</query>'
                  . '</filterStatement></getSitesByStatement>';
            $resp = $this->soap('SiteService', $token, $network, $appName, $body);
            $xml  = preg_match('#<(?:\w+:)?results\b[^>]*>(.*)</(?:\w+:)?results>#s', $resp, $m) ? $m[1] : $resp;
            return ['ok' => true, 'xml' => $xml];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Look up an existing GAM site by its URL (to backfill gam_site_id for
     * domains registered before we tracked it, or added directly in GAM).
     * Returns ['ok'=>bool, 'site_id'=>?string, 'status'=>?string, 'msg'=>string].
     */
    public function findSite(array $adx, string $url): array
    {
        $host = preg_replace('#/.*$#', '', preg_replace('#^https?://#i', '', trim($url)));
        if ($host === '') return ['ok' => false, 'msg' => 'no url'];

        if (!empty($this->cfg['mock'])) {
            return ['ok' => true, 'site_id' => 'MOCK-' . random_int(100000, 999999), 'status' => 'APPROVED', 'msg' => "[MOCK] found site for $host"];
        }

        $network = trim((string) ($adx['network_code'] ?? ''));
        $keyFile = trim((string) ($adx['key_file'] ?? ''));
        if ($network === '' || $keyFile === '') return ['ok' => false, 'msg' => 'ADX network missing network code or key'];
        if (!is_file($keyFile))                  return ['ok' => false, 'msg' => "key file not found: $keyFile"];

        try {
            $token   = $this->accessToken($keyFile);
            $appName = (string) ($this->au['application_name'] ?? 'Amaira');
            $q    = "WHERE url = '" . str_replace("'", '', $host) . "' LIMIT 1";
            $body = '<getSitesByStatement xmlns="%NS%"><filterStatement><query>'
                  . $this->xml($q) . '</query></filterStatement></getSitesByStatement>';
            $resp = $this->soap('SiteService', $token, $network, $appName, $body);

            $id = $this->extract($resp, 'id');
            if ($id === null) return ['ok' => true, 'site_id' => null, 'msg' => "no GAM site found for $host"];
            $status = $this->extract($resp, 'approvalStatus');
            return ['ok' => true, 'site_id' => $id, 'status' => $status, 'msg' => "found site $id ($status)"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /** Register a domain as a GAM Ad Exchange Site (SiteService.createSites). Returns the site id. */
    private function createSite(string $token, string $network, string $appName, string $domainName): string
    {
        $url  = preg_replace('#^https?://#i', '', trim($domainName));
        $url  = preg_replace('#/.*$#', '', $url);   // host only, no path
        $body = '<createSites xmlns="%NS%"><sites><url>' . $this->xml($url) . '</url></sites></createSites>';
        $resp = $this->soap('SiteService', $token, $network, $appName, $body);
        $id   = $this->extract($resp, 'id');
        if ($id === null) throw new \Exception('site created but GAM returned no id');
        return $id;
    }

    /**
     * Add ad units to a line item's inventory targeting (get → modify → update).
     * Returns the number of ad units added. Assumes the line item already has an
     * <inventoryTargeting> block (line items normally do).
     */
    private function addAdUnitsToLineItem(string $token, string $network, string $appName, string $lineItemId, array $adUnitIds): int
    {
        $lineItemId = preg_replace('/\D/', '', $lineItemId);
        if ($lineItemId === '') throw new \Exception('invalid line item id');

        $ids = array_values(array_filter(array_map(fn ($x) => preg_replace('/\D/', '', (string) $x), $adUnitIds)));
        if (!$ids) return 0;

        // 1) Fetch the line item.
        $getBody = '<getLineItemsByStatement xmlns="%NS%"><filterStatement>'
                 . '<query>WHERE id = ' . $lineItemId . ' LIMIT 1</query>'
                 . '</filterStatement></getLineItemsByStatement>';
        $resp = $this->soap('LineItemService', $token, $network, $appName, $getBody);

        if (!preg_match('#<(?:\w+:)?results\b[^>]*>(.*)</(?:\w+:)?results>#s', $resp, $m)) {
            throw new \Exception("line item $lineItemId not found");
        }
        // Strip the operation-namespace prefix so tags fall under updateLineItems'
        // default namespace, while xsi:type attributes stay intact.
        $li = $this->stripNsPrefix($resp, $m[1]);

        if (!preg_match('/<inventoryTargeting\b[^>]*>/', $li)) {
            throw new \Exception('line item has no inventoryTargeting');
        }

        $add = '';
        foreach ($ids as $id) {
            $add .= "<targetedAdUnits><adUnitId>{$id}</adUnitId><includeDescendants>true</includeDescendants></targetedAdUnits>";
        }
        $li = preg_replace('/(<inventoryTargeting\b[^>]*>)/', '$1' . $add, $li, 1);

        // 2) Send the whole (modified) line item back.
        $updBody = '<updateLineItems xmlns="%NS%"><lineItems>' . $li . '</lineItems></updateLineItems>';
        $this->soap('LineItemService', $token, $network, $appName, $updBody);

        return count($ids);
    }

    /** Remove the prefix bound to the GAM operation namespace from a response fragment. */
    private function stripNsPrefix(string $fullResponse, string $fragment): string
    {
        $version = (string) ($this->au['soap_version'] ?? 'v202408');
        $ns      = "https://www.google.com/apis/ads/publisher/$version";
        if (preg_match('/xmlns:(\w+)="' . preg_quote($ns, '/') . '"/', $fullResponse, $m)) {
            $p = $m[1];
            $fragment = preg_replace('#<' . $p . ':#', '<', $fragment);
            $fragment = preg_replace('#</' . $p . ':#', '</', $fragment);
            $fragment = preg_replace('/\s+xmlns:' . $p . '="[^"]*"/', '', $fragment);
        }
        return $fragment;
    }

    /** POST a SOAP operation to a GAM service and return the raw response XML. */
    private function soap(string $service, string $token, string $network, string $appName, string $bodyTemplate): string
    {
        $version = (string) ($this->au['soap_version'] ?? 'v202408');
        $ns      = "https://www.google.com/apis/ads/publisher/$version";
        $body    = str_replace('%NS%', $ns, $bodyTemplate);

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<soap:Header><RequestHeader xmlns="' . $ns . '">'
            . '<networkCode>' . $this->xml($network) . '</networkCode>'
            . '<applicationName>' . $this->xml($appName) . '</applicationName>'
            . '</RequestHeader></soap:Header>'
            . '<soap:Body>' . $body . '</soap:Body></soap:Envelope>';

        $url = "https://ads.google.com/apis/ads/publisher/$version/$service";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $out  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("SOAP request failed: $err"); }
        curl_close($ch);

        $fault = $this->extract($out, 'faultstring') ?? $this->extract($out, 'errorString');
        if ($fault !== null) throw new \Exception("GAM error: $fault");
        if ($code < 200 || $code >= 300) throw new \Exception("GAM HTTP $code: " . substr($out, 0, 300));
        return $out;
    }

    /** Extract the first <tag> value from a namespaced SOAP response. */
    private function extract(string $xml, string $tag): ?string
    {
        if (preg_match('/<(?:\w+:)?' . preg_quote($tag, '/') . '>([^<]*)<\/(?:\w+:)?' . preg_quote($tag, '/') . '>/', $xml, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1);
        }
        return null;
    }

    private function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Service-account access token for the SOAP Ad Manager API (dfp scope). */
    private function accessToken(string $keyFile): string
    {
        $key = json_decode((string) file_get_contents($keyFile), true);
        if (empty($key['client_email']) || empty($key['private_key'])) throw new \Exception('invalid service-account key file');

        $now   = time();
        $claim = ['iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/dfp',
                  'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600];
        $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode($claim));
        $sig = '';
        if (!openssl_sign($jwt, $sig, $key['private_key'], 'sha256WithRSAEncryption')) throw new \Exception('JWT signing failed');
        $assertion = $jwt . '.' . $b64($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion])]);
        $out = curl_exec($ch);
        if ($out === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("token request failed: $err"); }
        curl_close($ch);
        $j = json_decode($out, true);
        if (empty($j['access_token'])) throw new \Exception('no access_token: ' . $out);
        return $j['access_token'];
    }
}
