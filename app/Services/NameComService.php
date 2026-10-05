<?php

namespace App\Services;

/**
 * Minimal name.com v4 DNS client — used to point a deployed domain at its server
 * by upserting the two A records the app needs:  A @  and  A www  → server IP.
 *
 * Auth is HTTP Basic (name.com username + API token). Idempotent: existing records
 * are updated in place (not duplicated) so re-deploys / server changes just work.
 */
class NameComService
{
    private string $user;
    private string $token;
    private string $base;

    public function __construct(string $user, string $token, bool $dev = false)
    {
        $this->user  = $user;
        $this->token = $token;
        // name.com's sandbox lives on api.dev.name.com; production on api.name.com.
        $this->base  = $dev ? 'https://api.dev.name.com' : 'https://api.name.com';
    }

    /**
     * Ensure  A @  and  A www  for $domain point at $ip. Returns
     * ['ok'=>bool, 'added'=>int, 'updated'=>int, 'skipped'=>int, 'errors'=>[]].
     */
    public function pointDomain(string $domain, string $ip): array
    {
        $domain = strtolower(preg_replace('/^www\./', '', trim($domain)));
        $out = ['ok' => true, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        if ($domain === '' || $ip === '') {
            return ['ok' => false, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['missing domain or ip']];
        }

        try {
            $existing = $this->listRecords($domain);
        } catch (\Throwable $e) {
            return ['ok' => false, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['list: ' . $e->getMessage()]];
        }

        foreach (['', 'www'] as $host) {          // '' = apex (@)
            try {
                $r = $this->upsertA($domain, $host, $ip, $existing);
                $out[$r]++;                        // 'added' | 'updated' | 'skipped'
            } catch (\Throwable $e) {
                $out['ok'] = false;
                $out['errors'][] = ($host === '' ? '@' : 'www') . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * Ensure a single host (apex OR subdomain, e.g. ewire1.blogonbudget.xyz) resolves
     * to $ip. The host is split into the registered zone name.com knows about and the
     * record host under it. Returns the same shape as pointDomain().
     */
    public function pointHost(string $host, string $ip): array
    {
        $out = ['ok' => true, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $host = strtolower(trim($host));
        if ($host === '' || $ip === '') {
            return ['ok' => false, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['missing host or ip']];
        }

        [$zone, $recordHost] = $this->splitHost($host);
        // Apex (or www of the apex) → keep the @ + www pair behaviour.
        if ($recordHost === '' || $recordHost === 'www') {
            return $this->pointDomain($zone, $ip);
        }

        try {
            $existing = $this->listRecords($zone);
            $r = $this->upsertA($zone, $recordHost, $ip, $existing);
            $out[$r]++;
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['errors'][] = $recordHost . ': ' . $e->getMessage();
        }
        return $out;
    }

    /**
     * Split a host into [zone, recordHost] — 'ewire1.blogonbudget.xyz' →
     * ['blogonbudget.xyz', 'ewire1']. Handles the common two-label public
     * suffixes (co.uk, com.au, …); everything else is treated as SLD + TLD.
     */
    public function splitHost(string $host): array
    {
        $host  = trim(strtolower($host), ". ");
        $parts = explode('.', $host);
        if (count($parts) <= 2) return [$host, ''];

        $twoLevel = ['co.uk','org.uk','me.uk','ac.uk','gov.uk','co.in','net.in','org.in',
                     'com.au','net.au','org.au','co.nz','co.za','com.br','com.mx','co.jp','com.sg'];
        $zoneLen = in_array(implode('.', array_slice($parts, -2)), $twoLevel, true) ? 3 : 2;
        if (count($parts) <= $zoneLen) return [$host, ''];

        $zone = implode('.', array_slice($parts, -$zoneLen));
        $rec  = implode('.', array_slice($parts, 0, count($parts) - $zoneLen));
        return [$zone, $rec];
    }

    /** Create/update the A record for one host; returns which happened. */
    private function upsertA(string $domain, string $host, string $ip, array $existing): string
    {
        // Find an existing A record for this host (apex records report host '' or absent).
        $match = null;
        foreach ($existing as $rec) {
            if (strtoupper((string) ($rec['type'] ?? '')) !== 'A') continue;
            if ((string) ($rec['host'] ?? '') === $host) { $match = $rec; break; }
        }

        if ($match) {
            if ((string) ($match['answer'] ?? '') === $ip) return 'skipped';   // already correct
            $this->http('PUT', "/v4/domains/{$domain}/records/" . (int) $match['id'],
                array_filter(['host' => $host, 'type' => 'A', 'answer' => $ip, 'ttl' => 300], fn ($v) => $v !== ''));
            return 'updated';
        }

        $this->http('POST', "/v4/domains/{$domain}/records",
            array_filter(['host' => $host, 'type' => 'A', 'answer' => $ip, 'ttl' => 300], fn ($v) => $v !== ''));
        return 'added';
    }

    /** All DNS records for a domain. */
    private function listRecords(string $domain): array
    {
        $res = $this->http('GET', "/v4/domains/{$domain}/records");
        return $res['records'] ?? [];
    }

    private function http(string $method, string $path, ?array $json = null): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $this->user . ':' . $this->token,   // HTTP Basic
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("HTTP $method failed: $err"); }
        curl_close($ch);

        $j = json_decode($res, true) ?: [];
        if ($code < 200 || $code >= 300) {
            throw new \Exception($j['message'] ?? ($j['details'] ?? "HTTP $code"));
        }
        return $j;
    }
}
