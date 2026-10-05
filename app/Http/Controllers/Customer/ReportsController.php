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

        $links = $pdo->prepare("SELECT * FROM links WHERE customer_id = ? ORDER BY link_name");
        $links->execute([currentCustomerId()]);
        $links = $links->fetchAll();

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

        $rows = [];
        $totGAMusd = $totGAM = 0;
        $totImpr = $totClicks = 0;

        foreach ($links as $link) {
            $gamSites  = array_values(array_filter(array_map('trim', explode(',', $link['gam_url']))));

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

        $totCtr    = $totImpr > 0 ? round($totClicks / $totImpr * 100, 2) : null;

        $dateObj     = new DateTime($date);
        $displayDate = $dateObj->format('d M Y') . ' (' . $dateObj->format('l') . ')';

        return view('customer.reports.site_wise', compact(
            'pageTitle', 'activePage', 'date', 'datesRaw', 'displayDate', 'usdRate',
            'rows', 'totGAMusd', 'totGAM', 'totCtr', 'totImpr', 'totClicks',
            'sharePct', 'restPct'
        ));
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

        // Every GAM site behind this customer's subdomains.
        $links = $pdo->prepare("SELECT gam_url FROM links WHERE customer_id = ?");
        $links->execute([currentCustomerId()]);
        $sites = [];
        foreach ($links->fetchAll(PDO::FETCH_COLUMN) as $gamUrl) {
            foreach (array_filter(array_map('trim', explode(',', (string) $gamUrl))) as $s) $sites[$s] = true;
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

        $dateObj     = new DateTime($date);
        $displayDate = $dateObj->format('d M Y') . ' (' . $dateObj->format('l') . ')';

        return view($view, compact(
            'pageTitle', 'activePage', 'date', 'datesRaw', 'displayDate', 'sites', 'site', 'rows', 'tot'
        ));
    }
}
