<?php

namespace App\Services;

/**
 * Syncs the assets needed by the Meta campaign builder — ad accounts, pages and
 * pixels — from the Graph API into meta_ad_accounts / meta_pages / meta_pixels,
 * so the builder's dropdowns are real. Pure cURL, mirrors MetaSync. Mock-aware.
 */
class MetaAssetsSync
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('adledger.meta');
    }

    /** Sync assets for every sync-ready Meta account (or one, via $onlyAccount). */
    public function run(int $onlyAccount = 0): array
    {
        $pdo  = getDB();
        $mock = !empty($this->cfg['mock']);
        $sql  = "SELECT * FROM meta_accounts WHERE active = 1 AND api_token <> ''"
              . ($onlyAccount ? " AND id = " . (int) $onlyAccount : "") . " ORDER BY name";
        $accounts = $pdo->query($sql)->fetchAll();

        if (!$accounts) {
            return ['ok' => false, 'msg' => $onlyAccount
                ? "Meta account #$onlyAccount not found, inactive, or has no API token."
                : "No sync-ready Meta accounts (need an API token)."];
        }

        $summary = []; $anyOk = false;
        $updAcc  = $pdo->prepare("UPDATE meta_accounts SET last_sync = ?, last_error = ? WHERE id = ?");

        foreach ($accounts as $acc) {
            try {
                $a = $this->syncAdAccounts($pdo, $acc, $mock);
                $p = $this->syncPages($pdo, $acc, $mock);
                $x = $this->syncPixels($pdo, $acc, $mock);
                $updAcc->execute([date('Y-m-d H:i:s'), '', $acc['id']]);
                $summary[] = "{$acc['name']}: {$a} ad accounts, {$p} pages, {$x} pixels";
                $anyOk = true;
            } catch (\Throwable $e) {
                $updAcc->execute([$acc['last_sync'] ?? null, date('Y-m-d H:i:s') . ' — ' . $e->getMessage(), $acc['id']]);
                $summary[] = "{$acc['name']}: ERROR " . $e->getMessage();
            }
        }

        return ['ok' => $anyOk, 'msg' => 'Meta assets sync' . ($mock ? ' [MOCK]' : '') . ' — ' . implode(' | ', $summary)];
    }

    // ── Ad accounts ─────────────────────────────────────────────────────
    private function syncAdAccounts(\PDO $pdo, array $acc, bool $mock): int
    {
        $rows = $mock ? [
            ['id' => 'act_1451129893448122', 'account_id' => '1451129893448122', 'name' => 'HT 2741', 'currency' => 'USD'],
            ['id' => 'act_2533524870424982', 'account_id' => '2533524870424982', 'name' => 'HT 2742', 'currency' => 'USD'],
        ] : $this->fetch($acc, '/me/adaccounts?fields=name,account_id,currency&limit=200');

        $ins = $pdo->prepare("INSERT INTO meta_ad_accounts (meta_account_id, act_id, account_id, name, currency, active, created_at, updated_at)
                              VALUES (?,?,?,?,?,1,NOW(),NOW())
                              ON DUPLICATE KEY UPDATE name=VALUES(name), currency=VALUES(currency), account_id=VALUES(account_id), active=1, updated_at=NOW()");
        $n = 0;
        foreach ($rows as $r) {
            $act = (string) ($r['id'] ?? ('act_' . ($r['account_id'] ?? '')));
            if ($act === 'act_') continue;
            $ins->execute([$acc['id'], $act, (string) ($r['account_id'] ?? ''), $this->clip($r['name'] ?? ''), (string) ($r['currency'] ?? '')]);
            $n++;
        }
        return $n;
    }

    // ── Pages ───────────────────────────────────────────────────────────
    private function syncPages(\PDO $pdo, array $acc, bool $mock): int
    {
        // Page records: ['act' => act_id ('' = global/directly-managed), 'id', 'name'].
        $records = [];
        if ($mock) {
            $records[] = ['act' => '', 'id' => '127971234567890', 'name' => 'Taylor Pagnozie'];
            $records[] = ['act' => '', 'id' => '133456789012345', 'name' => 'Wevion Store'];
        } else {
            foreach ($this->fetchPages($acc) as $p) {
                if (!empty($p['id'])) $records[] = ['act' => '', 'id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? '')];
            }
            // Fallback for tokens that can't list businesses/pages ("(#100) Missing
            // Permission"): derive the pages used in each ad account's ads, scoped to
            // that ad account so the picker can show only its pages.
            if (!$records) {
                $actIds = $pdo->query("SELECT act_id FROM meta_ad_accounts WHERE meta_account_id = " . (int) $acc['id'])->fetchAll(\PDO::FETCH_COLUMN);
                $nameCache = [];
                foreach ($this->fetchPagesFromAds($acc, $actIds) as $pair) {
                    $id = $pair['id'];
                    if (!array_key_exists($id, $nameCache)) $nameCache[$id] = $this->pageName($acc, $id, $pair['story'] ?? '');
                    $records[] = ['act' => $pair['act'], 'id' => $id, 'name' => $nameCache[$id]];
                }
            }
        }

        $ins = $pdo->prepare("INSERT INTO meta_pages (meta_account_id, act_id, page_id, name, created_at, updated_at)
                              VALUES (?,?,?,?,NOW(),NOW())
                              ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=NOW()");
        $n = 0; $seen = [];
        foreach ($records as $r) {
            $id = (string) ($r['id'] ?? '');
            $key = $r['act'] . '|' . $id;
            if ($id === '' || isset($seen[$key])) continue;      // de-dupe per (account, page)
            $seen[$key] = true;
            $ins->execute([$acc['id'], $r['act'], $id, $this->clip($r['name'] ?? '')]);
            $n++;
        }
        return $n;
    }

    /**
     * Fetch every usable Fan Page for this token. A **System User** token (the
     * usual case here) returns nothing from /me/accounts — its pages live under
     * the businesses it belongs to — so we union three sources and de-dupe:
     *   • /me/accounts               (classic user tokens / directly-managed pages)
     *   • /{business}/owned_pages    (pages the business owns)
     *   • /{business}/client_pages   (pages shared into the business)
     * Each source is best-effort: a missing permission on one won't abort the rest.
     * Needs `pages_show_list` (+ `business_management` for the business edges).
     */
    private function fetchPages(array $acc): array
    {
        $pages = [];
        try { foreach ($this->fetch($acc, '/me/accounts?fields=id,name&limit=200') as $p) $pages[] = $p; }
        catch (\Throwable $e) {}

        try {
            foreach ($this->fetch($acc, '/me/businesses?fields=id&limit=100') as $b) {
                $bid = (string) ($b['id'] ?? '');
                if ($bid === '') continue;
                foreach (['owned_pages', 'client_pages'] as $edge) {
                    try { foreach ($this->fetch($acc, "/{$bid}/{$edge}?fields=id,name&limit=200") as $p) $pages[] = $p; }
                    catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {}

        return $pages;
    }

    /**
     * Fallback page source for tokens that can't list businesses/pages: collect the
     * page ids actually used in an ad account's ads (needs only ads_management), the
     * same way the campaign pull resolves the page.
     */
    private function fetchPagesFromAds(array $acc, array $actIds): array
    {
        $pairs = [];
        foreach ($actIds as $act) {
            $act = str_starts_with((string) $act, 'act_') ? $act : ('act_' . $act);
            try {
                foreach ($this->fetch($acc, "/{$act}/ads?fields=creative{effective_object_story_id,object_story_id,actor_id,object_story_spec{page_id}}&limit=100") as $ad) {
                    $cr    = $ad['creative'] ?? [];
                    $story = (string) ($cr['effective_object_story_id'] ?? ($cr['object_story_id'] ?? ''));
                    $pid   = (string) ($cr['object_story_spec']['page_id'] ?? '');
                    if ($pid === '' && $story !== '')            $pid = explode('_', $story)[0];
                    if ($pid === '' && !empty($cr['actor_id']))  $pid = (string) $cr['actor_id'];
                    if ($pid === '') continue;
                    $key = $act . '|' . $pid;
                    if (!isset($pairs[$key]))                          $pairs[$key] = ['act' => $act, 'id' => $pid, 'story' => $story];
                    elseif ($pairs[$key]['story'] === '' && $story)    $pairs[$key]['story'] = $story;
                }
            } catch (\Throwable $e) { /* skip this ad account */ }
        }
        return array_values($pairs);
    }

    /**
     * Resolve one page's name (best-effort — falls back to the id). Tries the page
     * node directly, then the promoted post's author page (which an ads_management
     * token can often read when the page node itself is blocked).
     */
    private function pageName(array $acc, string $id, string $story = ''): string
    {
        $token = trim((string) ($acc['api_token'] ?? ''));
        $ver   = $this->cfg['api_version'] ?? 'v21.0';

        try {                                                          // 1. the page node
            $r = $this->http("https://graph.facebook.com/{$ver}/{$id}?fields=name", $token);
            if (!empty($r['name'])) return (string) $r['name'];
        } catch (\Throwable $e) {}

        if ($story !== '') {                                           // 2. the promoted post's author
            try {
                $r = $this->http("https://graph.facebook.com/{$ver}/{$story}?fields=from{name,id}", $token);
                if (!empty($r['from']['name'])) return (string) $r['from']['name'];
            } catch (\Throwable $e) {}
        }
        return $id;
    }

    /**
     * Human-readable report of why pages are / aren't syncing — what's stored vs.
     * what each live API source returns (with the real error when one fails, e.g. a
     * missing business_management / pages_show_list permission). Diagnostic only.
     */
    public function diagnose(int $onlyAccount = 0): string
    {
        $pdo = getDB();
        $sql = "SELECT * FROM meta_accounts" . ($onlyAccount ? " WHERE id = " . (int) $onlyAccount : "") . " ORDER BY name";
        $accounts = $pdo->query($sql)->fetchAll();
        $out = "Meta assets diagnostic" . (!empty($this->cfg['mock']) ? " [MOCK]" : "") . "\n";
        $out .= str_repeat('=', 60) . "\n\n";

        foreach ($accounts as $acc) {
            $out .= "Account: {$acc['name']}  (meta_account #{$acc['id']})\n";
            $out .= "  active=" . ($acc['active'] ? 'yes' : 'no') . "  token=" . ($acc['api_token'] ? 'set' : 'MISSING') . "\n";
            $adN = $pdo->query("SELECT COUNT(*) FROM meta_ad_accounts WHERE meta_account_id = {$acc['id']}")->fetchColumn();
            $pgN = $pdo->query("SELECT COUNT(*) FROM meta_pages WHERE meta_account_id = {$acc['id']}")->fetchColumn();
            $out .= "  stored: {$adN} ad accounts, {$pgN} pages\n";

            if (empty($acc['api_token']) || !$acc['active']) { $out .= "  (inactive or no token — API skipped)\n\n"; continue; }

            $probe = function (string $path) use ($acc) {
                try { return count($this->fetch($acc, $path)); }
                catch (\Throwable $e) { return 'ERROR ' . $e->getMessage(); }
            };
            $out .= "  API /me/accounts (direct pages): " . $probe('/me/accounts?fields=id,name&limit=200') . "\n";
            try {
                $biz = $this->fetch($acc, '/me/businesses?fields=id,name&limit=100');
                $out .= "  API /me/businesses: " . count($biz) . "\n";
                foreach ($biz as $b) {
                    $bid = (string) ($b['id'] ?? ''); $bn = $b['name'] ?? $bid; if ($bid === '') continue;
                    $out .= "    - {$bn}: owned_pages=" . $probe("/{$bid}/owned_pages?fields=id,name&limit=200")
                          . ", client_pages=" . $probe("/{$bid}/client_pages?fields=id,name&limit=200") . "\n";
                }
            } catch (\Throwable $e) {
                $out .= "  API /me/businesses: ERROR " . $e->getMessage() . "\n";
            }

            // Fallback source that only needs ads_management.
            $actIds  = $pdo->query("SELECT act_id FROM meta_ad_accounts WHERE meta_account_id = {$acc['id']} LIMIT 5")->fetchAll(\PDO::FETCH_COLUMN);
            $derived = $this->fetchPagesFromAds($acc, $actIds);
            $ids = array_values(array_unique(array_map(fn ($p) => $p['id'], $derived)));
            $out .= "  Pages derived from ads (first " . count($actIds) . " ad accts): " . count($ids)
                  . ($ids ? " -> " . implode(', ', array_slice($ids, 0, 12)) : '') . "\n";
            if ($derived) {
                $f = $derived[0];
                $out .= "  Name check for {$f['id']} (story " . ($f['story'] ?: '-') . "): '" . $this->pageName($acc, $f['id'], $f['story'] ?? '') . "'\n";
            }

            // Instagram wiring — why creatives may fail with "does not have access to
            // Instagram account". Shows whether the ad account owns an IG, and which IG
            // (if any) the page has linked.
            $ver = $this->cfg['api_version'] ?? 'v21.0';
            $token = trim((string) $acc['api_token']);
            $firstAct = $actIds[0] ?? '';
            if ($firstAct !== '') {
                $firstAct = str_starts_with((string) $firstAct, 'act_') ? $firstAct : ('act_' . $firstAct);
                try {
                    $r = $this->http("https://graph.facebook.com/{$ver}/{$firstAct}/instagram_accounts?fields=username&limit=10", $token);
                    $out .= "  Ad-account ({$firstAct}) Instagram accounts: " . count($r['data'] ?? []) . "\n";
                } catch (\Throwable $e) { $out .= "  Ad-account Instagram accounts: ERROR " . $e->getMessage() . "\n"; }
            }
            if ($ids) {
                try {
                    $r  = $this->http("https://graph.facebook.com/{$ver}/{$ids[0]}?fields=instagram_business_account{username},connected_instagram_account{username}", $token);
                    $ig = $r['instagram_business_account']['username'] ?? ($r['connected_instagram_account']['username'] ?? '');
                    $out .= "  Page {$ids[0]} linked Instagram: " . ($ig !== '' ? '@' . $ig : '(none or unreadable)') . "\n";
                } catch (\Throwable $e) { $out .= "  Page IG check: ERROR " . $e->getMessage() . "\n"; }
            }
            $out .= "\n";
        }
        return $out;
    }

    // ── Pixels (per ad account) ─────────────────────────────────────────
    private function syncPixels(\PDO $pdo, array $acc, bool $mock): int
    {
        $ins = $pdo->prepare("INSERT INTO meta_pixels (meta_account_id, act_id, pixel_id, name, created_at, updated_at)
                              VALUES (?,?,?,?,NOW(),NOW())
                              ON DUPLICATE KEY UPDATE act_id=VALUES(act_id), name=VALUES(name), updated_at=NOW()");
        $n = 0;

        // Which ad accounts to look under — the ones we just synced for this account.
        $acts = $pdo->prepare("SELECT act_id FROM meta_ad_accounts WHERE meta_account_id = ?");
        $acts->execute([$acc['id']]);
        $actIds = $acts->fetchAll(\PDO::FETCH_COLUMN) ?: array_filter(array_map('trim', explode(',', (string) $acc['ad_account_ids'])));

        foreach ($actIds as $act) {
            $act = str_starts_with((string) $act, 'act_') ? $act : ('act_' . $act);
            $rows = $mock
                ? [['id' => '9885959921140376', 'name' => 'Main Pixel']]
                : $this->fetch($acc, "/{$act}/adspixels?fields=id,name&limit=100");
            foreach ($rows as $r) {
                if (empty($r['id'])) continue;
                $ins->execute([$acc['id'], $act, (string) $r['id'], $this->clip($r['name'] ?? '')]);
                $n++;
            }
        }
        return $n;
    }

    /** Keep names within the column width (Meta names can be very long). */
    private function clip($s): string
    {
        return mb_substr((string) $s, 0, 500);
    }

    /** GET a Graph endpoint (following paging) and return the merged `data` rows. */
    private function fetch(array $acc, string $path): array
    {
        $token = trim((string) ($acc['api_token'] ?? ''));
        if ($token === '') throw new \Exception('no API token');
        $ver = $this->cfg['api_version'] ?? 'v21.0';
        $url = "https://graph.facebook.com/$ver" . $path;

        $out = []; $guard = 0;
        while ($url && $guard++ < 50) {
            $page = $this->http($url, $token);
            foreach (($page['data'] ?? []) as $d) $out[] = $d;
            $url = $page['paging']['next'] ?? '';
        }
        return $out;
    }

    private function http(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("HTTP GET failed: $err"); }
        curl_close($ch);
        $j = json_decode($res, true) ?: [];
        if ($code < 200 || $code >= 300) {
            throw new \Exception("HTTP $code: " . ($j['error']['message'] ?? $res));
        }
        return $j;
    }
}
