<?php

namespace App\Services;

/**
 * Port of gam_sync.php — pulls GAM revenue from every active GAM-enabled ADX
 * network via the Ad Manager REST API and imports it into gam_data (GLOBAL,
 * user_id = 0). Pure cURL — no SDK dependency.
 *
 * Used by both the gam:sync artisan command and the /gam_sync web endpoint.
 */
class GamSync
{
    /**
     * Direct mode (config gam.direct_reports, default on): the app defines the
     * report itself — no saved report has to be built in the GAM UI. Each ADX
     * network gets one hidden report per kind, created on first sync, and its
     * date range is patched before every run. The report id is remembered in
     * settings (gam_auto_report_<adxId>_<kind>); if it's deleted in GAM a new one
     * is created. Column order is fixed here, so these maps replace the
     * configurable column_map / hourly_column_map.
     */
    private const DIRECT_DAILY = [
        'dimensions' => ['SITE', 'DATE'],
        'metrics'    => ['AD_EXCHANGE_REVENUE', 'AD_EXCHANGE_CTR', 'AD_EXCHANGE_IMPRESSIONS', 'AD_EXCHANGE_TOTAL_REQUESTS', 'AD_EXCHANGE_CLICKS'],
        'map'        => [
            'site' => ['dim', 0], 'date' => ['dim', 1],
            'revenue_usd' => ['metric', 0], 'ctr' => ['metric', 1], 'impressions' => ['metric', 2],
            'total_requests' => ['metric', 3], 'clicks' => ['metric', 4],
            'match_rate' => ['metric', -1], 'ecpm' => ['metric', -1],
        ],
    ];
    private const DIRECT_HOURLY = [
        'dimensions' => ['SITE', 'DATE', 'HOUR'],
        'metrics'    => ['AD_EXCHANGE_CTR', 'AD_EXCHANGE_REVENUE', 'AD_EXCHANGE_IMPRESSIONS', 'AD_EXCHANGE_TOTAL_REQUESTS'],
        'map'        => [
            'site' => ['dim', 0], 'date' => ['dim', 1], 'hour' => ['dim', 2],
            'ctr' => ['metric', 0], 'revenue' => ['metric', 1], 'impressions' => ['metric', 2],
            'total_requests' => ['metric', 3],
        ],
    ];
    private const DIRECT_COUNTRY = [
        'dimensions' => ['SITE', 'DATE', 'COUNTRY_NAME'],
        'metrics'    => ['AD_EXCHANGE_CTR', 'AD_EXCHANGE_REVENUE', 'AD_EXCHANGE_IMPRESSIONS', 'AD_EXCHANGE_TOTAL_REQUESTS'],
        'map'        => [
            'site' => ['dim', 0], 'date' => ['dim', 1], 'country' => ['dim', 2],
            'ctr' => ['metric', 0], 'revenue' => ['metric', 1], 'impressions' => ['metric', 2],
            'total_requests' => ['metric', 3],
        ],
    ];
    // The API returns AD_EXCHANGE_CTR as a 0..1 ratio; gam_hourly / gam_country store a percent.
    private const DIRECT_HOURLY_CTR_SCALE = 100;

    /*
     * SOAP mode (config gam.report_api = 'soap', default): the same three reports
     * run as ad-hoc ReportService jobs (runReportJob → CSV download). Nothing is
     * created or saved in the GAM account. Keys are SOAP Dimension / Column enums;
     * the CSV_DUMP header names each column "Dimension.X" / "Column.X".
     */
    private const SOAP_DAILY = [
        'dimensions' => ['SITE_NAME', 'DATE'],
        'columns'    => ['AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE', 'AD_EXCHANGE_LINE_ITEM_LEVEL_CTR', 'AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS', 'AD_EXCHANGE_TOTAL_REQUESTS', 'AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS'],
    ];
    private const SOAP_HOURLY = [
        'dimensions' => ['SITE_NAME', 'DATE', 'HOUR'],
        'columns'    => ['AD_EXCHANGE_LINE_ITEM_LEVEL_CTR', 'AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE', 'AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS', 'AD_EXCHANGE_TOTAL_REQUESTS'],
    ];
    private const SOAP_COUNTRY = [
        'dimensions' => ['SITE_NAME', 'DATE', 'COUNTRY_NAME'],
        'columns'    => ['AD_EXCHANGE_LINE_ITEM_LEVEL_CTR', 'AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE', 'AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS', 'AD_EXCHANGE_TOTAL_REQUESTS'],
    ];

    private array $cfg;
    private array $tokenCache = [];

    public function __construct()
    {
        $this->cfg = config('adledger.gam');
    }

    /**
     * Run the sync. Returns ['ok' => bool, 'msg' => string].
     * $onlyAccount limits to one ADX id; $force bypasses the throttle.
     */
    public function run(int $onlyAccount = 0, bool $force = false): array
    {
        if (empty($this->cfg['enabled'])) {
            return ['ok' => false, 'msg' => "GAM sync is disabled (set GAM_ENABLED=true)."];
        }

        // Throttle
        $minH = (float) ($this->cfg['min_interval_hours'] ?? 0.75);
        $last = getSetting('gam_last_sync', '');
        if (!$force && $last !== '') {
            $hrs = (time() - strtotime($last)) / 3600;
            if ($hrs < $minH) {
                return ['ok' => true, 'msg' => sprintf("Skipped — last sync %s (%.1fh ago, < %sh). Use force to override.", $last, $hrs, $minH)];
            }
        }

        $pdo = getDB();
        $sql = "SELECT * FROM adx WHERE active = 1 AND network_code <> ''" . ($onlyAccount ? " AND id = " . $onlyAccount : "") . " ORDER BY name";
        $accounts = $pdo->query($sql)->fetchAll();
        if (!$accounts) {
            return ['ok' => false, 'msg' => $onlyAccount
                ? "ADX network #$onlyAccount not found, inactive, or has no GAM network code."
                : "No GAM-enabled ADX networks. Add a network code on the ADX page."];
        }

        $look  = max(0, (int) ($this->cfg['lookback_days'] ?? 3));
        $end   = date('Y-m-d');
        $start = date('Y-m-d', strtotime("-$look days"));

        $colMap    = $this->cfg['column_map'] ?? [];
        $hourlyMap = $this->cfg['hourly_column_map'] ?? [];
        $mock      = !empty($this->cfg['mock']);
        $direct    = $this->direct();
        $defCur    = (string) ($this->cfg['currency'] ?? 'USD');
        $hourlyDay = date('Y-m-d', strtotime('-1 day'));   // hourly report = last complete day
        $hasHourly = in_array('hourly_report_id', array_column(
            $pdo->query("SHOW COLUMNS FROM `adx`")->fetchAll(), 'Field'), true);
        $summary = [];
        $anyOk   = false;

        $updAcc = $pdo->prepare("UPDATE adx SET last_sync = ?, last_error = ? WHERE id = ?");

        foreach ($accounts as $acc) {
            $cur = $acc['gam_currency'] ?: $defCur;
            try {
                $rows = $mock ? $this->mockRows($end) : $this->fetchRows($acc, $colMap, $start, $end);
                $r    = $this->importRows($pdo, $rows, $cur);
                $line = "{$acc['name']}: {$r['total']} rows ({$r['inserted']} new, {$r['updated']} upd, {$r['skipped']} skip)";

                // Hourly CTR report (GAM Check) — same account, same schedule.
                if ($mock || $direct || ($hasHourly && !empty($acc['hourly_report_id']))) {
                    try {
                        $hrows = $mock ? $this->mockHourlyRows($hourlyDay) : $this->fetchHourlyRows($acc, $hourlyMap, $hourlyDay, $end);
                        $scale = ($direct && !$mock) ? self::DIRECT_HOURLY_CTR_SCALE : (float) ($this->cfg['hourly_ctr_scale'] ?? 1);
                        $hr    = $this->importHourlyRows($pdo, $hrows, $cur, $hourlyDay, $scale);
                        $line .= " + hourly {$hr['total']} ({$hr['inserted']} new, {$hr['updated']} upd)";
                    } catch (\Throwable $he) {
                        $line .= " + hourly ERROR " . $he->getMessage();
                    }
                }

                // Country report (customer Country Wise) — direct mode only, same date range as daily.
                if ($direct && !$mock) {
                    try {
                        if ($this->soapMode()) {
                            $crows = array_map(fn ($r) => [
                                'site'    => $r['SITE_NAME'] ?? '',
                                'date'    => $this->normalizeDate($r['DATE'] ?? ''),
                                'country' => $r['COUNTRY_NAME'] ?? '',
                            ] + $this->soapMetrics($r), $this->soapReportRows($acc, self::SOAP_COUNTRY, $start, $end));
                        } else {
                            $raw   = $this->fetchRawRows($acc, $this->directReportId($acc, 'country', self::DIRECT_COUNTRY, $start, $end));
                            $crows = array_map(fn ($row) => $this->normalizeCountryRow($row, self::DIRECT_COUNTRY['map']), $raw);
                        }
                        $cr    = $this->importCountryRows($pdo, $crows, $cur, self::DIRECT_HOURLY_CTR_SCALE);
                        $line .= " + country {$cr['total']} ({$cr['inserted']} new, {$cr['updated']} upd)";
                    } catch (\Throwable $ce) {
                        $line .= " + country ERROR " . $ce->getMessage();
                    }
                }

                $updAcc->execute([date('Y-m-d H:i:s'), '', $acc['id']]);
                $summary[] = $line;
                $anyOk = true;
            } catch (\Throwable $e) {
                $updAcc->execute([$acc['last_sync'], date('Y-m-d H:i:s') . ' — ' . $e->getMessage(), $acc['id']]);
                $summary[] = "{$acc['name']}: ERROR " . $e->getMessage();
            }
        }

        if ($anyOk) $this->setSetting('gam_last_sync', date('Y-m-d H:i:s'));
        $msg = "GAM sync ($start..$end)" . ($mock ? ' [MOCK]' : ($direct ? ' [direct]' : '')) . " — " . implode(' | ', $summary);
        return ['ok' => $anyOk, 'msg' => $msg];
    }

    /** Diagnostic: list saved reports each GAM network's service account can see. */
    public function listReports(int $onlyAccount = 0): string
    {
        $pdo = getDB();
        $sql = "SELECT * FROM adx WHERE active = 1 AND network_code <> ''" . ($onlyAccount ? " AND id = " . $onlyAccount : "") . " ORDER BY name";
        $out = '';
        foreach ($pdo->query($sql)->fetchAll() as $acc) {
            $out .= "=== {$acc['name']} (network {$acc['network_code']}) ===\n";
            try {
                $reps = $this->fetchReports($acc);
                if (!$reps) $out .= "  (no reports visible to this service account — visibility/sharing issue)\n";
                foreach ($reps as $r) $out .= "  id = {$r['id']}   |   {$r['name']}\n";
            } catch (\Throwable $e) {
                $out .= "  ERROR: " . $e->getMessage() . "\n";
            }
        }
        return $out ?: "No GAM-enabled ADX networks.\n";
    }

    private function setSetting(string $key, string $val): void
    {
        getDB()->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?,?,NOW(),NOW())
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $val]);
    }

    private function importRows(\PDO $pdo, array $rows, string $currency): array
    {
        $ins = $pdo->prepare("
            INSERT INTO gam_data (user_id, site, date, revenue_usd, impressions, clicks, total_requests, match_rate, ctr, ecpm, gam_currency, created_at, updated_at)
            VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                revenue_usd = VALUES(revenue_usd), impressions = VALUES(impressions), clicks = VALUES(clicks),
                total_requests = VALUES(total_requests), match_rate = VALUES(match_rate), ctr = VALUES(ctr),
                ecpm = VALUES(ecpm), gam_currency = VALUES(gam_currency), updated_at = NOW()
        ");
        $total = $inserted = $updated = $skipped = 0;
        $pdo->beginTransaction();   // one commit for the whole batch, not one per row
        try {
            foreach ($rows as $r) {
                $site = trim((string) ($r['site'] ?? ''));
                $date = trim((string) ($r['date'] ?? ''));
                if ($site === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }
                $total++;
                $ins->execute([
                    $site, $date,
                    (float) ($r['revenue_usd'] ?? 0), (int) ($r['impressions'] ?? 0), (int) ($r['clicks'] ?? 0),
                    (int) ($r['total_requests'] ?? 0), (float) ($r['match_rate'] ?? 0), (float) ($r['ctr'] ?? 0),
                    (float) ($r['ecpm'] ?? 0), $currency,
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

    private function mockRows(string $date): array
    {
        return [
            ['site' => 'aarya1.jewelryrenderings.com', 'date' => $date, 'revenue_usd' => 12.34, 'impressions' => 1000, 'clicks' => 5, 'total_requests' => 2000, 'match_rate' => 85.0, 'ctr' => 0.5, 'ecpm' => 12.3],
            ['site' => 'aarya2.jewelryrenderings.com', 'date' => $date, 'revenue_usd' => 7.89,  'impressions' => 800,  'clicks' => 3, 'total_requests' => 1500, 'match_rate' => 80.0, 'ctr' => 0.4, 'ecpm' => 9.9],
        ];
    }

    /** Import normalized hourly rows into gam_hourly (GLOBAL, matched by site). */
    private function importHourlyRows(\PDO $pdo, array $rows, string $currency, string $fallbackDate, float $scale): array
    {
        $ins = $pdo->prepare("
            INSERT INTO gam_hourly (site, date, hour, ctr, revenue, impressions, total_requests, gam_currency, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                ctr = VALUES(ctr), revenue = VALUES(revenue), impressions = VALUES(impressions),
                total_requests = VALUES(total_requests), gam_currency = VALUES(gam_currency), updated_at = NOW()
        ");
        $total = $inserted = $updated = $skipped = 0;
        $pdo->beginTransaction();   // one commit for the whole batch, not one per row
        try {
            foreach ($rows as $r) {
                $site = trim((string) ($r['site'] ?? ''));
                $hour = $r['hour'];
                $date = trim((string) ($r['date'] ?? '')) ?: $fallbackDate;
                if ($site === '' || $hour === null || $hour === '' || !is_numeric($hour)
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }
                $hour = (int) $hour;
                if ($hour < 0 || $hour > 23) { $skipped++; continue; }
                $total++;
                $ins->execute([
                    $site, $date, $hour,
                    (float) ($r['ctr'] ?? 0) * $scale,
                    (float) ($r['revenue'] ?? 0),
                    (int) ($r['impressions'] ?? 0),
                    (int) ($r['total_requests'] ?? 0),
                    $currency,
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

    private function normalizeHourlyRow(array $row, array $map): array
    {
        $n = $this->rowAccessors($row, $map);
        return [
            'site'        => $n('site', 'dim', 0),
            'date'        => $this->normalizeDate($n('date', 'dim', 1)),
            'hour'        => $n('hour', 'dim', 2),
            'ctr'         => (float) ($n('ctr', 'metric', 0) ?? 0),
            'revenue'     => (float) ($n('revenue', 'metric', -1) ?? 0),
            'impressions' => (int) ($n('impressions', 'metric', -1) ?? 0),
            'total_requests' => (int) ($n('total_requests', 'metric', -1) ?? 0),
        ];
    }

    /** Import normalized Site × Date × Country rows into gam_country (GLOBAL). */
    private function importCountryRows(\PDO $pdo, array $rows, string $currency, float $scale): array
    {
        $ins = $pdo->prepare("
            INSERT INTO gam_country (site, date, country, ctr, revenue, impressions, total_requests, gam_currency, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                ctr = VALUES(ctr), revenue = VALUES(revenue), impressions = VALUES(impressions),
                total_requests = VALUES(total_requests), gam_currency = VALUES(gam_currency), updated_at = NOW()
        ");
        $total = $inserted = $updated = $skipped = 0;
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $site    = trim((string) ($r['site'] ?? ''));
                $country = trim((string) ($r['country'] ?? ''));
                $date    = trim((string) ($r['date'] ?? ''));
                if ($site === '' || $country === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }
                $total++;
                $ins->execute([
                    $site, $date, mb_substr($country, 0, 100),
                    (float) ($r['ctr'] ?? 0) * $scale,
                    (float) ($r['revenue'] ?? 0),
                    (int) ($r['impressions'] ?? 0),
                    (int) ($r['total_requests'] ?? 0),
                    $currency,
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

    private function normalizeCountryRow(array $row, array $map): array
    {
        $n = $this->rowAccessors($row, $map);
        return [
            'site'           => $n('site', 'dim', 0),
            'date'           => $this->normalizeDate($n('date', 'dim', 1)),
            'country'        => $n('country', 'dim', 2),
            'ctr'            => (float) ($n('ctr', 'metric', 0) ?? 0),
            'revenue'        => (float) ($n('revenue', 'metric', 1) ?? 0),
            'impressions'    => (int) ($n('impressions', 'metric', 2) ?? 0),
            'total_requests' => (int) ($n('total_requests', 'metric', 3) ?? 0),
        ];
    }

    private function mockHourlyRows(string $date): array
    {
        $rows = [];
        // aarya1 — last 3 hours 0% CTR (flagged by GAM Check).
        foreach ([1.1, 0.9, 1.0, 0.0, 0.0, 0.0] as $h => $ctr) {
            $rows[] = ['site' => 'aarya1.jewelryrenderings.com', 'date' => $date, 'hour' => $h, 'ctr' => $ctr, 'revenue' => 5.0, 'impressions' => 100];
        }
        // aarya2 — healthy.
        foreach ([0.5, 0.6, 0.4, 0.7, 0.5, 0.6] as $h => $ctr) {
            $rows[] = ['site' => 'aarya2.jewelryrenderings.com', 'date' => $date, 'hour' => $h, 'ctr' => $ctr, 'revenue' => 3.0, 'impressions' => 80];
        }
        return $rows;
    }

    private function fetchRows(array $acc, array $columnMap, string $start, string $end): array
    {
        if ($this->soapMode()) {
            $scale = (float) ($this->cfg['ctr_scale'] ?? 1);
            return array_map(function ($r) use ($scale) {
                $m = $this->soapMetrics($r);
                return [
                    'site' => $r['SITE_NAME'] ?? '', 'date' => $this->normalizeDate($r['DATE'] ?? ''),
                    'revenue_usd' => $m['revenue'], 'impressions' => $m['impressions'], 'clicks' => $m['clicks'],
                    'total_requests' => $m['total_requests'], 'match_rate' => null,
                    'ctr' => $m['ctr'] * $scale, 'ecpm' => 0,
                ];
            }, $this->soapReportRows($acc, self::SOAP_DAILY, $start, $end));
        }
        if ($this->direct()) {
            $raw = $this->fetchRawRows($acc, $this->directReportId($acc, 'daily', self::DIRECT_DAILY, $start, $end));
            return array_map(fn ($row) => $this->normalizeRow($row, self::DIRECT_DAILY['map']), $raw);
        }
        if (empty($acc['saved_report_id'])) throw new \Exception("account '{$acc['name']}' missing saved_report_id");
        $raw = $this->fetchRawRows($acc, (string) $acc['saved_report_id']);
        return array_map(fn ($row) => $this->normalizeRow($row, $columnMap), $raw);
    }

    /**
     * Fetch the hourly Ad Exchange report (Site × Hour × CTR) for one account.
     * Returns normalized rows ready for importHourlyRows().
     */
    private function fetchHourlyRows(array $acc, array $columnMap, string $start, string $end): array
    {
        if ($this->soapMode()) {
            return array_map(fn ($r) => [
                'site' => $r['SITE_NAME'] ?? '',
                'date' => $this->normalizeDate($r['DATE'] ?? ''),
                'hour' => $r['HOUR'] ?? null,
            ] + $this->soapMetrics($r), $this->soapReportRows($acc, self::SOAP_HOURLY, $start, $end));
        }
        if ($this->direct()) {
            $raw = $this->fetchRawRows($acc, $this->directReportId($acc, 'hourly', self::DIRECT_HOURLY, $start, $end));
            return array_map(fn ($row) => $this->normalizeHourlyRow($row, self::DIRECT_HOURLY['map']), $raw);
        }
        if (empty($acc['hourly_report_id'])) throw new \Exception("account '{$acc['name']}' missing hourly_report_id");
        $raw = $this->fetchRawRows($acc, (string) $acc['hourly_report_id']);
        return array_map(fn ($row) => $this->normalizeHourlyRow($row, $columnMap), $raw);
    }

    private function direct(): bool
    {
        return !empty($this->cfg['direct_reports']);
    }

    /** Direct mode via ad-hoc SOAP report jobs (nothing saved in GAM). */
    private function soapMode(): bool
    {
        return $this->direct() && strtolower((string) ($this->cfg['report_api'] ?? 'soap')) === 'soap';
    }

    /** Pull the shared Ad Exchange metrics out of a SOAP CSV row (CTR stays a 0..1 ratio). */
    private function soapMetrics(array $r): array
    {
        $div = (float) ($this->cfg['soap_money_divisor'] ?? 1000000) ?: 1;
        return [
            'ctr'            => (float) ($r['AD_EXCHANGE_LINE_ITEM_LEVEL_CTR'] ?? 0),
            'revenue'        => (float) ($r['AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE'] ?? 0) / $div,
            'impressions'    => (int) ($r['AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS'] ?? 0),
            'clicks'         => (int) ($r['AD_EXCHANGE_LINE_ITEM_LEVEL_CLICKS'] ?? 0),
            'total_requests' => (int) ($r['AD_EXCHANGE_TOTAL_REQUESTS'] ?? 0),
        ];
    }

    /**
     * Run an ad-hoc report through SOAP ReportService (runReportJob → poll →
     * CSV_DUMP download). No report is saved in the GAM account. Returns rows
     * keyed by the Dimension / Column enum name (e.g. SITE_NAME, DATE).
     */
    private function soapReportRows(array $acc, array $spec, string $start, string $end): array
    {
        foreach (['network_code', 'key_file'] as $k) {
            if (empty($acc[$k])) throw new \Exception("account '{$acc['name']}' missing $k");
        }
        if (!is_file($acc['key_file'])) throw new \Exception("key file not found: {$acc['key_file']}");

        $token = $this->accessToken($acc['key_file'], 'https://www.googleapis.com/auth/dfp');
        $net   = (string) $acc['network_code'];
        $date  = fn (string $tag, string $d) => "<$tag><year>" . (int) substr($d, 0, 4) . '</year><month>' . (int) substr($d, 5, 2)
                                             . '</month><day>' . (int) substr($d, 8, 2) . "</day></$tag>";

        // ReportQuery is an xsd:sequence — element order matters.
        $query = '';
        foreach ($spec['dimensions'] as $d) $query .= "<dimensions>$d</dimensions>";
        foreach ($spec['columns'] as $c)    $query .= "<columns>$c</columns>";
        $query .= $date('startDate', $start) . $date('endDate', $end)
                . '<dateRangeType>CUSTOM_DATE</dateRangeType><timeZoneType>PUBLISHER</timeZoneType>';

        $resp  = $this->soap($token, $net, "<runReportJob xmlns=\"%NS%\"><reportJob><reportQuery>$query</reportQuery></reportJob></runReportJob>");
        $jobId = $this->soapValue($resp, 'id');
        if (!$jobId) throw new \Exception('runReportJob returned no job id');

        $deadline = time() + 300;
        do {
            if (time() > $deadline) throw new \Exception('report job timed out');
            sleep(3);
            $status = $this->soapValue($this->soap($token, $net,
                "<getReportJobStatus xmlns=\"%NS%\"><reportJobId>$jobId</reportJobId></getReportJobStatus>"), 'rval');
            if ($status === 'FAILED') throw new \Exception('report job failed');
        } while ($status !== 'COMPLETED');

        $url = $this->soapValue($this->soap($token, $net,
            "<getReportDownloadURL xmlns=\"%NS%\"><reportJobId>$jobId</reportJobId><exportFormat>CSV_DUMP</exportFormat></getReportDownloadURL>"), 'rval');
        if (!$url) throw new \Exception('no report download URL');

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true]);
        $data = curl_exec($ch);
        if ($data === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("report download failed: $err"); }
        curl_close($ch);
        if (str_starts_with($data, "\x1f\x8b")) $data = gzdecode($data);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, (string) $data);
        rewind($fh);
        $head = fgetcsv($fh);
        if (!$head) { fclose($fh); return []; }
        // "Dimension.SITE_NAME" → "SITE_NAME"
        $head = array_map(fn ($h) => preg_replace('/^.*\./', '', trim((string) $h, "\xEF\xBB\xBF \t")), $head);
        $rows = [];
        while (($line = fgetcsv($fh)) !== false) {
            if (count($line) !== count($head)) continue;
            $rows[] = array_combine($head, $line);
        }
        fclose($fh);
        return $rows;
    }

    /** POST a SOAP operation to GAM ReportService and return the raw response XML. */
    private function soap(string $token, string $network, string $bodyTemplate): string
    {
        $au      = $this->cfg['ad_unit'] ?? [];
        $version = (string) ($au['soap_version'] ?? 'v202605');
        $ns      = "https://www.google.com/apis/ads/publisher/$version";
        $x       = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Header><RequestHeader xmlns="' . $ns . '">'
            . '<networkCode>' . $x($network) . '</networkCode>'
            . '<applicationName>' . $x($au['application_name'] ?? 'Amaira') . '</applicationName>'
            . '</RequestHeader></soap:Header>'
            . '<soap:Body>' . str_replace('%NS%', $ns, $bodyTemplate) . '</soap:Body></soap:Envelope>';

        $ch = curl_init("https://ads.google.com/apis/ads/publisher/$version/ReportService");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""', 'Authorization: Bearer ' . $token]]);
        $out  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("SOAP request failed: $err"); }
        curl_close($ch);

        $fault = $this->soapValue($out, 'faultstring') ?? $this->soapValue($out, 'errorString');
        if ($fault !== null) throw new \Exception("GAM error: $fault");
        if ($code < 200 || $code >= 300) throw new \Exception("GAM HTTP $code: " . substr($out, 0, 300));
        return $out;
    }

    /** First <tag> value from a namespaced SOAP response. */
    private function soapValue(string $xml, string $tag): ?string
    {
        $t = preg_quote($tag, '/');
        return preg_match("/<(?:\\w+:)?$t>([^<]*)<\\/(?:\\w+:)?$t>/", $xml, $m)
            ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1) : null;
    }

    /**
     * Id of this network's app-managed report of $kind, with its definition set to
     * $spec over $start..$end. Patches the remembered report; creates a new hidden
     * one if there is none yet or it was deleted in GAM.
     */
    private function directReportId(array $acc, string $kind, array $spec, string $start, string $end): string
    {
        foreach (['network_code', 'key_file'] as $k) {
            if (empty($acc[$k])) throw new \Exception("account '{$acc['name']}' missing $k");
        }
        if (!is_file($acc['key_file'])) throw new \Exception("key file not found: {$acc['key_file']}");

        $token = $this->accessToken($acc['key_file']);
        $net   = rawurlencode($acc['network_code']);
        $base  = "https://admanager.googleapis.com/v1";
        $date  = fn (string $d) => ['year' => (int) substr($d, 0, 4), 'month' => (int) substr($d, 5, 2), 'day' => (int) substr($d, 8, 2)];
        $body  = [
            'displayName'      => "Amaira auto — $kind (managed by app, do not edit)",
            'visibility'       => 'HIDDEN',
            'reportDefinition' => [
                'dimensions'     => $spec['dimensions'],
                'metrics'        => $spec['metrics'],
                'dateRange'      => ['fixedDateRange' => ['startDate' => $date($start), 'endDate' => $date($end)]],
                'reportType'     => 'HISTORICAL',
                'timeZoneSource' => 'PUBLISHER',
            ],
        ];

        $key = "gam_auto_report_{$acc['id']}_$kind";
        $id  = getSetting($key, '');
        if ($id !== '') {
            try {
                $this->http('PATCH', "$base/networks/$net/reports/" . rawurlencode($id) . "?updateMask=reportDefinition", $token, $body);
                return $id;
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'HTTP 404')) throw $e;   // gone → recreate below
            }
        }

        $created = $this->http('POST', "$base/networks/$net/reports", $token, $body);
        $id = (string) ($created['reportId'] ?? (preg_match('#/reports/([^/]+)$#', $created['name'] ?? '', $m) ? $m[1] : ''));
        if ($id === '') throw new \Exception('report create returned no id: ' . json_encode($created));
        $this->setSetting($key, $id);
        return $id;
    }

    /** Run a GAM saved report by id and return its raw fetched rows (API shape). */
    private function fetchRawRows(array $acc, string $reportId): array
    {
        [$token, $resultName, $base] = $this->runReport($acc, $reportId);

        $rows = [];
        $pageToken = '';
        do {
            $url  = "$base/{$resultName}:fetchRows?pageSize=1000" . ($pageToken ? "&pageToken=" . rawurlencode($pageToken) : '');
            $page = $this->http('GET', $url, $token);
            foreach (($page['rows'] ?? []) as $row) $rows[] = $row;
            $pageToken = $page['nextPageToken'] ?? '';
        } while ($pageToken);

        return $rows;
    }

    /**
     * Run a saved report to completion. Returns [accessToken, resultName, apiBaseUrl]
     * so the caller can fetch pages. Shared by fetchRawRows() and fetchFirstPage().
     */
    private function runReport(array $acc, string $reportId): array
    {
        foreach (['network_code', 'key_file'] as $k) {
            if (empty($acc[$k])) throw new \Exception("account '{$acc['name']}' missing $k");
        }
        if ($reportId === '') throw new \Exception("account '{$acc['name']}' missing report id");
        if (!is_file($acc['key_file'])) throw new \Exception("key file not found: {$acc['key_file']}");

        $token = $this->accessToken($acc['key_file']);
        $net   = rawurlencode($acc['network_code']);
        $rid   = rawurlencode($reportId);
        $base  = "https://admanager.googleapis.com/v1";

        $op = $this->http('POST', "$base/networks/$net/reports/$rid:run", $token, new \stdClass());
        $opName = $op['name'] ?? null;
        if (!$opName) throw new \Exception('report run returned no operation name');

        $deadline = time() + 300;
        do {
            if (time() > $deadline) throw new \Exception('report timed out');
            sleep(3);
            $status = $this->http('GET', "$base/$opName", $token);
        } while (empty($status['done']));
        if (!empty($status['error'])) throw new \Exception('report error: ' . json_encode($status['error']));

        $resp = $status['response'] ?? [];
        $resultName = $resp['reportResult'] ?? $resp['report_result'] ?? $resp['name']
                   ?? (isset($resp['result']) && is_string($resp['result']) ? $resp['result'] : null);
        if (!$resultName) throw new \Exception('report finished but no result name in operation response: ' . json_encode($resp));

        return [$token, $resultName, $base];
    }

    /** Run a saved report and fetch only its first page (for the dump_rows diagnostic). */
    private function fetchFirstPage(array $acc, string $reportId): array
    {
        [$token, $resultName, $base] = $this->runReport($acc, $reportId);
        $page = $this->http('GET', "$base/{$resultName}:fetchRows?pageSize=1000", $token);
        return ['resultName' => $resultName, 'page' => $page];
    }

    /**
     * Diagnostic (?dump_rows=1): for each GAM network, run its saved report and dump
     * the first page's structure (resultName, top-level keys, header/schema fields)
     * plus the raw rows — the port of gam_sync.php's --dump-rows / ?dump_rows=1.
     */
    public function dumpRows(int $onlyAccount = 0): string
    {
        $pdo = getDB();
        $sql = "SELECT * FROM adx WHERE active = 1 AND network_code <> ''" . ($onlyAccount ? " AND id = " . $onlyAccount : "") . " ORDER BY name";
        $out = '';
        foreach ($pdo->query($sql)->fetchAll() as $acc) {
            $out .= "=== {$acc['name']} (network {$acc['network_code']}) ===\n";
            try {
                $rid  = $this->direct()
                    ? $this->directReportId($acc, 'daily', self::DIRECT_DAILY, date('Y-m-d', strtotime('-' . max(0, (int) ($this->cfg['lookback_days'] ?? 3)) . ' days')), date('Y-m-d'))
                    : (string) ($acc['saved_report_id'] ?? '');
                $fp   = $this->fetchFirstPage($acc, $rid);
                $rows = $fp['page']['rows'] ?? [];
                $out .= "  resultName: {$fp['resultName']}\n";
                $out .= "  page top-level keys: " . implode(', ', array_keys($fp['page'])) . "\n";
                $out .= "  rows on first page: " . count($rows) . "\n";
                foreach ($fp['page'] as $k => $v) {                 // header/schema keys in full
                    if ($k === 'rows') continue;
                    $out .= "  [$k]: " . json_encode($v, JSON_UNESCAPED_SLASHES) . "\n";
                }
                $out .= "  rows:\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            } catch (\Throwable $e) {
                $out .= "  ERROR: " . $e->getMessage() . "\n";
            }
        }
        return $out ?: "No GAM-enabled ADX networks.\n";
    }

    private function fetchReports(array $acc): array
    {
        foreach (['network_code', 'key_file'] as $k) {
            if (empty($acc[$k])) throw new \Exception("account '{$acc['name']}' missing $k");
        }
        if (!is_file($acc['key_file'])) throw new \Exception("key file not found: {$acc['key_file']}");
        $token = $this->accessToken($acc['key_file']);
        $net   = rawurlencode($acc['network_code']);
        $base  = "https://admanager.googleapis.com/v1";
        $out = []; $pageToken = '';
        do {
            $url  = "$base/networks/$net/reports?pageSize=200" . ($pageToken ? "&pageToken=" . rawurlencode($pageToken) : '');
            $page = $this->http('GET', $url, $token);
            foreach (($page['reports'] ?? []) as $r) {
                $name = $r['name'] ?? '';
                $id   = $r['reportId'] ?? (preg_match('#/reports/(\d+)#', $name, $m) ? $m[1] : $name);
                $out[] = ['id' => $id, 'name' => $r['displayName'] ?? $r['name'] ?? ''];
            }
            $pageToken = $page['nextPageToken'] ?? '';
        } while ($pageToken);
        return $out;
    }

    /** Build a $get($field,$defSrc,$defIdx) accessor over one API row + column map. */
    private function rowAccessors(array $row, array $map): callable
    {
        $dims = $row['dimensionValues'] ?? [];
        $mets = $row['metricValueGroups'] ?? [];

        $dimVal = function ($i) use ($dims) {
            $v = $dims[$i] ?? null;
            if (!is_array($v)) return null;
            return $v['stringValue'] ?? $v['intValue'] ?? $v['doubleValue'] ?? ($v['dateValue'] ?? null);
        };
        $metVal = function ($i) use ($mets) {
            // One metricValueGroup per date range; its primaryValues hold one value per metric.
            $p = $mets[0]['primaryValues'][$i] ?? null;
            if (!is_array($p)) return null;
            return $p['doubleValue'] ?? $p['intValue'] ?? $p['microValue'] ?? ($p['stringValue'] ?? null);
        };
        return function ($field, $defSrc, $defIdx) use ($map, $dimVal, $metVal) {
            $spec = $map[$field] ?? null;
            if (!is_array($spec) || count($spec) < 2) $spec = [$defSrc, $defIdx];
            [$src, $idx] = $spec;
            if ($idx < 0) return null;
            return $src === 'metric' ? $metVal($idx) : $dimVal($idx);
        };
    }

    private function normalizeRow(array $row, array $map): array
    {
        $get = $this->rowAccessors($row, $map);

        return [
            'site'           => $get('site', 'dim', 0),
            'date'           => $this->normalizeDate($get('date', 'dim', 1)),
            'revenue_usd'    => (float) ($get('revenue_usd', 'metric', 0) ?? 0),
            'impressions'    => $get('impressions', 'metric', -1),
            'clicks'         => $get('clicks', 'metric', -1),
            'total_requests' => $get('total_requests', 'metric', -1),
            'match_rate'     => $get('match_rate', 'metric', -1),
            'ctr'            => ($ctr = $get('ctr', 'metric', -1)) !== null ? (float) $ctr * (float) ($this->cfg['ctr_scale'] ?? 1) : null,
            'ecpm'           => (float) ($get('ecpm', 'metric', -1) ?? 0),
        ];
    }

    private function normalizeDate($d): string
    {
        if (is_array($d)) return sprintf('%04d-%02d-%02d', (int) ($d['year'] ?? 0), (int) ($d['month'] ?? 0), (int) ($d['day'] ?? 0));
        $s = (string) $d;
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) return "$m[1]-$m[2]-$m[3]";
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : '';
    }

    private function accessToken(string $keyFile, string $scope = 'https://www.googleapis.com/auth/admanager'): string
    {
        $ck = "$keyFile|$scope";
        if (isset($this->tokenCache[$ck]) && $this->tokenCache[$ck]['exp'] > time() + 60) {
            return $this->tokenCache[$ck]['tok'];
        }
        $key = json_decode((string) file_get_contents($keyFile), true);
        if (empty($key['client_email']) || empty($key['private_key'])) throw new \Exception('invalid service-account key file');

        $now = time();
        $claim = ['iss' => $key['client_email'], 'scope' => $scope,
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
        $this->tokenCache[$ck] = ['tok' => $j['access_token'], 'exp' => $now + 3600];
        return $j['access_token'];
    }

    private function http(string $method, string $url, string $token, $jsonBody = null): array
    {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60];
        if ($jsonBody !== null) { $headers[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody); }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $out  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("HTTP $method failed: $err"); }
        curl_close($ch);
        if ($code < 200 || $code >= 300) throw new \Exception("HTTP $code for $url: $out");
        return json_decode($out, true) ?: [];
    }
}
