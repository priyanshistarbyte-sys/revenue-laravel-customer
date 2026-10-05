<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PDO;

/**
 * GAM Check — flags links whose GAM (Ad Exchange) hourly CTR looks unhealthy.
 *
 * Data comes from the hourly report uploaded on the Upload page (gam_hourly).
 * Per GAM site we look at its most recent available day's hourly rows and flag:
 *   • the last 3 hourly entries are all low CTR, OR
 *   • more than half the entries are low CTR (only when there are > 5 entries).
 * An hourly entry counts as "low CTR" when its CTR is below LOW_CTR (0.5%) —
 * i.e. a near-zero CTR is treated the same as an actual 0% CTR.
 * A link is flagged if any of its GAM sites is flagged.
 */
class GamCheckController extends Controller
{
    /** CTR (in %) at or above this is healthy; anything below counts as a 0-CTR hour. */
    private const LOW_CTR = 0.5;

    /** GAM returns CTR as a 0..1 ratio; multiply by this to display/evaluate it as a %. */
    private const CTR_SCALE = 100;

    public function index(Request $request)
    {
        $pageTitle   = 'GAM Check';
        $activePage  = 'gam_check';
        $flaggedOnly = $request->query('flagged') === '1';
        $fDate       = trim((string) $request->query('date', ''));   // '' = latest per site
        $flash       = session('flash');

        // Sorting - applied to the assembled rows (they are built in PHP, not SQL).
        // No sort = the default task ordering (open first, resolved last).
        $sort = (string) $request->query('sort', '');
        $dir  = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($request->isMethod('post')) {
            $redirect = $this->handlePost($request);
            if ($redirect) return $redirect;
        }

        $rows = [];
        $totalChecked = $flaggedCount = $noDataCount = $resolvedCount = 0;
        $latestDate = null;
        $dates = [];

        try {
            $pdo = getDB();

            // Dates available in the hourly data, for the date filter.
            $dates = $pdo->query("SELECT DISTINCT date FROM gam_hourly ORDER BY date DESC LIMIT 90")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            if ($fDate !== '' && !in_array($fDate, $dates, true)) $fDate = '';   // ignore unknown dates

            // Active links in scope, newest first (GAM sites live on gam_url CSV).
            $links = $pdo->query("SELECT l.*, d.name AS domain_name, a.name AS adx_name
                                  FROM links l
                                  LEFT JOIN domains d ON d.id=l.domain_id
                                  LEFT JOIN adx a ON a.id=d.adx_id
                                  WHERE " . linkScopeWhere('l', 'd') . " AND l.active = 1
                                  ORDER BY l.id DESC")->fetchAll();

            // Resolve/TODO state per link.
            $todo = [];
            foreach ($pdo->query("SELECT link_id, done, note FROM gam_check_todo")->fetchAll() as $t) {
                $todo[(int) $t['link_id']] = ['done' => (bool) $t['done'], 'note' => (string) $t['note']];
            }

            // Hourly rows per site — a chosen date, else each site's latest available day.
            if ($fDate !== '') {
                $hStmt = $pdo->prepare("SELECT g.site, g.date, g.hour, g.ctr, g.revenue, g.impressions
                                        FROM gam_hourly g WHERE g.date = ? ORDER BY g.site, g.hour");
                $hStmt->execute([$fDate]);
                $hourly = $hStmt->fetchAll();
            } else {
                $hourly = $pdo->query("SELECT g.site, g.date, g.hour, g.ctr, g.revenue, g.impressions
                                       FROM gam_hourly g
                                       JOIN (SELECT site, MAX(date) md FROM gam_hourly GROUP BY site) m
                                         ON m.site = g.site AND m.md = g.date
                                       ORDER BY g.site, g.hour")->fetchAll();
            }

            $bySite = [];
            foreach ($hourly as $h) {
                $s = strtolower(trim($h['site']));
                if (!isset($bySite[$s])) $bySite[$s] = ['date' => $h['date'], 'rows' => []];
                $bySite[$s]['rows'][] = [
                    'hour'        => (int) $h['hour'],
                    'ctr'         => (float) $h['ctr'] * self::CTR_SCALE,   // 0..1 ratio → percent
                    'revenue'     => (float) $h['revenue'],
                    'impressions' => (int) $h['impressions'],
                ];
                if ($latestDate === null || $h['date'] > $latestDate) $latestDate = $h['date'];
            }

            foreach ($links as $link) {
                $siteNames = array_values(array_filter(array_map(
                    fn ($s) => strtolower(trim($s)),
                    explode(',', $link['gam_url'] ?? '')
                )));

                $siteReports = [];
                $linkFlagged = false;
                $anyData     = false;
                $reasons     = [];

                foreach ($siteNames as $site) {
                    $data = $bySite[$site] ?? null;
                    if (!$data) {
                        $siteReports[] = ['site' => $site, 'hasData' => false];
                        continue;
                    }
                    $anyData = true;
                    $rep = $this->evaluate($site, $data);
                    $siteReports[] = $rep;
                    if ($rep['flagged']) {
                        $linkFlagged = true;
                        foreach ($rep['reasons'] as $r) $reasons[] = "{$site}: {$r}";
                    }
                }

                $lid  = (int) $link['id'];
                $done = $todo[$lid]['done'] ?? false;
                $note = $todo[$lid]['note'] ?? '';

                $totalChecked++;
                if ($done)          $resolvedCount++;
                elseif (!$anyData)  $noDataCount++;
                elseif ($linkFlagged) $flaggedCount++;

                // A "task" exists when the link is flagged or has been resolved (so it can be reopened).
                $isTask = $linkFlagged || $done;
                if ($flaggedOnly && !$isTask) continue;

                // Totals over the sites that actually have hourly rows - also what the
                // Hours / low-CTR / Day columns sort on.
                $withData = array_values(array_filter($siteReports, fn ($s) => !empty($s['hasData'])));

                $rows[] = [
                    'link'    => $link,
                    'sites'   => $siteReports,
                    'flagged' => $linkFlagged,
                    'anyData' => $anyData,
                    'reasons' => array_values(array_unique($reasons)),
                    'done'    => $done,
                    'note'    => $note,
                    'entry'   => (string) ($link['created_at'] ?? ''),
                    'day'     => (string) ($withData[0]['date'] ?? ''),
                    'totN'    => array_sum(array_map(fn ($s) => $s['n'], $withData)),
                    'totZero' => array_sum(array_map(fn ($s) => $s['zeros'], $withData)),
                ];
            }

            // Open tasks first (flagged & not done), then healthy/no-data, resolved last.
            $rank = fn ($r) => $r['done'] ? 2 : ($r['flagged'] ? 0 : 1);

            $sortKeys = [
                'name'   => fn ($r) => strtolower((string) $r['link']['link_name']),
                'meta'   => fn ($r) => strtolower((string) $r['link']['meta_url']),
                'adx'    => fn ($r) => strtolower((string) ($r['link']['adx_name'] ?? '')),
                'sites'  => fn ($r) => strtolower((string) $r['link']['gam_url']),
                'entry'  => fn ($r) => (string) $r['entry'],
                'day'    => fn ($r) => (string) $r['day'],
                'hours'  => fn ($r) => (int) $r['totN'],
                'low'    => fn ($r) => (int) $r['totZero'],
                'status' => $rank,
            ];

            if (isset($sortKeys[$sort])) {
                $key = $sortKeys[$sort];
                $mul = $dir === 'desc' ? -1 : 1;
                usort($rows, function ($a, $b) use ($key, $mul) {
                    $va = $key($a); $vb = $key($b);
                    // Rows with no value (no hourly data, no date) sink to the bottom
                    // either way, so a sort never buries the rows that matter.
                    $ea = ($va === '' || $va === null); $eb = ($vb === '' || $vb === null);
                    if ($ea !== $eb) return $ea ? 1 : -1;
                    return ($va <=> $vb) * $mul;
                });
            } else {
                usort($rows, fn ($a, $b) => $rank($a) <=> $rank($b));
            }
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'GAM Check error: ' . $e->getMessage()];
        }

        return view('gam_check', compact(
            'pageTitle', 'activePage', 'flash', 'rows', 'flaggedOnly',
            'totalChecked', 'flaggedCount', 'noDataCount', 'resolvedCount', 'latestDate',
            'dates', 'fDate', 'sort', 'dir'
        ) + ['lowCtr' => self::LOW_CTR]);
    }

    /** Handle TODO actions: mark a link resolved/reopened, or save a note. PRG. */
    private function handlePost(Request $request)
    {
        $action = $request->input('action', '');
        if (!userCan('gam_check', 'edit')) abort(403, 'You do not have permission to edit GAM Check to-dos.');
        $linkId = (int) $request->input('link_id', 0);
        // Back to the same view: keep the date filter, flagged toggle and sort intact.
        $qs     = http_build_query($request->query());
        $back   = '/gam_check' . ($qs ? '?' . $qs : '');
        if ($linkId <= 0) return redirect($back);

        $pdo = getDB();

        if ($action === 'toggle_done') {
            $cur  = $pdo->prepare("SELECT done FROM gam_check_todo WHERE link_id = ?");
            $cur->execute([$linkId]);
            $done = (int) !((bool) $cur->fetchColumn());
            $pdo->prepare("INSERT INTO gam_check_todo (link_id, done, resolved_at, created_at, updated_at)
                           VALUES (?, ?, ?, NOW(), NOW())
                           ON DUPLICATE KEY UPDATE done = VALUES(done), resolved_at = VALUES(resolved_at), updated_at = NOW()")
                ->execute([$linkId, $done, $done ? date('Y-m-d H:i:s') : null]);
            return redirect($back)->with('flash', ['type' => 'success', 'msg' => $done ? 'Marked as resolved.' : 'Reopened.']);
        }

        if ($action === 'save_note') {
            $note = trim((string) $request->input('note', ''));
            $pdo->prepare("INSERT INTO gam_check_todo (link_id, note, created_at, updated_at)
                           VALUES (?, ?, NOW(), NOW())
                           ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = NOW()")
                ->execute([$linkId, $note]);
            return redirect($back)->with('flash', ['type' => 'success', 'msg' => 'Note saved.']);
        }

        return redirect($back);
    }

    /**
     * Evaluate one site's latest-day hourly rows against the 0%-CTR rules.
     * Returns a report array the view renders.
     */
    private function evaluate(string $site, array $data): array
    {
        $rows = $data['rows'];
        $n    = count($rows);

        // An hour with CTR below LOW_CTR (0.5%) is treated the same as a 0% CTR hour.
        $isLow = fn ($r) => $r['ctr'] < self::LOW_CTR;

        $zeros = 0;
        foreach ($rows as $r) if ($isLow($r)) $zeros++;

        // Rule 1: the 3 most-recent hourly entries are all low CTR.
        $last3Zero = false;
        if ($n >= 3) {
            $last3Zero = true;
            foreach (array_slice($rows, -3) as $r) {
                if (!$isLow($r)) { $last3Zero = false; break; }
            }
        }

        // Rule 2: > half the entries are low CTR, only when there are > 5 entries.
        $halfZero = ($n > 5) && ($zeros * 2 > $n);

        $reasons = [];
        if ($last3Zero) $reasons[] = 'last 3 hours all < 0.5% CTR';
        if ($halfZero)  $reasons[] = "{$zeros}/{$n} hours < 0.5% CTR (>half)";

        return [
            'site'      => $site,
            'hasData'   => true,
            'date'      => $data['date'],
            'rows'      => $rows,
            'n'         => $n,
            'zeros'     => $zeros,
            'last3Zero' => $last3Zero,
            'halfZero'  => $halfZero,
            'flagged'   => $last3Zero || $halfZero,
            'reasons'   => $reasons,
        ];
    }
}
