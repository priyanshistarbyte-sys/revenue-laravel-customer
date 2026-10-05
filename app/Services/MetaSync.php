<?php

namespace App\Services;

/**
 * Port of meta_sync.php — pulls Meta (Facebook) Ads spend via the Graph API
 * Insights edge and imports it into meta_data (global, user_id = 0 — one row per
 * campaign/date no matter how many users link to it). Pure cURL — no SDK dependency.
 *
 * Used by both the meta:sync artisan command and the /meta_sync web endpoint.
 */
class MetaSync
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('adledger.meta');
    }

    /** Run the sync. Returns ['ok' => bool, 'msg' => string]. */
    public function run(int $onlyAccount = 0, bool $force = false): array
    {
        if (empty($this->cfg['enabled'])) {
            return ['ok' => false, 'msg' => "Meta sync is disabled (set META_ENABLED=true)."];
        }

        $minH = (float) ($this->cfg['min_interval_hours'] ?? 3);
        $last = getSetting('meta_last_sync', '');
        if (!$force && $last !== '') {
            $hrs = (time() - strtotime($last)) / 3600;
            if ($hrs < $minH) {
                return ['ok' => true, 'msg' => sprintf("Skipped — last sync %s (%.1fh ago, < %sh). Use force to override.", $last, $hrs, $minH)];
            }
        }

        $pdo = getDB();
        $sql = "SELECT * FROM meta_accounts
                WHERE active = 1 AND api_token <> '' AND ad_account_ids <> '' AND owner_user_id > 0"
             . ($onlyAccount ? " AND id = " . $onlyAccount : "") . " ORDER BY name";
        $accounts = $pdo->query($sql)->fetchAll();
        if (!$accounts) {
            return ['ok' => false, 'msg' => $onlyAccount
                ? "Meta account #$onlyAccount not found, inactive, or missing token / ad accounts / owner."
                : "No sync-ready Meta accounts. Set an Owner, API Token and Ad Account ID(s) on the Meta Accounts page."];
        }

        $look  = max(0, (int) ($this->cfg['lookback_days'] ?? 3));
        $end   = date('Y-m-d');
        $start = date('Y-m-d', strtotime("-$look days"));

        $mock    = !empty($this->cfg['mock']);
        $summary = [];
        $anyOk   = false;

        $updAcc = $pdo->prepare("UPDATE meta_accounts SET last_sync = ?, last_error = ? WHERE id = ?");

        foreach ($accounts as $acc) {
            try {
                $rows = $mock ? $this->mockRows($end) : $this->fetchRows($acc, $start, $end);
                $r    = $this->importRows($pdo, $rows);
                $updAcc->execute([date('Y-m-d H:i:s'), '', $acc['id']]);
                $summary[] = "{$acc['name']}: {$r['total']} rows ({$r['inserted']} new, {$r['updated']} upd, {$r['skipped']} skip)";
                $anyOk = true;
            } catch (\Throwable $e) {
                $updAcc->execute([$acc['last_sync'], date('Y-m-d H:i:s') . ' — ' . $e->getMessage(), $acc['id']]);
                $summary[] = "{$acc['name']}: ERROR " . $e->getMessage();
            }
        }

        if ($anyOk) $this->setSetting('meta_last_sync', date('Y-m-d H:i:s'));
        $msg = "Meta sync ($start..$end)" . ($mock ? ' [MOCK]' : '') . " — " . implode(' | ', $summary);
        return ['ok' => $anyOk, 'msg' => $msg];
    }

    /** Diagnostic: list the ad accounts each token can see. */
    public function listAdAccounts(int $onlyAccount = 0): string
    {
        $pdo = getDB();
        $sql = "SELECT * FROM meta_accounts WHERE active = 1 AND api_token <> ''" . ($onlyAccount ? " AND id = " . $onlyAccount : "") . " ORDER BY name";
        $out = '';
        foreach ($pdo->query($sql)->fetchAll() as $acc) {
            $out .= "=== {$acc['name']} ===\n";
            try {
                foreach ($this->fetchAdAccounts($acc) as $r) {
                    $out .= "  {$r['id']}   |   {$r['currency']}   |   {$r['name']}\n";
                }
            } catch (\Throwable $e) {
                $out .= "  ERROR: " . $e->getMessage() . "\n";
            }
        }
        return $out ?: "No token-enabled Meta accounts.\n";
    }

    private function setSetting(string $key, string $val): void
    {
        getDB()->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?,?,NOW(),NOW())
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $val]);
    }

    private function importRows(\PDO $pdo, array $rows): array
    {
        $ins = $pdo->prepare("
            INSERT INTO meta_data (user_id, campaign_name, date, amount_spent, impressions, reach, results, frequency, currency, amount_spent_raw, website_url, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,'',NOW(),NOW())
            ON DUPLICATE KEY UPDATE
                amount_spent = VALUES(amount_spent), impressions = VALUES(impressions), reach = VALUES(reach),
                results = VALUES(results), frequency = VALUES(frequency), currency = VALUES(currency),
                amount_spent_raw = VALUES(amount_spent_raw), updated_at = NOW()
        ");
        $total = $inserted = $updated = $skipped = 0;
        $pdo->beginTransaction();   // one commit for the whole batch, not one per row
        try {
            foreach ($rows as $r) {
                $camp = trim((string) ($r['campaign_name'] ?? ''));
                $date = trim((string) ($r['date'] ?? ''));
                if ($camp === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }

                $total++;
                $ins->execute([
                    0, $camp, $date,
                    (float) ($r['amount_spent'] ?? 0), (int) ($r['impressions'] ?? 0), (int) ($r['reach'] ?? 0),
                    (int) ($r['results'] ?? 0), (float) ($r['frequency'] ?? 0), (string) ($r['currency'] ?? 'USD'),
                    (float) ($r['amount_raw'] ?? 0),
                ]);
                $ins->rowCount() === 1 ? $inserted++ : $updated++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return compact('total', 'inserted', 'updated', 'skipped');
    }

    private function normAct(string $id): string
    {
        $id = trim($id);
        if ($id === '') return '';
        return str_starts_with($id, 'act_') ? $id : 'act_' . preg_replace('/\D/', '', $id);
    }

    private function fetchAdAccounts(array $acc): array
    {
        $token = trim((string) $acc['api_token']);
        if ($token === '') throw new \Exception("no API token");
        $ver  = $this->cfg['api_version'] ?? 'v21.0';
        $page = $this->http("https://graph.facebook.com/$ver/me/adaccounts?fields=name,currency,account_id&limit=200", $token);
        $out = [];
        foreach (($page['data'] ?? []) as $a) {
            $out[] = ['id' => $a['id'] ?? ('act_' . ($a['account_id'] ?? '')), 'currency' => $a['currency'] ?? '?', 'name' => $a['name'] ?? ''];
        }
        return $out;
    }

    private function fetchRows(array $acc, string $start, string $end): array
    {
        $token = trim((string) $acc['api_token']);
        if ($token === '') throw new \Exception("account '{$acc['name']}' has no API token");
        $ver    = $this->cfg['api_version'] ?? 'v21.0';
        $base   = "https://graph.facebook.com/$ver";
        $actIds = array_filter(array_map('trim', explode(',', (string) $acc['ad_account_ids'])));
        if (!$actIds) throw new \Exception("account '{$acc['name']}' has no ad account IDs");
        $resultAction = trim((string) ($this->cfg['results_action'] ?? ''));

        $rows = [];
        foreach ($actIds as $rawAct) {
            $act = $this->normAct($rawAct);
            if ($act === 'act_') continue;

            $info = $this->http("$base/$act?fields=currency", $token);
            $cur  = strtoupper((string) ($info['currency'] ?? 'USD'));

            $fields = 'campaign_name,spend,impressions,reach,frequency,date_start' . ($resultAction ? ',actions' : '');
            $q = http_build_query([
                'level'          => 'campaign',
                'time_increment' => 1,
                'time_range'     => json_encode(['since' => $start, 'until' => $end]),
                'fields'         => $fields,
                'limit'          => 500,
            ]);
            $url = "$base/$act/insights?$q";
            $guard = 0;
            while ($url && $guard++ < 200) {
                $page = $this->http($url, $token);
                foreach (($page['data'] ?? []) as $d) {
                    $rows[] = $this->normalizeRow($d, $cur, $resultAction);
                }
                $url = $page['paging']['next'] ?? '';
            }
        }
        return $rows;
    }

    private function normalizeRow(array $d, string $currency, string $resultAction): array
    {
        $spendRaw = (float) ($d['spend'] ?? 0);
        return [
            'campaign_name' => (string) ($d['campaign_name'] ?? ''),
            'date'          => (string) ($d['date_start'] ?? ''),
            'amount_raw'    => $spendRaw,
            'amount_spent'  => round($spendRaw * getCurrencyRate($currency), 4),
            'currency'      => $currency,
            'impressions'   => (int) ($d['impressions'] ?? 0),
            'reach'         => (int) ($d['reach'] ?? 0),
            'frequency'     => (float) ($d['frequency'] ?? 0),
            'results'       => $this->results($d['actions'] ?? [], $resultAction),
        ];
    }

    private function results(array $actions, string $type): int
    {
        if ($type === '') return 0;
        foreach ($actions as $a) {
            if (($a['action_type'] ?? '') === $type) return (int) round((float) ($a['value'] ?? 0));
        }
        return 0;
    }

    private function http(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $out  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("HTTP GET failed: $err"); }
        curl_close($ch);
        $j = json_decode($out, true) ?: [];
        if ($code < 200 || $code >= 300) {
            $m = $j['error']['message'] ?? $out;
            throw new \Exception("HTTP $code: $m");
        }
        return $j;
    }

    private function mockRows(string $date): array
    {
        return [
            ['campaign_name' => 'API Test Campaign A', 'date' => $date, 'amount_raw' => 12.34,
             'amount_spent' => round(12.34 * getCurrencyRate('USD'), 4), 'currency' => 'USD',
             'impressions' => 1000, 'reach' => 800, 'frequency' => 1.25, 'results' => 5],
            ['campaign_name' => 'API Test Campaign B', 'date' => $date, 'amount_raw' => 7.89,
             'amount_spent' => round(7.89 * getCurrencyRate('USD'), 4), 'currency' => 'USD',
             'impressions' => 500, 'reach' => 420, 'frequency' => 1.19, 'results' => 2],
        ];
    }
}
