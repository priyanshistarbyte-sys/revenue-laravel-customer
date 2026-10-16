<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use DateTime;
use Illuminate\Http\Request;
use PDO;

/**
 * Customer panel → Report menu.
 */
class ReportsController extends Controller
{
    /**
     * Site Wise: the admin Dashboard's Link Performance table for one day, limited
     * to the subdomains assigned to this customer (links.customer_id) and to the
     * GAM URL / GAM Rev ($) / GAM Rev (₹) / CTR columns (same maths as
     * DashboardController::index), plus the customer's share % and the remaining %.
     */
    public function siteWise(Request $request)
    {
        $pageTitle  = 'Site Wise';
        $activePage = 'site-wise';

        $date = (string) $request->query('date', date('Y-m-d', strtotime('yesterday')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d', strtotime('yesterday'));
        }

        $usdRate = getCurrencyRate('USD');
        $pdo     = getDB();

        $type  = $this->reportType($request);
        $links = $this->customerLinks($pdo, $type);

        // Share % from Admin → Customers (e.g. 10) and what remains (e.g. 90).
        $sh = $pdo->prepare("SELECT share_percentage FROM customers WHERE id = ?");
        $sh->execute([currentCustomerId()]);
        $sharePct = round((float) $sh->fetchColumn(), 2);
        $restPct  = round(100 - $sharePct, 2);

        $gamRaw = [];
        if ($links) {
            $gamStmt = $pdo->prepare("
                SELECT g.site,
                       MAX(g.revenue_usd * COALESCE(c.rate_to_default, 1)) AS rev_default,
                       MAX(g.impressions) AS impressions,
                       MAX(g.clicks)      AS clicks,
                       MAX(g.ctr)         AS ctr
                FROM gam_data g
                LEFT JOIN currencies c ON c.code = COALESCE(g.gam_currency, 'USD')
                WHERE g.date = ?
                GROUP BY g.site
            ");
            $gamStmt->execute([$date]);
            foreach ($gamStmt->fetchAll() as $row) {
                $gamRaw[$row['site']] = [
                    'rev'         => (float) $row['rev_default'],
                    'impressions' => (int) $row['impressions'],
                    'clicks'      => (int) $row['clicks'],
                    'ctr'         => (float) $row['ctr'],
                ];
            }
        }

        // Dates that have data — for the date dropdown.
        $datesRaw = $pdo->query("SELECT DISTINCT date FROM gam_data ORDER BY date DESC LIMIT 120")->fetchAll(PDO::FETCH_COLUMN);

        ['rows' => $rows, 'totGAMusd' => $totGAMusd, 'totGAM' => $totGAM, 'totImpr' => $totImpr, 'totClicks' => $totClicks]
            = $this->siteRows($links, $gamRaw, $usdRate);
        $totCtr = $totImpr > 0 ? round($totClicks / $totImpr * 100, 2) : null;

        if ($request->query('export') === 'csv') {
            $fmt  = fn ($v) => number_format((float) $v, 2, '.', '');
            $pct  = fn ($p) => rtrim(rtrim(number_format($p, 2), '0'), '.') . '%';
            $data = [];
            foreach ($rows as $r) {
                $share  = $r['gamINR'] * $sharePct / 100;
                $data[] = [
                    ...$this->siteCells($r['link'], $type),
                    $fmt($r['gamUSD'] ?? 0),
                    $fmt($r['gamINR']),
                    $r['ctr'] !== null ? $fmt($r['ctr']) : '',
                    $fmt($share),
                    $fmt($r['gamINR'] - $share),
                ];
            }
            $totShare = $totGAM * $sharePct / 100;
            $data[] = [...$this->totalCells($type), $fmt($totGAMusd), $fmt($totGAM), $totCtr !== null ? $fmt($totCtr) : '', $fmt($totShare), $fmt($totGAM - $totShare)];

            return $this->csv('site-wise-' . ($type === 'app' ? 'apps-' : '') . $date . '.csv', [
                ...$this->siteHeads($type), 'GAM Rev ($)', 'GAM Rev (Rs)', 'CTR (%)',
                'Share ' . $pct($sharePct) . ' (Rs)', 'Remaining ' . $pct($restPct) . ' (Rs)',
            ], $data);
        }

        $dateObj     = new DateTime($date);
        $displayDate = $dateObj->format('d M Y') . ' (' . $dateObj->format('l') . ')';

        return view('customer.reports.site_wise', compact(
            'pageTitle', 'activePage', 'date', 'datesRaw', 'displayDate', 'usdRate',
            'rows', 'totGAMusd', 'totGAM', 'totCtr', 'totImpr', 'totClicks',
            'sharePct', 'restPct', 'type'
        ));
    }

    /**
     * Date Range: the Site Wise report for every day between ?from and ?to,
     * one row per day × site, with a Date column. Same maths as siteWise().
     */
    public function dateRange(Request $request)
    {
        $pageTitle  = 'Date Range';
        $activePage = 'date-range';

        $maxDays = 62;
        $valid = fn ($d, $fallback) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) ? (string) $d : $fallback;
        $from  = $valid($request->query('from'), date('Y-m-d', strtotime('-6 days')));
        $to    = $valid($request->query('to'), date('Y-m-d'));
        if ($from > $to) [$from, $to] = [$to, $from];

        // Cap the range so the table stays usable.
        $capped = false;
        $maxTo  = (new DateTime($from))->modify('+' . ($maxDays - 1) . ' days')->format('Y-m-d');
        if ($to > $maxTo) { $to = $maxTo; $capped = true; }

        $dates = [];
        for ($d = new DateTime($from); $d->format('Y-m-d') <= $to; $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
        }

        $usdRate = getCurrencyRate('USD');
        $pdo     = getDB();

        $type  = $this->reportType($request);
        $links = $this->customerLinks($pdo, $type);

        $sh = $pdo->prepare("SELECT share_percentage FROM customers WHERE id = ?");
        $sh->execute([currentCustomerId()]);
        $sharePct = round((float) $sh->fetchColumn(), 2);
        $restPct  = round(100 - $sharePct, 2);

        // gam_data for the whole range, keyed by date then site.
        $gamByDate = [];
        if ($links) {
            $gamStmt = $pdo->prepare("
                SELECT g.date, g.site,
                       MAX(g.revenue_usd * COALESCE(c.rate_to_default, 1)) AS rev_default,
                       MAX(g.impressions) AS impressions,
                       MAX(g.clicks)      AS clicks,
                       MAX(g.ctr)         AS ctr
                FROM gam_data g
                LEFT JOIN currencies c ON c.code = COALESCE(g.gam_currency, 'USD')
                WHERE g.date BETWEEN ? AND ?
                GROUP BY g.date, g.site
            ");
            $gamStmt->execute([$from, $to]);
            foreach ($gamStmt->fetchAll() as $row) {
                $gamByDate[$row['date']][$row['site']] = [
                    'rev'         => (float) $row['rev_default'],
                    'impressions' => (int) $row['impressions'],
                    'clicks'      => (int) $row['clicks'],
                    'ctr'         => (float) $row['ctr'],
                ];
            }
        }

        $rows = [];
        $totGAMusd = $totGAM = 0;
        $totImpr = $totClicks = 0;
        $siteIds = [];
        // Newest day first.
        foreach (array_reverse($dates) as $day) {
            $res = $this->siteRows($links, $gamByDate[$day] ?? [], $usdRate);
            foreach ($res['rows'] as $r) {
                $r['date'] = $day;
                $rows[]    = $r;
                $siteIds[$r['link']['id']] = true;
            }
            $totGAMusd += $res['totGAMusd'];
            $totGAM    += $res['totGAM'];
            $totImpr   += $res['totImpr'];
            $totClicks += $res['totClicks'];
        }
        $totCtr    = $totImpr > 0 ? round($totClicks / $totImpr * 100, 2) : null;
        $siteCount = count($siteIds);

        if ($request->query('export') === 'csv') {
            $fmt  = fn ($v) => number_format((float) $v, 2, '.', '');
            $pct  = fn ($p) => rtrim(rtrim(number_format($p, 2), '0'), '.') . '%';
            $data = [];
            foreach ($rows as $r) {
                $share  = $r['gamINR'] * $sharePct / 100;
                $data[] = [
                    $r['date'],
                    ...$this->siteCells($r['link'], $type),
                    $fmt($r['gamUSD'] ?? 0),
                    $fmt($r['gamINR']),
                    $r['ctr'] !== null ? $fmt($r['ctr']) : '',
                    $fmt($share),
                    $fmt($r['gamINR'] - $share),
                ];
            }
            $totShare = $totGAM * $sharePct / 100;
            $data[] = ['TOTALS', ...array_fill(0, count($this->siteHeads($type)), ''), $fmt($totGAMusd), $fmt($totGAM), $totCtr !== null ? $fmt($totCtr) : '', $fmt($totShare), $fmt($totGAM - $totShare)];

            return $this->csv('date-range-' . ($type === 'app' ? 'apps-' : '') . $from . '-to-' . $to . '.csv', [
                'Date', ...$this->siteHeads($type), 'GAM Rev ($)', 'GAM Rev (Rs)', 'CTR (%)',
                'Share ' . $pct($sharePct) . ' (Rs)', 'Remaining ' . $pct($restPct) . ' (Rs)',
            ], $data);
        }

        return view('customer.reports.date_range', compact(
            'pageTitle', 'activePage', 'from', 'to', 'capped', 'maxDays', 'usdRate',
            'rows', 'totGAMusd', 'totGAM', 'totCtr', 'totImpr', 'totClicks', 'siteCount',
            'sharePct', 'restPct', 'type'
        ));
    }

    /**
     * One day's Site Wise rows for the given links: GAM revenue ($ and default
     * currency), CTR, impressions and clicks from that day's gam_data (keyed by
     * site), plus the day's totals. Inactive links with no data are left out.
     */
    private function siteRows(array $links, array $gamRaw, float $usdRate): array
    {
        $rows = [];
        $totGAMusd = $totGAM = 0;
        $totImpr = $totClicks = 0;

        foreach ($links as $link) {
            $gamSites  = self::linkSites($link);

            $gamDefaultCur = null;
            $gamImpr = $gamClicks = 0;
            foreach ($gamSites as $site) {
                if (isset($gamRaw[$site])) {
                    $gamDefaultCur = ($gamDefaultCur ?? 0) + $gamRaw[$site]['rev'];
                    $gamImpr      += $gamRaw[$site]['impressions'];
                    $gamClicks    += $gamRaw[$site]['clicks'];
                }
            }
            $siteHits = array_values(array_filter($gamSites, fn($s) => isset($gamRaw[$s])));
            if (count($siteHits) === 1 && $gamRaw[$siteHits[0]]['ctr'] > 0) {
                $ctr = round($gamRaw[$siteHits[0]]['ctr'], 2);
            } else {
                $ctr = $gamImpr > 0 ? round($gamClicks / $gamImpr * 100, 2) : null;
            }

            $hasData = $gamDefaultCur !== null;
            if (!$link['active'] && !$hasData) continue;

            $gamUSD = $gamDefaultCur !== null ? ($usdRate > 0 ? round($gamDefaultCur / $usdRate, 6) : 0) : null;
            $gamINR = $gamDefaultCur !== null ? round($gamDefaultCur, 2) : 0.0;

            if ($hasData) {
                $totGAMusd += $gamUSD ?? 0;
                $totGAM    += $gamINR;
                $totImpr   += $gamImpr;
                $totClicks += $gamClicks;
            }

            $rows[] = compact('link', 'gamSites', 'gamUSD', 'gamINR', 'ctr', 'hasData', 'gamImpr', 'gamClicks');
        }

        return compact('rows', 'totGAMusd', 'totGAM', 'totImpr', 'totClicks');
    }

    /**
     * Hourly Wise: GAM's Site × Hour Ad Exchange report (gam_hourly) for one day.
     */
    public function hourlyWise(Request $request)
    {
        return $this->breakdown($request, 'gam_hourly', 'hour', 'customer.reports.hourly_wise', 'Hourly Wise', 'hourly-wise');
    }

    /**
     * Country Wise: GAM's Site × Country Ad Exchange report (gam_country) for one day.
     */
    public function countryWise(Request $request)
    {
        return $this->breakdown($request, 'gam_country', 'country', 'customer.reports.country_wise', 'Country Wise', 'country-wise');
    }

    /**
     * Shared Site × <dimension> report for one day, limited to the GAM sites of
     * this customer's subdomains. Revenue is converted to USD like Site Wise;
     * eCPM = revenue / impressions × 1000. Each row's dimension value is 'dim'.
     */
    private function breakdown(Request $request, string $table, string $dimCol, string $view, string $pageTitle, string $activePage)
    {
        $pdo = getDB();

        $datesRaw = $pdo->query("SELECT DISTINCT date FROM $table ORDER BY date DESC LIMIT 120")->fetchAll(PDO::FETCH_COLUMN);

        $date = (string) $request->query('date', $datesRaw[0] ?? date('Y-m-d', strtotime('yesterday')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $datesRaw[0] ?? date('Y-m-d', strtotime('yesterday'));
        }

        // Every GAM site (Web) or App ID (App) behind this customer's subdomains.
        // $siteNames: App ID → App Name, for the App view.
        $type      = $this->reportType($request);
        $sites     = $siteNames = [];
        foreach ($this->customerLinks($pdo, $type) as $lk) {
            foreach (self::linkSites($lk) as $s) {
                $sites[$s] = true;
                if ($type === 'app') $siteNames[$s] = (string) ($lk['app_name'] ?? '');
            }
        }
        $sites = array_keys($sites);
        sort($sites);

        $site = (string) $request->query('site', '');
        if ($site !== '' && !in_array($site, $sites, true)) $site = '';
        $filter = $site !== '' ? [$site] : $sites;

        $usdRate = getCurrencyRate('USD');
        $rows    = [];
        $tot     = ['rev' => 0.0, 'impr' => 0, 'req' => 0, 'clicks' => 0.0];

        if ($filter) {
            $in    = implode(',', array_fill(0, count($filter), '?'));
            $order = $dimCol === 'hour' ? 'h.site, h.hour' : 'h.site, h.revenue DESC';
            $stmt  = $pdo->prepare("
                SELECT h.site, h.$dimCol AS dim, h.ctr, h.impressions, h.total_requests,
                       h.revenue * COALESCE(c.rate_to_default, 1) AS rev_default
                FROM $table h
                LEFT JOIN currencies c ON c.code = COALESCE(h.gam_currency, 'USD')
                WHERE h.date = ? AND h.site IN ($in)
                ORDER BY $order
            ");
            $stmt->execute(array_merge([$date], $filter));
            foreach ($stmt->fetchAll() as $r) {
                $rev  = $usdRate > 0 ? (float) $r['rev_default'] / $usdRate : 0.0;
                $impr = (int) $r['impressions'];
                $rows[] = [
                    'site'  => $r['site'],
                    'dim'   => $dimCol === 'hour' ? (int) $r['dim'] : $r['dim'],
                    'ctr'   => (float) $r['ctr'],
                    'req'   => (int) $r['total_requests'],
                    'rev'   => $rev,
                    'ecpm'  => $impr > 0 ? $rev / $impr * 1000 : 0.0,
                    'impr'  => $impr,
                ];
                $tot['rev']    += $rev;
                $tot['impr']   += $impr;
                $tot['req']    += (int) $r['total_requests'];
                $tot['clicks'] += (float) $r['ctr'] / 100 * $impr;   // clicks aren't stored; CTR × impressions
            }
        }
        $tot['ctr']  = $tot['impr'] > 0 ? $tot['clicks'] / $tot['impr'] * 100 : null;
        $tot['ecpm'] = $tot['impr'] > 0 ? $tot['rev'] / $tot['impr'] * 1000 : null;

        if ($request->query('export') === 'csv') {
            $fmt  = fn ($v) => number_format((float) $v, 2, '.', '');
            $data = [];
            foreach ($rows as $r) {
                $data[] = [$r['site'], ...($type === 'app' ? [$siteNames[$r['site']] ?? ''] : []), $r['dim'], $fmt($r['ctr']), $r['req'], $fmt($r['rev']), $fmt($r['ecpm']), $r['impr']];
            }
            $data[] = [
                'TOTALS', ...($type === 'app' ? [''] : []), '', $tot['ctr'] !== null ? $fmt($tot['ctr']) : '', $tot['req'], $fmt($tot['rev']),
                $tot['ecpm'] !== null ? $fmt($tot['ecpm']) : '', $tot['impr'],
            ];

            return $this->csv($activePage . '-' . ($type === 'app' ? 'apps-' : '') . $date . ($site !== '' ? '-' . $site : '') . '.csv', [
                ...($type === 'app' ? ['App ID', 'App Name'] : ['Site']), ucfirst($dimCol), 'Ad Exchange CTR (%)', 'Ad Exchange total requests',
                'Ad Exchange revenue ($)', 'Ad Exchange average eCPM ($)', 'Ad Exchange impressions',
            ], $data);
        }

        $dateObj     = new DateTime($date);
        $displayDate = $dateObj->format('d M Y') . ' (' . $dateObj->format('l') . ')';

        return view($view, compact(
            'pageTitle', 'activePage', 'date', 'datesRaw', 'displayDate', 'sites', 'site', 'rows', 'tot',
            'type', 'siteNames'
        ));
    }

    /** ?type=app → the customer's App subdomains; anything else → Web. */
    private function reportType(Request $request): string
    {
        return $request->query('type') === 'app' ? 'app' : 'web';
    }

    /** This customer's subdomains of one type; App ones get their store name filled in. */
    private function customerLinks(\PDO $pdo, string $type): array
    {
        $stmt = $pdo->prepare("SELECT * FROM links WHERE customer_id = ? AND COALESCE(link_type, 'web') = ? ORDER BY link_name");
        $stmt->execute([currentCustomerId(), $type]);
        $links = $stmt->fetchAll();
        if ($type === 'app') \App\Services\AppNameLookup::fillMissing($pdo, $links);
        return $links;
    }

    /** GAM "site" keys a link earns under: its App ID for an App, else its GAM host(s). */
    private static function linkSites(array $link): array
    {
        if (($link['link_type'] ?? 'web') === 'app') {
            return array_values(array_filter([trim((string) ($link['app_id'] ?? ''))]));
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $link['gam_url']))));
    }

    /** CSV identity columns for Site Wise / Date Range: GAM URL (Web) or App ID + App Name (App). */
    private function siteHeads(string $type): array
    {
        return $type === 'app' ? ['App ID', 'App Name'] : ['GAM URL'];
    }

    private function siteCells(array $link, string $type): array
    {
        return $type === 'app'
            ? [(string) ($link['app_id'] ?? ''), (string) ($link['app_name'] ?? '')]
            : [implode(', ', self::linkSites($link))];
    }

    private function totalCells(string $type): array
    {
        return $type === 'app' ? ['TOTALS', ''] : ['TOTALS'];
    }

    /** Stream rows as a CSV download (UTF-8 BOM so Excel reads it correctly). */
    private function csv(string $filename, array $header, array $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
