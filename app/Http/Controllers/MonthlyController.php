<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;

/**
 * Port of monthly.php — monthly P&L summary.
 */
class MonthlyController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Monthly Summary';
        $activePage = 'monthly';

        $year  = (int) $request->query('year', date('Y'));
        $month = (int) $request->query('month', date('n'));
        if ($month < 1 || $month > 12) $month = (int) date('n');
        if ($year < 2020 || $year > 2035) $year = (int) date('Y');

        $monthStr    = sprintf('%04d-%02d', $year, $month);
        $monthName   = (new DateTime("$year-$month-01"))->format('F Y');
        $daysInMonth = (int) (new DateTime("$year-$month-01"))->format('t');

        $usdRate = getCurrencyRate('USD');
        $gstRate = (float) getSetting('gst_rate', '18');

        $prevMonth = new DateTime("$year-$month-01");
        $prevMonth->modify('-1 month');

        $viewUserId = canSeeAllUsers() ? (int) $request->query('user', 0) : 0;
        $linkScope  = $viewUserId > 0 ? "lk.user_id = $viewUserId" : linkScopeWhere('lk', 'd');

        // ── ADX network filter (matches a link's ADX via its domain, legacy col as fallback) ──
        $adxFilter = (int) $request->query('adx', 0);
        $adxWhere  = $adxFilter > 0 ? " AND COALESCE(d.adx_id, lk.adx_id) = $adxFilter" : '';

        $dailyByDate = [];
        $linkMonthly = [];
        $adxOptions  = [];
        $pmRow = ['gam_usd' => 0, 'gam_inr' => 0, 'meta_spend' => 0];
        $dbOk = true;
        $error = null;

        try {
            $pdo = getDB();
            $adxOptions = adxOptionsForUser();

            $start = "$monthStr-01";
            $end   = "$monthStr-$daysInMonth";

            $pmStart = $prevMonth->format('Y-m-01');
            $pmEnd   = $prevMonth->format('Y-m-t');

            $metaAgg = $pdo->prepare("
                SELECT campaign_name, `date`, SUM(amount_spent) AS sp
                FROM meta_data
                WHERE `date` BETWEEN ? AND ?
                GROUP BY campaign_name, `date`
            ");
            $metaAgg->execute([$pmStart, $end]);
            $metaByCampDate = [];
            foreach ($metaAgg->fetchAll() as $r) {
                $metaByCampDate[$r['campaign_name']][$r['date']] = (float) $r['sp'];
            }

            $gamAgg = $pdo->prepare("
                SELECT g.site, g.`date`,
                       MAX(g.revenue_usd * COALESCE(c.rate_to_default, 1)) AS v
                FROM gam_data g
                LEFT JOIN currencies c ON c.code = COALESCE(g.gam_currency, 'USD')
                WHERE g.`date` BETWEEN ? AND ?
                GROUP BY g.site, g.`date`
            ");
            $gamAgg->execute([$pmStart, $end]);
            $gamBySiteDate = [];
            foreach ($gamAgg->fetchAll() as $r) {
                $gamBySiteDate[$r['site']][$r['date']] = (float) $r['v'];
            }

            $links = $pdo->query("
                SELECT lk.id, u.name AS owner_name, lk.active,
                       lk.link_name, lk.meta_campaign, lk.gam_url
                FROM links lk
                LEFT JOIN users u ON u.id = lk.user_id
                LEFT JOIN domains d ON d.id = lk.domain_id
                WHERE $linkScope $adxWhere
                ORDER BY lk.link_name, lk.id
            ")->fetchAll();

            $pmGamDefaultCurSum = $pmMetaSum = 0.0;

            foreach ($links as $link) {
                $campaigns = array_unique(array_filter(array_map('trim', explode(',', $link['meta_campaign']))));
                $sites     = array_unique(array_filter(array_map('trim', explode(',', $link['gam_url']))));

                $lkMeta = 0.0; $lkGam = 0.0;

                foreach ($campaigns as $c) {
                    foreach (($metaByCampDate[$c] ?? []) as $d => $sp) {
                        if ($d >= $start && $d <= $end) {
                            $lkMeta += $sp;
                            if (!isset($dailyByDate[$d])) $dailyByDate[$d] = ['date' => $d, 'gam_usd' => 0.0, 'gam_inr' => 0.0, 'meta_spend' => 0.0];
                            $dailyByDate[$d]['meta_spend'] += $sp;
                        } elseif ($d >= $pmStart && $d <= $pmEnd) {
                            $pmMetaSum += $sp;
                        }
                    }
                }
                foreach ($sites as $s) {
                    foreach (($gamBySiteDate[$s] ?? []) as $d => $v) {
                        if ($d >= $start && $d <= $end) {
                            $lkGam += $v;
                            if (!isset($dailyByDate[$d])) $dailyByDate[$d] = ['date' => $d, 'gam_usd' => 0.0, 'gam_inr' => 0.0, 'meta_spend' => 0.0];
                            $dailyByDate[$d]['gam_inr'] += $v;
                            $dailyByDate[$d]['gam_usd'] += $usdRate > 0 ? $v / $usdRate : 0;
                        } elseif ($d >= $pmStart && $d <= $pmEnd) {
                            $pmGamDefaultCurSum += $v;
                        }
                    }
                }

                if ($link['active'] || $lkGam > 0 || $lkMeta > 0) {
                    $linkMonthly[$link['id']] = [
                        'link_name'     => $link['link_name'],
                        'owner_name'    => $link['owner_name'] ?? '—',
                        'meta_campaign' => $link['meta_campaign'],
                        'gam_url'       => $link['gam_url'],
                        'active'        => (int) $link['active'],
                        'gam_usd'       => $lkGam,
                        'meta_spend'    => $lkMeta,
                    ];
                }
            }

            uasort($linkMonthly, fn ($a, $b) => $b['meta_spend'] <=> $a['meta_spend']);

            $pmRow = [
                'gam_usd'    => $usdRate > 0 ? $pmGamDefaultCurSum / $usdRate : 0,
                'gam_inr'    => $pmGamDefaultCurSum,
                'meta_spend' => $pmMetaSum,
            ];
        } catch (\Throwable $e) {
            $dbOk = false;
            $error = $e->getMessage();
            $dailyByDate = [];
            $pmRow = ['gam_usd' => 0, 'gam_inr' => 0, 'meta_spend' => 0];
        }

        // ── Prev month KPIs ──
        $pmGAMusd = (float) ($pmRow['gam_usd'] ?? 0);
        $pmGAM    = (float) ($pmRow['gam_inr'] ?? 0);
        $pmMeta   = (float) ($pmRow['meta_spend'] ?? 0);
        $pmGST  = round($pmMeta * $gstRate / 100, 2);
        $pmCost = round($pmMeta + $pmGST, 2);
        $pmPL   = round($pmGAM - $pmCost, 2);
        $pmMgn  = $pmCost > 0 ? round($pmPL / $pmCost * 100, 1) : 0;

        // ── Current month totals ──
        $curGAMusd = $curGAM = $curMeta = 0;
        foreach ($dailyByDate as $r) {
            $curGAMusd += (float) $r['gam_usd'];
            $curGAM    += (float) $r['gam_inr'];
            $curMeta   += (float) $r['meta_spend'];
        }
        $curGST  = round($curMeta * $gstRate / 100, 2);
        $curCost = round($curMeta + $curGST, 2);
        $curPL   = round($curGAM - $curCost, 2);
        $curMgn  = $curCost > 0 ? round($curPL / $curCost * 100, 1) : 0;

        $prevM = new DateTime("$year-$month-01"); $prevM->modify('-1 month');
        $nextM = new DateTime("$year-$month-01"); $nextM->modify('+1 month');

        return view('monthly', compact(
            'pageTitle', 'activePage', 'year', 'month', 'monthName', 'daysInMonth',
            'usdRate', 'gstRate', 'viewUserId', 'adxOptions', 'adxFilter', 'prevMonth', 'dailyByDate', 'linkMonthly',
            'pmGAMusd', 'pmGAM', 'pmMeta', 'pmGST', 'pmCost', 'pmPL', 'pmMgn',
            'curGAMusd', 'curGAM', 'curMeta', 'curGST', 'curCost', 'curPL', 'curMgn',
            'prevM', 'nextM', 'dbOk', 'error'
        ));
    }

    /**
     * Date-wise report — per-link P&L for TWO specific dates side by side.
     * Each metric (GAM $/₹, Meta Spend, GST, Cost, P/L, Margin) shows one column
     * per selected date.
     */
    public function dateWise(Request $request)
    {
        $pageTitle  = 'Date-wise Report';
        $activePage = 'date-wise';

        $valid  = fn ($d, $fallback) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) ? (string) $d : $fallback;
        $date1  = $valid($request->query('date1'), date('Y-m-d', strtotime('-1 day')));
        $date2  = $valid($request->query('date2'), date('Y-m-d'));

        $usdRate = getCurrencyRate('USD');
        $gstRate = (float) getSetting('gst_rate', '18');

        $viewUserId = canSeeAllUsers() ? (int) $request->query('user', 0) : 0;
        $linkScope  = $viewUserId > 0 ? "lk.user_id = $viewUserId" : linkScopeWhere('lk', 'd');
        $adxFilter  = (int) $request->query('adx', 0);
        $adxWhere   = $adxFilter > 0 ? " AND COALESCE(d.adx_id, lk.adx_id) = $adxFilter" : '';
        // "Active only" — off by default, so paused subdomains that still have data
        // for these dates keep showing up unless the user asks to hide them.
        $activeOnly = $request->query('active') === '1';
        $activeWhere = $activeOnly ? ' AND lk.active = 1' : '';

        $rows = []; $adxOptions = [];
        $dbOk = true; $error = null;

        // Per-date metric builder from raw meta spend + gam revenue (default currency).
        $mk = function (float $meta, float $gamInr) use ($usdRate, $gstRate) {
            $gamUsd = $usdRate > 0 ? $gamInr / $usdRate : 0;
            $gst    = round($meta * $gstRate / 100, 2);
            $cost   = round($meta + $gst, 2);
            $pl     = round($gamInr - $cost, 2);
            $mgn    = $cost > 0 ? round($pl / $cost * 100, 1) : 0;
            return compact('gamUsd', 'gamInr', 'meta', 'gst', 'cost', 'pl', 'mgn');
        };

        try {
            $pdo = getDB();
            $adxOptions = adxOptionsForUser();

            // Spend per campaign for the two dates.
            $metaAgg = $pdo->prepare("
                SELECT campaign_name, `date`, SUM(amount_spent) AS sp
                FROM meta_data WHERE `date` IN (?, ?)
                GROUP BY campaign_name, `date`
            ");
            $metaAgg->execute([$date1, $date2]);
            $metaByCampDate = [];
            foreach ($metaAgg->fetchAll() as $r) {
                $metaByCampDate[$r['campaign_name']][$r['date']] = (float) $r['sp'];
            }

            // Revenue per GAM site for the two dates (in default currency).
            $gamAgg = $pdo->prepare("
                SELECT g.site, g.`date`, MAX(g.revenue_usd * COALESCE(c.rate_to_default, 1)) AS v
                FROM gam_data g
                LEFT JOIN currencies c ON c.code = COALESCE(g.gam_currency, 'USD')
                WHERE g.`date` IN (?, ?)
                GROUP BY g.site, g.`date`
            ");
            $gamAgg->execute([$date1, $date2]);
            $gamBySiteDate = [];
            foreach ($gamAgg->fetchAll() as $r) {
                $gamBySiteDate[$r['site']][$r['date']] = (float) $r['v'];
            }

            $links = $pdo->query("
                SELECT lk.id, u.name AS owner_name, lk.active, lk.link_name,
                       lk.meta_url, lk.meta_campaign, lk.gam_url, lk.notes, lk.created_at,
                       ax.name AS adx_name
                FROM links lk
                LEFT JOIN users u ON u.id = lk.user_id
                LEFT JOIN domains d ON d.id = lk.domain_id
                LEFT JOIN adx ax ON ax.id = COALESCE(d.adx_id, lk.adx_id)
                WHERE $linkScope $adxWhere $activeWhere
                ORDER BY lk.link_name, lk.id
            ")->fetchAll();

            foreach ($links as $link) {
                $campaigns = array_unique(array_filter(array_map('trim', explode(',', $link['meta_campaign']))));
                $sites     = array_unique(array_filter(array_map('trim', explode(',', $link['gam_url']))));

                $m1 = $m2 = $g1 = $g2 = 0.0;
                foreach ($campaigns as $c) {
                    $m1 += $metaByCampDate[$c][$date1] ?? 0;
                    $m2 += $metaByCampDate[$c][$date2] ?? 0;
                }
                foreach ($sites as $s) {
                    $g1 += $gamBySiteDate[$s][$date1] ?? 0;
                    $g2 += $gamBySiteDate[$s][$date2] ?? 0;
                }

                $d1 = $mk($m1, $g1);
                $d2 = $mk($m2, $g2);
                $has = ($m1 || $g1 || $m2 || $g2);

                if ($link['active'] || $has) {
                    $rows[] = [
                        'link_name'  => $link['link_name'],
                        'meta_url'   => $link['meta_url'],
                        'gam_url'    => $link['gam_url'],
                        'adx_name'   => $link['adx_name'],
                        'entry'      => (string) ($link['created_at'] ?? ''),   // when the subdomain was added
                        'owner_name' => $link['owner_name'] ?? '—',
                        'notes'      => $link['notes'],
                        'active'     => (int) $link['active'],
                        'd1'         => $d1,
                        'd2'         => $d2,
                        'has'        => $has,
                    ];
                }
            }

            usort($rows, fn ($a, $b) => ($b['d1']['meta'] + $b['d2']['meta']) <=> ($a['d1']['meta'] + $a['d2']['meta']));
        } catch (\Throwable $e) {
            $dbOk = false; $error = $e->getMessage(); $rows = [];
        }

        // Column totals for each date.
        $t1 = ['gamUsd' => 0, 'gamInr' => 0, 'meta' => 0, 'gst' => 0, 'cost' => 0, 'pl' => 0];
        $t2 = ['gamUsd' => 0, 'gamInr' => 0, 'meta' => 0, 'gst' => 0, 'cost' => 0, 'pl' => 0];
        foreach ($rows as $r) {
            foreach (['gamUsd', 'gamInr', 'meta', 'gst', 'cost', 'pl'] as $k) {
                $t1[$k] += $r['d1'][$k];
                $t2[$k] += $r['d2'][$k];
            }
        }
        $t1['mgn'] = $t1['cost'] > 0 ? round($t1['pl'] / $t1['cost'] * 100, 1) : 0;
        $t2['mgn'] = $t2['cost'] > 0 ? round($t2['pl'] / $t2['cost'] * 100, 1) : 0;

        // CSV export — 'csv' = full GAM URL list, 'last' = only the last GAM host.
        $export = $request->query('export');
        if ($dbOk && ($export === 'csv' || $export === 'last')) {
            return $this->dateWiseCsv($rows, $date1, $date2, $export === 'last');
        }

        return view('date_wise', compact(
            'pageTitle', 'activePage', 'date1', 'date2', 'usdRate', 'gstRate',
            'viewUserId', 'adxOptions', 'adxFilter', 'activeOnly', 'rows', 't1', 't2', 'dbOk', 'error'
        ));
    }

    /**
     * Date-range report — per-link Meta Spend / Net P/L / Margin for every day in
     * a range. Each date is a column group with those three metrics as sub-columns.
     */
    public function dateRange(Request $request)
    {
        $pageTitle  = 'Date Range Report';
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

        // Daily actions judge the range's last day against the two days before it.
        // Today's numbers are still coming in (GAM revenue lags), so a range ending
        // today is judged on yesterday instead. Those days may fall outside the range.
        $yesterday     = date('Y-m-d', strtotime('-1 day'));
        $actionDay     = min($to, $yesterday);
        $actionShifted = $actionDay !== $to;
        $prevDay       = (new DateTime($actionDay))->modify('-1 day')->format('Y-m-d');
        $prevDay2      = (new DateTime($actionDay))->modify('-2 days')->format('Y-m-d');
        $fetchFrom     = min($from, $prevDay2);

        $usdRate = getCurrencyRate('USD');
        $gstRate = (float) getSetting('gst_rate', '18');

        $viewUserId  = canSeeAllUsers() ? (int) $request->query('user', 0) : 0;
        $linkScope   = $viewUserId > 0 ? "lk.user_id = $viewUserId" : linkScopeWhere('lk', 'd');
        $adxFilter   = (int) $request->query('adx', 0);
        $adxWhere    = $adxFilter > 0 ? " AND COALESCE(d.adx_id, lk.adx_id) = $adxFilter" : '';
        $activeOnly  = $request->query('active') === '1';
        $activeWhere = $activeOnly ? ' AND lk.active = 1' : '';

        $rows = []; $adxOptions = [];
        $dbOk = true; $error = null;

        // Metrics from raw meta spend + gam revenue (default currency).
        $mk = function (float $meta, float $gam) use ($gstRate) {
            $cost = round($meta + round($meta * $gstRate / 100, 2), 2);
            $pl   = round($gam - $cost, 2);
            $mgn  = $cost > 0 ? round($pl / $cost * 100, 1) : 0;
            return compact('meta', 'gam', 'cost', 'pl', 'mgn');
        };

        try {
            $pdo = getDB();
            $adxOptions = adxOptionsForUser();

            $metaAgg = $pdo->prepare("
                SELECT campaign_name, `date`, SUM(amount_spent) AS sp
                FROM meta_data WHERE `date` BETWEEN ? AND ?
                GROUP BY campaign_name, `date`
            ");
            $metaAgg->execute([$fetchFrom, $to]);
            $metaByCampDate = [];
            foreach ($metaAgg->fetchAll() as $r) {
                $metaByCampDate[$r['campaign_name']][$r['date']] = (float) $r['sp'];
            }

            $gamAgg = $pdo->prepare("
                SELECT g.site, g.`date`, MAX(g.revenue_usd * COALESCE(c.rate_to_default, 1)) AS v
                FROM gam_data g
                LEFT JOIN currencies c ON c.code = COALESCE(g.gam_currency, 'USD')
                WHERE g.`date` BETWEEN ? AND ?
                GROUP BY g.site, g.`date`
            ");
            $gamAgg->execute([$fetchFrom, $to]);
            $gamBySiteDate = [];
            foreach ($gamAgg->fetchAll() as $r) {
                $gamBySiteDate[$r['site']][$r['date']] = (float) $r['v'];
            }

            // Every day each campaign has had spend, up to the last day — used to
            // count a link's test days (its first days with spend).
            $spendDays = $pdo->prepare("
                SELECT campaign_name, `date` FROM meta_data
                WHERE `date` <= ? AND amount_spent > 0
                GROUP BY campaign_name, `date`
            ");
            $spendDays->execute([$actionDay]);
            $spendDatesByCamp = [];
            foreach ($spendDays->fetchAll() as $r) {
                $spendDatesByCamp[$r['campaign_name']][] = $r['date'];
            }

            // A test verdict needs a link's first spend days, which can be older than
            // the fetched window — load any such date on demand, once per date.
            $extraLoaded = [];
            $loadDate = function (string $dt) use ($pdo, $fetchFrom, $to, &$extraLoaded, &$metaByCampDate, &$gamBySiteDate) {
                if (($dt >= $fetchFrom && $dt <= $to) || isset($extraLoaded[$dt])) return;
                $extraLoaded[$dt] = true;
                $q = $pdo->prepare("SELECT campaign_name, SUM(amount_spent) AS sp FROM meta_data WHERE `date` = ? GROUP BY campaign_name");
                $q->execute([$dt]);
                foreach ($q->fetchAll() as $r) $metaByCampDate[$r['campaign_name']][$dt] = (float) $r['sp'];
                $q = $pdo->prepare("
                    SELECT g.site, MAX(g.revenue_usd * COALESCE(c.rate_to_default, 1)) AS v
                    FROM gam_data g
                    LEFT JOIN currencies c ON c.code = COALESCE(g.gam_currency, 'USD')
                    WHERE g.`date` = ?
                    GROUP BY g.site
                ");
                $q->execute([$dt]);
                foreach ($q->fetchAll() as $r) $gamBySiteDate[$r['site']][$dt] = (float) $r['v'];
            };

            $links = $pdo->query("
                SELECT lk.id, u.name AS owner_name, lk.active, lk.link_name,
                       lk.meta_url, lk.meta_campaign, lk.gam_url, ax.name AS adx_name
                FROM links lk
                LEFT JOIN users u ON u.id = lk.user_id
                LEFT JOIN domains d ON d.id = lk.domain_id
                LEFT JOIN adx ax ON ax.id = COALESCE(d.adx_id, lk.adx_id)
                WHERE $linkScope $adxWhere $activeWhere
                ORDER BY lk.link_name, lk.id
            ")->fetchAll();

            foreach ($links as $link) {
                $campaigns = array_unique(array_filter(array_map('trim', explode(',', $link['meta_campaign']))));
                $sites     = array_unique(array_filter(array_map('trim', explode(',', $link['gam_url']))));

                $byDate = []; $sumMeta = $sumGam = 0.0;
                foreach ($dates as $dt) {
                    $m = $g = 0.0;
                    foreach ($campaigns as $c) $m += $metaByCampDate[$c][$dt] ?? 0;
                    foreach ($sites as $s)     $g += $gamBySiteDate[$s][$dt] ?? 0;
                    $byDate[$dt] = $mk($m, $g);
                    $sumMeta += $m; $sumGam += $g;
                }
                $has = ($sumMeta || $sumGam);

                if ($link['active'] || $has) {
                    // Judged day vs the two before it, and the days it has had spend so far.
                    $day = function (string $dt) use ($campaigns, $sites, &$metaByCampDate, &$gamBySiteDate, $mk) {
                        $m = $g = 0.0;
                        foreach ($campaigns as $c) $m += $metaByCampDate[$c][$dt] ?? 0;
                        foreach ($sites as $s)     $g += $gamBySiteDate[$s][$dt] ?? 0;
                        return $mk($m, $g);
                    };
                    $daysSpent = [];
                    foreach ($campaigns as $c) {
                        foreach ($spendDatesByCamp[$c] ?? [] as $dt) $daysSpent[$dt] = true;
                    }

                    // Still in its test: the verdict needs every test day's numbers.
                    $testDays = [];
                    if (count($daysSpent) <= self::TEST_DAYS) {
                        foreach (array_keys($daysSpent) as $dt) {
                            $loadDate($dt);
                            $testDays[] = $day($dt);
                        }
                    }

                    $rows[] = [
                        'meta_url'   => $link['meta_url'],
                        'gam_url'    => $link['gam_url'],
                        'adx_name'   => $link['adx_name'],
                        'owner_name' => $link['owner_name'] ?? '—',
                        'active'     => (int) $link['active'],
                        'byDate'     => $byDate,
                        'total'      => $mk($sumMeta, $sumGam),
                        'has'        => $has,
                        'advice'     => $this->linkAdvice($day($actionDay), $day($prevDay), $day($prevDay2), count($daysSpent), $testDays),
                    ];
                }
            }

            $sortKey = showMeta() ? 'meta' : 'gam';
            usort($rows, fn ($a, $b) => $b['total'][$sortKey] <=> $a['total'][$sortKey]);
        } catch (\Throwable $e) {
            $dbOk = false; $error = $e->getMessage(); $rows = [];
        }

        // How many links fall under each action (for the filter chips).
        $actionCounts = array_fill_keys(array_keys(self::ACTIONS), 0);
        foreach ($rows as $r) $actionCounts[$r['advice']['key']]++;

        // Column totals per date + grand total (margin recomputed from summed P/L and cost).
        $blank = ['meta' => 0, 'gam' => 0, 'cost' => 0, 'pl' => 0];
        $totByDate = array_fill_keys($dates, $blank);
        $grand = $blank;
        foreach ($rows as $r) {
            foreach ($dates as $dt) {
                foreach ($blank as $k => $_) $totByDate[$dt][$k] += $r['byDate'][$dt][$k];
            }
            foreach ($blank as $k => $_) $grand[$k] += $r['total'][$k];
        }
        $withMgn = fn ($t) => $t + ['mgn' => $t['cost'] > 0 ? round($t['pl'] / $t['cost'] * 100, 1) : 0];
        $totByDate = array_map($withMgn, $totByDate);
        $grand = $withMgn($grand);

        $actions = self::ACTIONS;

        return view('date_range', compact(
            'pageTitle', 'activePage', 'from', 'to', 'actionDay', 'actionShifted', 'prevDay', 'dates', 'capped', 'maxDays', 'usdRate',
            'viewUserId', 'adxOptions', 'adxFilter', 'activeOnly', 'rows', 'totByDate', 'grand',
            'actions', 'actionCounts', 'dbOk', 'error'
        ));
    }

    // ── Daily link rules (checked on the Date Range page) ──
    const TEST_DAYS  = 3;      // a new link runs this many spend days before it is judged
    const TEST_MIN   = 10000;  // test spend range, per day
    const TEST_MAX   = 13000;
    const SAME_SPEND = 1000;   // spend within this of yesterday counts as "the same"
    const SCALE_ADD  = 5000;   // campaign budget to add to a profitable link
    const MIN_MARGIN = 10;     // % margin needed before adding that budget
    const TEST_FAIL_MARGIN = -30; // % margin over the test days so far that ends a test early

    /** Action key => label, in the order the filter chips show them. */
    const ACTIONS = [
        'stop'    => 'Stop link',
        'review'  => 'Review',
        'add_new' => 'Add new campaign',
        'add_5k'  => 'Add ₹5,000 campaign',
        'passed'  => 'Test passed',
        'testing' => 'Testing',
        'none'    => 'No action',
        'idle'    => 'Not running',
    ];

    /**
     * What to do with a link, from the judged day, the two days before it, how
     * many days it has had spend so far, and (while testing) each test day:
     *   1. first 3 spend days → testing at ₹10,000–13,000; from day 2, test days
     *      below -30% margin together → stop early; on day 3, the 3 days together
     *      decide: no loss → test passed, loss → stop
     *   2. loss at roughly the same spend as yesterday → stop the link, but only
     *      if yesterday was a loss too or the last 3 days together are a loss;
     *      a single loss day is only flagged for review
     *   3. loss while spend dropped > ₹1,000 → review (rules disagree)
     *   4. loss while spend rose > ₹1,000 → review (losing faster)
     *   5. profit while spend dropped > ₹1,000 → add a new campaign
     *   6. profit at the same spend and margin ≥ 10% → add a ₹5,000 campaign
     *      (thinner margin → hold spend)
     *   7. profit while spend rose > ₹1,000 → no rule applies
     */
    private function linkAdvice(array $today, array $prev, array $prev2, int $spendDays, array $testDays = []): array
    {
        $spend = $today['meta'];
        $diff  = $spend - $prev['meta'];
        $money = fn (float $v) => fmtINR($v);
        $vs    = 'yesterday ' . $money($prev['meta']);
        $pl3   = $today['pl'] + $prev['pl'] + $prev2['pl'];

        if ($spend <= 0) {
            $key  = 'idle';
            $note = 'No spend on the last day';
        } elseif ($spendDays <= self::TEST_DAYS) {
            $testPl   = array_sum(array_column($testDays, 'pl'));
            $testCost = array_sum(array_column($testDays, 'cost'));
            $testMgn  = $testCost > 0 ? round($testPl / $testCost * 100, 1) : 0;
            $sofar    = $money($testPl) . ' (' . fmtPct($testMgn) . ' margin)';

            if ($spendDays >= self::TEST_DAYS && $testPl >= 0) {
                $key  = 'passed';
                $note = self::TEST_DAYS . "-day test P/L $sofar — scale rules apply from the next day";
            } elseif ($spendDays >= self::TEST_DAYS) {
                $key  = 'stop';
                $note = 'Test failed: ' . self::TEST_DAYS . "-day P/L $sofar";
            } elseif ($spendDays >= 2 && $testMgn < self::TEST_FAIL_MARGIN) {
                $key  = 'stop';
                $note = "Test failing early: P/L so far $sofar, below " . self::TEST_FAIL_MARGIN . '%';
            } else {
                $key  = 'testing';
                $band = $spend < self::TEST_MIN ? 'below ₹10,000' : ($spend > self::TEST_MAX ? 'above ₹13,000' : 'in range');
                $note = 'Day ' . max(1, $spendDays) . ' of ' . self::TEST_DAYS . ' · spend ' . $money($spend) . " ($band) · P/L so far $sofar";
            }
        } elseif ($today['pl'] < 0 && abs($diff) <= self::SAME_SPEND) {
            if ($prev['pl'] < 0) {
                $key  = 'stop';
                $note = '2nd loss day in a row: ' . $money($today['pl']) . ' at ' . $money($spend) . " spend ($vs)";
            } elseif ($pl3 < 0) {
                $key  = 'stop';
                $note = 'Loss ' . $money($today['pl']) . '; last 3 days together ' . $money($pl3);
            } else {
                $key  = 'review';
                $note = 'First loss day (' . $money($today['pl']) . ') — stop if the next day is a loss too';
            }
        } elseif ($today['pl'] < 0 && $diff > self::SAME_SPEND) {
            $key  = 'review';
            $note = 'Loss ' . $money($today['pl']) . ' while spend rose ' . $money($diff) . " ($vs)";
        } elseif ($today['pl'] < 0 && $diff < -self::SAME_SPEND) {
            $key  = 'review';
            $note = 'Loss ' . $money($today['pl']) . ', spend down ' . $money(-$diff) . " ($vs)";
        } elseif ($diff < -self::SAME_SPEND) {
            $key  = 'add_new';
            $note = 'Spend down ' . $money(-$diff) . ': ' . $money($prev['meta']) . ' → ' . $money($spend);
        } elseif (abs($diff) <= self::SAME_SPEND && $today['mgn'] >= self::MIN_MARGIN) {
            $key  = 'add_5k';
            $note = 'Profit ' . $money($today['pl']) . ' (' . fmtPct($today['mgn']) . ' margin) at steady ' . $money($spend) . " spend ($vs)";
        } elseif (abs($diff) <= self::SAME_SPEND) {
            $key  = 'none';
            $note = 'Profit ' . $money($today['pl']) . ' but only ' . fmtPct($today['mgn']) . ' margin (under ' . self::MIN_MARGIN . '%) — hold spend';
        } else {
            $key  = 'none';
            $note = 'Spend up ' . $money($diff) . " ($vs) — no rule applies";
        }

        return ['key' => $key, 'label' => self::ACTIONS[$key], 'note' => $note];
    }

    /** Stream the date-wise report as CSV (per-date columns for each metric). */
    private function dateWiseCsv(array $rows, string $date1, string $date2, bool $lastGamOnly)
    {
        $d1 = (new DateTime($date1))->format('d M Y');
        $d2 = (new DateTime($date2))->format('d M Y');

        $metrics = [
            'GAM Rev ($)'     => fn ($m) => number_format($m['gamUsd'], 2, '.', ''),
            'GAM Revenue (Rs)'=> fn ($m) => number_format($m['gamInr'], 2, '.', ''),
            'Meta Spend'      => fn ($m) => number_format($m['meta'], 2, '.', ''),
            'GST'             => fn ($m) => number_format($m['gst'], 2, '.', ''),
            'Total Cost'      => fn ($m) => number_format($m['cost'], 2, '.', ''),
            'Net P/L'         => fn ($m) => number_format($m['pl'], 2, '.', ''),
            'Margin (%)'      => fn ($m) => number_format($m['mgn'], 1, '.', ''),
        ];
        $showMeta = showMeta();
        if (!$showMeta) {
            $metrics = array_intersect_key($metrics, array_flip(['GAM Rev ($)', 'GAM Revenue (Rs)']));
        }

        $header = $showMeta ? ['META URL', 'GAM URL', 'ADX'] : ['GAM URL', 'ADX'];
        foreach ($metrics as $label => $_) { $header[] = "$label ($d1)"; $header[] = "$label ($d2)"; }
        if ($showMeta) $header[] = 'Status';
        array_push($header, 'User', 'Notes');

        $filename = 'date-wise-' . $date1 . '-vs-' . $date2 . ($lastGamOnly ? '-lastgam' : '') . '.csv';

        return response()->streamDownload(function () use ($rows, $metrics, $header, $lastGamOnly, $showMeta) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
            fputcsv($out, $header);
            foreach ($rows as $r) {
                $gam = $r['gam_url'];
                if ($lastGamOnly) {
                    $hosts = array_values(array_filter(array_map('trim', explode(',', $r['gam_url']))));
                    $gam   = $hosts ? end($hosts) : '';
                }
                $line = $showMeta ? [$r['meta_url'], $gam, $r['adx_name']] : [$gam, $r['adx_name']];
                foreach ($metrics as $fn) { $line[] = $fn($r['d1']); $line[] = $fn($r['d2']); }
                if ($showMeta) {
                    $combinedPL = $r['d1']['pl'] + $r['d2']['pl'];
                    $line[] = !$r['has'] ? 'No data' : ($combinedPL >= 0 ? 'Profit' : 'Loss');
                }
                $line[] = $r['owner_name'];
                $line[] = $r['notes'];
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
