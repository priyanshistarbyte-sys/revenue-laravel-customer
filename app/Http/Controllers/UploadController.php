<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of upload.php + delete_data.php — Meta/GAM CSV upload and processing,
 * plus delete-by-date. Admin only.
 */
class UploadController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Upload Data';
        $activePage = 'upload';
        $flash = session('flash');

        if ($request->isMethod('post')) {
            if (!userCan('upload', 'add')) {
                abort(403, 'You do not have permission to upload data.');
            }
            $type = $request->input('type', '');
            $file = $request->file('csv_file');

            if (in_array($type, ['meta', 'gam', 'hourly']) && $file && $file->isValid()) {
                $origName = $file->getClientOriginalName();
                if (!str_ends_with(strtolower($origName), '.csv')) {
                    $flash = ['type' => 'error', 'msg' => 'Only .csv files are accepted.'];
                } else {
                    $ownerId = currentUserId();
                    $path = $file->getRealPath();
                    if ($type === 'meta') {
                        $metaCurrency = strtoupper(trim((string) $request->input('currency', getDefaultCurrency()['code'])));
                        $flash = $this->processMeta($path, $metaCurrency, $ownerId);
                    } elseif ($type === 'gam') {
                        $gamCurrency = strtoupper(trim((string) $request->input('currency', 'USD')));
                        $flash = $this->processGAM($path, $gamCurrency, $ownerId);
                    } else { // hourly (GAM Check)
                        $gamCurrency = strtoupper(trim((string) $request->input('currency', 'USD')));
                        $reportDate  = trim((string) $request->input('report_date', ''));
                        $flash = $this->processHourly($path, $reportDate, $gamCurrency);
                    }
                }
            } else {
                $flash = ['type' => 'error', 'msg' => 'Please select a CSV file.'];
            }
        }

        try {
            $pdo = getDB();
            $recentMeta = $pdo->query("SELECT date, COUNT(*) as cnt, SUM(amount_spent) as total FROM meta_data GROUP BY date ORDER BY date DESC LIMIT 7")->fetchAll();
            $recentGAM  = $pdo->query("SELECT date, COUNT(*) as cnt, SUM(revenue_usd) as total FROM gam_data GROUP BY date ORDER BY date DESC LIMIT 7")->fetchAll();
            $recentHourly = $pdo->query("SELECT date, COUNT(DISTINCT site) as sites, COUNT(*) as cnt FROM gam_hourly GROUP BY date ORDER BY date DESC LIMIT 7")->fetchAll();
        } catch (\Throwable $e) {
            $recentMeta = $recentGAM = $recentHourly = [];
        }

        $gamLastSync  = getSetting('gam_last_sync', '');
        $gamCfgReady  = (bool) env('GAM_ENABLED', false);
        $gamAcctCount = 0;
        try { $gamAcctCount = (int) getDB()->query("SELECT COUNT(*) FROM adx WHERE active=1 AND network_code<>''")->fetchColumn(); } catch (\Throwable $e) {}

        return view('upload', compact(
            'pageTitle', 'activePage', 'flash', 'recentMeta', 'recentGAM', 'recentHourly',
            'gamLastSync', 'gamCfgReady', 'gamAcctCount'
        ));
    }

    /** Port of delete_data.php */
    public function delete(Request $request)
    {
        if (!userCan('upload', 'delete')) {
            abort(403, 'You do not have permission to delete uploaded data.');
        }
        $type = $request->input('type', '');
        $date = $request->input('date', '');

        if (!in_array($type, ['meta', 'gam', 'hourly']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return redirect('/upload')->with('flash', ['type' => 'error', 'msg' => 'Invalid request.']);
        }

        try {
            $pdo   = getDB();
            $table = ['meta' => 'meta_data', 'gam' => 'gam_data', 'hourly' => 'gam_hourly'][$type];
            // Meta and GAM (daily + hourly) are all global — delete everything for the date.
            $stmt  = $pdo->prepare("DELETE FROM $table WHERE date = ?");
            $stmt->execute([$date]);
            $deleted = $stmt->rowCount();
            return redirect('/upload')->with('flash', ['type' => 'success', 'msg' => "Deleted $deleted " . strtoupper($type) . " records for $date."]);
        } catch (\Throwable $e) {
            return redirect('/upload')->with('flash', ['type' => 'error', 'msg' => $e->getMessage()]);
        }
    }

    // ── CSV helpers ──────────────────────────────────────────────────

    private function normalizeCol(string $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        $h = trim($h, " \t\n\r\0\x0B\"'");
        $h = strtolower($h);
        $h = preg_replace('/[\s\-\/\(\)]+/', '_', $h);
        $h = preg_replace('/_+/', '_', $h);
        $h = trim($h, '_');
        return $h;
    }

    private function findCol(array $normHeaders, string $needle): int|false
    {
        foreach ($normHeaders as $i => $h) {
            if (str_contains($h, $needle)) return $i;
        }
        return false;
    }

    /**
     * Meta spend is global (user_id = 0), like GAM: re-uploading the same report —
     * by any user — updates the existing rows instead of adding a second copy.
     * $ownerId is only used to auto-link campaigns to the uploader's own links.
     */
    private function processMeta(string $file, string $currencyCode, int $ownerId): array
    {
        $META_OWNER = 0;
        $pdo  = getDB();
        $rate = getCurrencyRate($currencyCode);
        $handle = fopen($file, 'r');
        if (!$handle) return ['type' => 'error', 'msg' => 'Could not open file.'];

        $rawHeader = fgetcsv($handle);
        if (!$rawHeader) { fclose($handle); return ['type' => 'error', 'msg' => 'Empty file.']; }

        $header = array_map([$this, 'normalizeCol'], $rawHeader);

        $colMap = [
            'campaign'    => $this->findCol($header, 'campaign'),
            'day'         => $this->findCol($header, 'day'),
            'amount'      => $this->findCol($header, 'amount_spent'),
            'impressions' => $this->findCol($header, 'impression'),
            'reach'       => $this->findCol($header, 'reach'),
            'results'     => $this->findCol($header, 'result'),
            'frequency'   => $this->findCol($header, 'frequency'),
            'website_url' => false,
        ];

        foreach ($header as $i => $h) {
            if (str_contains($h, 'result') && !str_contains($h, 'type') && !str_contains($h, 'cost')) {
                $colMap['results'] = $i;
                break;
            }
        }
        foreach ($header as $i => $h) {
            if ((str_contains($h, 'website') && str_contains($h, 'url'))
                || $h === 'url' || str_contains($h, 'destination_url') || str_contains($h, 'landing_page')) {
                $colMap['website_url'] = $i;
                break;
            }
        }

        if ($colMap['campaign'] === false || $colMap['day'] === false || $colMap['amount'] === false) {
            fclose($handle);
            $found = implode(', ', $header);
            return ['type' => 'error', 'msg' => "Invalid Meta CSV. Could not find required columns. Detected: $found"];
        }

        $hasUrlCol = $colMap['website_url'] !== false;

        $stmt = $pdo->prepare("
            INSERT INTO meta_data (user_id, campaign_name, date, amount_spent, impressions, reach, results, frequency, currency, amount_spent_raw, website_url, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE
                amount_spent     = VALUES(amount_spent),
                impressions      = VALUES(impressions),
                reach            = VALUES(reach),
                results          = VALUES(results),
                frequency        = VALUES(frequency),
                currency         = VALUES(currency),
                amount_spent_raw = VALUES(amount_spent_raw),
                website_url      = VALUES(website_url),
                updated_at       = NOW()
        ");

        $linksByUrl = [];
        if ($hasUrlCol) {
            $linkRows = $pdo->query("SELECT id, meta_campaign, meta_host FROM links WHERE active = 1 AND user_id = " . (int) $ownerId)->fetchAll();
            foreach ($linkRows as $lr) {
                $key = $lr['meta_host'];
                if ($key !== '') {
                    $linksByUrl[$key] = [
                        'id'        => $lr['id'],
                        'campaigns' => array_filter(array_map('trim', explode(',', $lr['meta_campaign']))),
                    ];
                }
            }
        }
        $linkUpdate = $pdo->prepare("UPDATE links SET meta_campaign = ? WHERE id = ?");
        // One transaction for the whole file: a failed row only fails its own
        // statement in MySQL, so per-row skips still work, minus a commit per row.
        $pdo->beginTransaction();
        $autoLinked = 0;

        $inserted = 0; $updated = 0; $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) continue;

            $campaign   = trim($row[$colMap['campaign']] ?? '');
            $day        = trim($row[$colMap['day']] ?? '');
            $amountRaw  = (float) ($row[$colMap['amount']] ?? 0);
            $amount     = round($amountRaw * $rate, 4);
            $websiteUrl = $hasUrlCol ? normalizeUrl($row[$colMap['website_url']] ?? '') : '';

            if (!$campaign || !$day) { $skipped++; continue; }

            $dateObj = date_create($day);
            if (!$dateObj) { $skipped++; continue; }
            $dateStr = $dateObj->format('Y-m-d');

            $impr  = $colMap['impressions'] !== false ? (int) ($row[$colMap['impressions']] ?? 0) : 0;
            $reach = $colMap['reach']       !== false ? (int) ($row[$colMap['reach']]       ?? 0) : 0;
            $res   = $colMap['results']     !== false ? (int) ($row[$colMap['results']]     ?? 0) : 0;
            $freq  = $colMap['frequency']   !== false ? (float) ($row[$colMap['frequency']] ?? 0) : 0;

            if ($websiteUrl !== '' && isset($linksByUrl[$websiteUrl])) {
                $link = &$linksByUrl[$websiteUrl];
                if (!in_array($campaign, $link['campaigns'], true)) {
                    $link['campaigns'][] = $campaign;
                    $linkUpdate->execute([implode(',', $link['campaigns']), $link['id']]);
                    $autoLinked++;
                }
                unset($link);
            }

            try {
                $stmt->execute([$META_OWNER, $campaign, $dateStr, $amount, $impr, $reach, $res, $freq, $currencyCode, $amountRaw, $websiteUrl]);
                if ($stmt->rowCount() === 1) $inserted++; else $updated++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }

        $pdo->commit();
        fclose($handle);

        $msg = "Meta upload complete: $inserted new rows, $updated updated, $skipped skipped. (Currency: $currencyCode)";
        if ($hasUrlCol) {
            $msg .= $autoLinked > 0
                ? " 🔗 $autoLinked campaign(s) auto-linked via Website URL."
                : " Website URL column detected — no new auto-links found.";
        }
        return ['type' => 'success', 'msg' => $msg];
    }

    private function processGAM(string $file, string $currencyCode, int $ownerId = 0): array
    {
        $ownerId = 0; // GAM is global
        $pdo    = getDB();
        $handle = fopen($file, 'r');
        if (!$handle) return ['type' => 'error', 'msg' => 'Could not open file.'];

        $header = null;
        $attempts = 0;
        while (($row = fgetcsv($handle)) !== false && $attempts < 20) {
            $attempts++;
            $first = strtolower(trim($row[0] ?? ''));
            if ($first === 'site') {
                $header = array_map(fn ($h) => strtolower(trim(str_replace(['"', ' '], ['', '_'], $h))), $row);
                break;
            }
        }

        if (!$header) {
            fclose($handle);
            return ['type' => 'error', 'msg' => 'Could not find header row in GAM CSV. Expected a row starting with "Site".'];
        }

        $colMap = [
            'site' => 0, 'date' => array_search('date', $header),
            'revenue' => false, 'impr' => false, 'clicks' => false,
            'requests' => false, 'match' => false, 'ctr' => false, 'ecpm' => false,
        ];

        foreach ($header as $i => $h) {
            if (str_contains($h, 'revenue') && !str_contains($h, 'partner')) { $colMap['revenue'] = $i; break; }
        }
        foreach ($header as $i => $h) {
            if (str_contains($h, 'impression'))  { $colMap['impr']     = $i; }
            if (str_contains($h, 'click') && !str_contains($h, 'ctr')) { $colMap['clicks'] = $i; }
            if (str_contains($h, 'total_request')){ $colMap['requests'] = $i; }
            if (str_contains($h, 'match_rate'))  { $colMap['match']    = $i; }
            if ($h === 'ad_exchange_ctr' || str_contains($h, '_ctr')) { $colMap['ctr'] = $i; }
            if (str_contains($h, 'ecpm'))        { $colMap['ecpm']     = $i; }
        }

        if ($colMap['revenue'] === false) {
            fclose($handle);
            return ['type' => 'error', 'msg' => 'Could not find revenue column in GAM CSV.'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO gam_data (user_id, site, date, revenue_usd, impressions, clicks, total_requests, match_rate, ctr, ecpm, gam_currency, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE
                revenue_usd    = VALUES(revenue_usd),
                impressions    = VALUES(impressions),
                clicks         = VALUES(clicks),
                total_requests = VALUES(total_requests),
                match_rate     = VALUES(match_rate),
                ctr            = VALUES(ctr),
                ecpm           = VALUES(ecpm),
                gam_currency   = VALUES(gam_currency),
                updated_at     = NOW()
        ");

        $inserted = 0; $updated = 0; $skipped = 0;
        $pdo->beginTransaction();   // one commit for the whole file (see processMeta)

        while (($row = fgetcsv($handle)) !== false) {
            $site = trim($row[0] ?? '');
            if (!$site || strtolower($site) === 'total') { continue; }

            $dateRaw = $colMap['date'] !== false ? trim($row[$colMap['date']] ?? '') : '';
            $dateObj = $dateRaw ? date_create($dateRaw) : null;
            if (!$dateObj) { $skipped++; continue; }
            $dateStr = $dateObj->format('Y-m-d');

            $revenue = (float) ($row[$colMap['revenue']] ?? 0);
            $impr    = $colMap['impr']     !== false ? (int) ($row[$colMap['impr']]     ?? 0) : 0;
            $clicks  = $colMap['clicks']   !== false ? (int) ($row[$colMap['clicks']]   ?? 0) : 0;
            $reqs    = $colMap['requests'] !== false ? (int) ($row[$colMap['requests']] ?? 0) : 0;
            $match   = $colMap['match']    !== false ? (float) ($row[$colMap['match']]  ?? 0) : 0;
            $ctr     = $colMap['ctr']      !== false ? (float) ($row[$colMap['ctr']]    ?? 0) : 0;
            $ecpm    = $colMap['ecpm']     !== false ? (float) ($row[$colMap['ecpm']]   ?? 0) : 0;

            try {
                $stmt->execute([$ownerId, $site, $dateStr, $revenue, $impr, $clicks, $reqs, $match, $ctr, $ecpm, $currencyCode]);
                $affected = $stmt->rowCount();
                if ($affected === 1) $inserted++; else $updated++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }

        $pdo->commit();
        fclose($handle);
        return ['type' => 'success', 'msg' => "GAM upload complete: $inserted new rows, $updated updated, $skipped skipped. (Currency: $currencyCode)"];
    }

    /**
     * Hourly Ad Exchange report (Site × Hour with CTR) → gam_hourly, for GAM Check.
     * The GAM interactive report has no Date column (date range = one day), so the
     * report date is supplied on the form; a Date column, if present, wins per row.
     */
    private function processHourly(string $file, string $reportDate, string $currencyCode): array
    {
        $pdo    = getDB();
        $handle = fopen($file, 'r');
        if (!$handle) return ['type' => 'error', 'msg' => 'Could not open file.'];

        // Locate the header row (GAM exports have metadata rows above it).
        $header = null; $attempts = 0;
        while (($row = fgetcsv($handle)) !== false && $attempts < 20) {
            $attempts++;
            if (strtolower(trim($row[0] ?? '')) === 'site') {
                $header = array_map(fn ($h) => $this->normalizeCol($h), $row);
                break;
            }
        }
        if (!$header) {
            fclose($handle);
            return ['type' => 'error', 'msg' => 'Could not find header row (expected a row starting with "Site").'];
        }

        $col = ['site' => 0, 'hour' => false, 'date' => false, 'ctr' => false, 'revenue' => false, 'impr' => false, 'req' => false];
        foreach ($header as $i => $h) {
            if (str_contains($h, 'hour'))                              $col['hour']    = $i;
            if ($h === 'date')                                        $col['date']    = $i;
            if (str_contains($h, 'ctr'))                              $col['ctr']     = $i;
            if (str_contains($h, 'revenue') && !str_contains($h, 'partner') && $col['revenue'] === false) $col['revenue'] = $i;
            if (str_contains($h, 'impression'))                       $col['impr']    = $i;
            if (str_contains($h, 'request'))                          $col['req']     = $i;
        }
        if ($col['hour'] === false) { fclose($handle); return ['type' => 'error', 'msg' => 'Could not find an "Hour" column in the CSV.']; }
        if ($col['ctr']  === false) { fclose($handle); return ['type' => 'error', 'msg' => 'Could not find an "Ad Exchange CTR" column in the CSV.']; }

        $fallbackDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) ? $reportDate : null;
        if ($col['date'] === false && !$fallbackDate) {
            fclose($handle);
            return ['type' => 'error', 'msg' => 'This report has no Date column — pick the report date before uploading.'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO gam_hourly (site, date, hour, ctr, revenue, impressions, total_requests, gam_currency, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE
                ctr = VALUES(ctr), revenue = VALUES(revenue), impressions = VALUES(impressions),
                total_requests = VALUES(total_requests), gam_currency = VALUES(gam_currency), updated_at = NOW()
        ");

        $inserted = 0; $updated = 0; $skipped = 0;
        $pdo->beginTransaction();   // one commit for the whole file (see processMeta)
        while (($row = fgetcsv($handle)) !== false) {
            $site = trim($row[0] ?? '');
            if (!$site || strtolower($site) === 'total') continue;

            $hourRaw = trim((string) ($row[$col['hour']] ?? ''));
            if ($hourRaw === '' || !is_numeric($hourRaw)) { $skipped++; continue; }
            $hour = (int) $hourRaw;
            if ($hour < 0 || $hour > 23) { $skipped++; continue; }

            if ($col['date'] !== false) {
                $d = date_create(trim($row[$col['date']] ?? ''));
                $dateStr = $d ? $d->format('Y-m-d') : $fallbackDate;
            } else {
                $dateStr = $fallbackDate;
            }
            if (!$dateStr) { $skipped++; continue; }

            $ctr  = $this->parseNum($row[$col['ctr']] ?? '');                                   // "0.34%" → 0.34
            $rev  = $col['revenue'] !== false ? $this->parseNum($row[$col['revenue']] ?? '') : 0;
            $impr = $col['impr']    !== false ? (int) $this->parseNum($row[$col['impr']] ?? '') : 0;
            $req  = $col['req']     !== false ? (int) $this->parseNum($row[$col['req']] ?? '') : 0;

            try {
                $stmt->execute([$site, $dateStr, $hour, $ctr, $rev, $impr, $req, $currencyCode]);
                $stmt->rowCount() === 1 ? $inserted++ : $updated++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }
        $pdo->commit();
        fclose($handle);

        if ($inserted + $updated === 0) {
            return ['type' => 'error', 'msg' => "No hourly rows imported ($skipped skipped). Check the CSV has Site, Hour and CTR columns."];
        }
        return ['type' => 'success', 'msg' => "Hourly GAM upload complete: $inserted new, $updated updated, $skipped skipped."];
    }

    /** Parse a numeric cell, stripping %, currency symbols and thousands separators. */
    private function parseNum($v): float
    {
        $v = preg_replace('/[^0-9.\-]/', '', (string) $v);
        return ($v === '' || $v === '-' || $v === '.') ? 0.0 : (float) $v;
    }
}
