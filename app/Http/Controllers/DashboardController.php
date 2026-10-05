<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;
use PDO;

/**
 * Port of index.php — daily P&L dashboard.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Daily Dashboard';
        $activePage = 'dashboard';

        // ── Date selection ──
        $date = $request->query('date', date('Y-m-d', strtotime('yesterday')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $usdRate = getCurrencyRate('USD');
        $gstRate = (float) getSetting('gst_rate', '18');

        // ── User scoping ── (admins may filter by ?user=; everyone else follows their data_scope) ──
        $viewUserId = canSeeAllUsers() ? (int) $request->query('user', 0) : 0;
        $linkScope  = $viewUserId > 0 ? "l.user_id = $viewUserId" : linkScopeWhere('l', 'd');

        // ── ADX network filter (matches a link's ADX via its domain, legacy col as fallback) ──
        $adxFilter = (int) $request->query('adx', 0);
        $adxWhere  = $adxFilter > 0 ? " AND COALESCE(d.adx_id, l.adx_id) = $adxFilter" : '';

        $links = $metaRaw = $gamRaw = $datesRaw = $adxOptions = $domAdx = [];
        $dbOk = true;
        $error = null;

        try {
            $pdo   = getDB();
            $adxOptions = adxOptionsForUser();
            $links = $pdo->query("SELECT l.*, u.name AS owner_name
                                  FROM links l
                                  LEFT JOIN domains d ON d.id=l.domain_id
                                  LEFT JOIN users u ON u.id=l.user_id
                                  WHERE $linkScope $adxWhere ORDER BY l.link_name")->fetchAll();

            // Map of domain name → ADX name, used to resolve a link's ADX from its
            // GAM host (a link may map to several GAM sites / domains).
            foreach ($pdo->query("SELECT LOWER(d.name) AS name, a.name AS adx_name
                                  FROM domains d LEFT JOIN adx a ON a.id=d.adx_id")->fetchAll() as $dr) {
                if ($dr['name'] !== '') $domAdx[$dr['name']] = $dr['adx_name'];
            }

            $metaStmt = $pdo->prepare("
                SELECT campaign_name,
                       SUM(amount_spent) AS amount_spent,
                       SUM(impressions)  AS impressions,
                       SUM(results)      AS results
                FROM meta_data
                WHERE date = ?
                GROUP BY campaign_name
            ");
            $metaStmt->execute([$date]);
            foreach ($metaStmt->fetchAll() as $row) {
                $metaRaw[$row['campaign_name']] = [
                    'spend'       => (float) $row['amount_spent'],
                    'impressions' => (int) $row['impressions'],
                    'results'     => (int) $row['results'],
                ];
            }

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

            $datesRaw = $pdo->query("
                SELECT DISTINCT date FROM meta_data
                UNION
                SELECT DISTINCT date FROM gam_data
                ORDER BY date DESC
                LIMIT 120
            ")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            $dbOk  = false;
            $error = $e->getMessage();
            $links = $metaRaw = $gamRaw = $datesRaw = [];
        }

        // ── Build rows ──
        $rows      = [];
        $totGAMusd = $totGAM = $totMeta = $totGST = $totCost = $totNetPL = 0;
        $totImpr   = $totClicks = 0;
        $cntProfit = $cntLoss = 0;

        foreach ($links as $link) {
            $campaigns = array_filter(array_map('trim', explode(',', $link['meta_campaign'])));
            $gamSites  = array_values(array_filter(array_map('trim', explode(',', $link['gam_url']))));

            $metaSpend         = null;
            $campaignBreakdown = [];
            foreach ($campaigns as $campaign) {
                $totals = $metaRaw[$campaign] ?? null;
                if ($totals !== null) {
                    $metaSpend = ($metaSpend ?? 0) + $totals['spend'];
                    $campaignBreakdown[] = [
                        'name'        => $campaign,
                        'spend'       => $totals['spend'],
                        'impressions' => $totals['impressions'],
                        'results'     => $totals['results'],
                    ];
                } else {
                    $campaignBreakdown[] = ['name' => $campaign, 'spend' => null, 'impressions' => 0, 'results' => 0];
                }
            }

            $gamDefaultCur = null;
            $gamImpr = $gamClicks = 0;
            foreach ($gamSites as $site) {
                if (isset($gamRaw[$site])) {
                    $gamDefaultCur = ($gamDefaultCur ?? 0) + $gamRaw[$site]['rev'];
                    $gamImpr      += $gamRaw[$site]['impressions'];
                    $gamClicks    += $gamRaw[$site]['clicks'];
                }
            }
            // GAM CTR (%). One site → the report's own Ad Exchange CTR. Several sites
            // (or no CTR in the report) → clicks ÷ impressions, since CTRs can't be averaged.
            $siteHits = array_values(array_filter($gamSites, fn($s) => isset($gamRaw[$s])));
            if (count($siteHits) === 1 && $gamRaw[$siteHits[0]]['ctr'] > 0) {
                $ctr = round($gamRaw[$siteHits[0]]['ctr'], 2);
            } else {
                $ctr = $gamImpr > 0 ? round($gamClicks / $gamImpr * 100, 2) : null;
            }
            $gamUSD  = $gamDefaultCur !== null ? ($usdRate > 0 ? round($gamDefaultCur / $usdRate, 6) : 0) : null;
            $hasData = ($metaSpend !== null || $gamDefaultCur !== null);

            if (!$link['active'] && !$hasData) continue;

            // ADX network — resolved from the LAST GAM site's domain (longest match).
            $adxName = null;
            if (!empty($gamSites)) {
                $host = normalizeUrl($gamSites[array_key_last($gamSites)]);
                $bestLen = -1;
                foreach ($domAdx as $dName => $aName) {
                    if ($host === $dName || str_ends_with($host, '.' . $dName)) {
                        if (strlen($dName) > $bestLen) { $adxName = $aName; $bestLen = strlen($dName); }
                    }
                }
            }

            $gamINR  = $gamDefaultCur !== null ? round($gamDefaultCur, 2) : 0.0;
            $spend   = $metaSpend !== null ? $metaSpend : 0.0;
            $gst     = round($spend * $gstRate / 100, 2);
            $cost    = round($spend + $gst, 2);
            $netPL   = round($gamINR - $cost, 2);
            $margin  = $cost > 0 ? round($netPL / $cost * 100, 1) : null;

            if ($hasData) {
                $totGAMusd += $gamUSD ?? 0;
                $totGAM    += $gamINR;
                $totMeta   += $spend;
                $totGST    += $gst;
                $totCost   += $cost;
                $totNetPL  += $netPL;
                $totImpr   += $gamImpr;
                $totClicks += $gamClicks;
                if ($netPL >= 0) $cntProfit++; else $cntLoss++;
            }

            $rows[] = compact('link', 'gamSites', 'adxName', 'gamINR', 'gamUSD', 'spend', 'gst', 'cost', 'netPL', 'margin', 'hasData', 'metaSpend', 'campaignBreakdown', 'gamImpr', 'gamClicks', 'ctr');
        }

        $totMargin = $totCost > 0 ? round($totNetPL / $totCost * 100, 1) : 0;
        $totCtr    = $totImpr > 0 ? round($totClicks / $totImpr * 100, 2) : null;

        // ── Distinct link notes for the filter ──
        $noteOptions  = [];
        $hasEmptyNote = false;
        foreach ($rows as $r) {
            $nt = trim((string) ($r['link']['notes'] ?? ''));
            if ($nt === '') $hasEmptyNote = true; else $noteOptions[$nt] = true;
        }
        $noteOptions = array_keys($noteOptions);
        natcasesort($noteOptions);
        $noteOptions = array_values($noteOptions);

        // ── Date display ──
        $dateObj     = new DateTime($date);
        $displayDate = $dateObj->format('d M Y') . ' (' . $dateObj->format('l') . ')';
        $monthYear   = $dateObj->format('F Y');

        return view('dashboard', compact(
            'pageTitle', 'activePage', 'date', 'usdRate', 'gstRate', 'viewUserId',
            'adxOptions', 'adxFilter',
            'rows', 'totGAMusd', 'totGAM', 'totMeta', 'totGST', 'totCost', 'totNetPL',
            'totMargin', 'totCtr', 'totImpr', 'totClicks', 'cntProfit', 'cntLoss', 'noteOptions', 'hasEmptyNote',
            'datesRaw', 'displayDate', 'monthYear', 'dbOk', 'error'
        ) + ['flash' => session('flash')]);
    }

    /**
     * AJAX: last 30 days of P&L for one link, ending on the dashboard's selected date.
     * Same maths as the dashboard rows (meta spend summed over campaigns, GAM revenue
     * summed over sites in the default currency, GST on spend).
     */
    public function history(Request $request)
    {
        $days   = 30;
        $linkId = (int) $request->query('link', 0);
        $end    = (string) $request->query('date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-d');
        $start  = (new DateTime($end))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT l.id, l.link_name, l.meta_url, l.meta_campaign, l.gam_url
                                   FROM links l LEFT JOIN domains d ON d.id = l.domain_id
                                   WHERE l.id = ? AND " . linkScopeWhere('l', 'd'));
            $stmt->execute([$linkId]);
            $link = $stmt->fetch();
            if (!$link) {
                return response()->json(['ok' => false, 'msg' => 'Link not found.'], 404);
            }

            $campaigns = array_values(array_unique(array_filter(array_map('trim', explode(',', $link['meta_campaign'])))));
            $sites     = array_values(array_unique(array_filter(array_map('trim', explode(',', $link['gam_url'])))));

            $metaByDate = [];
            if ($campaigns) {
                $in = implode(',', array_fill(0, count($campaigns), '?'));
                $q  = $pdo->prepare("SELECT `date`, SUM(amount_spent) AS sp FROM meta_data
                                     WHERE campaign_name IN ($in) AND `date` BETWEEN ? AND ?
                                     GROUP BY `date`");
                $q->execute([...$campaigns, $start, $end]);
                foreach ($q->fetchAll() as $r) $metaByDate[$r['date']] = (float) $r['sp'];
            }

            $gamByDate = [];
            if ($sites) {
                $in = implode(',', array_fill(0, count($sites), '?'));
                $q  = $pdo->prepare("SELECT t.`date`, SUM(t.v) AS v FROM (
                                         SELECT g.site, g.`date`, MAX(g.revenue_usd * COALESCE(c.rate_to_default, 1)) AS v
                                         FROM gam_data g
                                         LEFT JOIN currencies c ON c.code = COALESCE(g.gam_currency, 'USD')
                                         WHERE g.site IN ($in) AND g.`date` BETWEEN ? AND ?
                                         GROUP BY g.site, g.`date`
                                     ) t GROUP BY t.`date`");
                $q->execute([...$sites, $start, $end]);
                foreach ($q->fetchAll() as $r) $gamByDate[$r['date']] = (float) $r['v'];
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()]);
        }

        $usdRate = getCurrencyRate('USD');
        $gstRate = (float) getSetting('gst_rate', '18');

        $rows = [];
        $tot  = ['gam' => 0.0, 'spend' => 0.0, 'gst' => 0.0, 'cost' => 0.0, 'pl' => 0.0];
        $cntProfit = $cntLoss = 0;

        // Newest first.
        for ($d = new DateTime($end); $d->format('Y-m-d') >= $start; $d->modify('-1 day')) {
            $dt      = $d->format('Y-m-d');
            $hasData = isset($metaByDate[$dt]) || isset($gamByDate[$dt]);
            $gam     = round($gamByDate[$dt] ?? 0, 2);
            $spend   = $metaByDate[$dt] ?? 0.0;
            $gst     = round($spend * $gstRate / 100, 2);
            $cost    = round($spend + $gst, 2);
            $pl      = round($gam - $cost, 2);
            $margin  = $cost > 0 ? round($pl / $cost * 100, 1) : null;

            if ($hasData) {
                foreach (['gam' => $gam, 'spend' => $spend, 'gst' => $gst, 'cost' => $cost, 'pl' => $pl] as $k => $v) $tot[$k] += $v;
                if ($pl >= 0) $cntProfit++; else $cntLoss++;
            }

            $rows[] = [
                'date'        => $dt,
                'label'       => $d->format('d M Y (D)'),
                'has_data'    => $hasData,
                'gam_usd_fmt' => fmtUsdFromInr($gam, $usdRate),
                'gam_fmt'     => fmtINR($gam),
                'spend_fmt'   => fmtINR($spend),
                'gst_fmt'     => fmtINR($gst),
                'cost_fmt'    => fmtINR($cost),
                'pl'          => $pl,
                'pl_fmt'      => fmtINR($pl),
                'margin'      => $margin,
                'margin_fmt'  => $margin !== null ? fmtPct($margin) : '—',
            ];
        }

        $totMargin = $tot['cost'] > 0 ? round($tot['pl'] / $tot['cost'] * 100, 1) : null;

        return response()->json([
            'ok'    => true,
            'link'  => ['name' => $link['link_name'], 'meta_url' => $link['meta_url']],
            'range' => (new DateTime($start))->format('d M Y') . ' – ' . (new DateTime($end))->format('d M Y'),
            'rows'  => $rows,
            'total' => [
                'gam_usd_fmt' => fmtUsdFromInr($tot['gam'], $usdRate),
                'gam_fmt'     => fmtINR($tot['gam']),
                'spend_fmt'   => fmtINR($tot['spend']),
                'gst_fmt'     => fmtINR($tot['gst']),
                'cost_fmt'    => fmtINR($tot['cost']),
                'pl'          => round($tot['pl'], 2),
                'pl_fmt'      => fmtINR($tot['pl']),
                'margin'      => $totMargin,
                'margin_fmt'  => $totMargin !== null ? fmtPct($totMargin) : '—',
                'profit_days' => $cntProfit,
                'loss_days'   => $cntLoss,
            ],
        ]);
    }
}
