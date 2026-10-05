<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of save_value.php — AJAX endpoint that saves GAM revenue and/or Meta
 * spend for a dashboard row and returns recalculated, formatted values.
 */
class SaveValueController extends Controller
{
    public function store(Request $request)
    {
        if (!userCan('dashboard', 'edit')) {
            return response()->json(['ok' => false, 'msg' => 'You do not have permission to edit dashboard values.'], 403);
        }

        $date      = $request->input('date', '');
        $gamSites  = json_decode($request->input('gam_sites', '[]'), true);
        $gamUsd    = $request->input('gam_usd', null);
        $metaSpend = $request->input('meta_spend', null);
        $campaigns = json_decode($request->input('meta_campaigns', '[]'), true);
        if (!is_array($gamSites))  $gamSites  = [];
        if (!is_array($campaigns)) $campaigns = [];

        // Normalise "" → null the way the original ($_POST default null) did not,
        // but the original treats '' as "not edited" below, so keep '' as-is.

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['ok' => false, 'msg' => 'Invalid date.']);
        }
        if ($gamUsd !== null && (float) $gamUsd < 0) {
            return response()->json(['ok' => false, 'msg' => 'GAM revenue cannot be negative.']);
        }
        if ($metaSpend !== null && (float) $metaSpend < 0) {
            return response()->json(['ok' => false, 'msg' => 'Meta spend cannot be negative.']);
        }

        try {
            $pdo     = getDB();
            $usdRate = getCurrencyRate('USD');
            $gstRate = (float) getSetting('gst_rate', '18');

            // ── Save GAM revenue (global, user_id = 0) ──
            $GAM_OWNER = 0;
            if ($gamUsd !== null && $gamUsd !== '' && !empty($gamSites)) {
                $usd = (float) $gamUsd;

                if (count($gamSites) === 1) {
                    $pdo->prepare("
                        INSERT INTO gam_data (user_id, site, date, revenue_usd, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE revenue_usd = VALUES(revenue_usd), updated_at = NOW()
                    ")->execute([$GAM_OWNER, $gamSites[0], $date, $usd]);
                } else {
                    $placeholders = implode(',', array_fill(0, count($gamSites), '?'));
                    $stmt = $pdo->prepare("SELECT site, SUM(revenue_usd) rev FROM gam_data WHERE site IN ($placeholders) AND date = ? GROUP BY site");
                    $stmt->execute([...$gamSites, $date]);
                    $existing = [];
                    foreach ($stmt->fetchAll() as $row) {
                        $existing[$row['site']] = (float) $row['rev'];
                    }
                    $oldTotal = array_sum($existing) ?: 1;

                    $upsert = $pdo->prepare("
                        INSERT INTO gam_data (user_id, site, date, revenue_usd, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE revenue_usd = VALUES(revenue_usd), updated_at = NOW()
                    ");
                    $distributed = 0;
                    foreach ($gamSites as $i => $site) {
                        if ($i === count($gamSites) - 1) {
                            $share = round($usd - $distributed, 6);
                        } else {
                            $ratio = isset($existing[$site]) ? $existing[$site] / $oldTotal : 1 / count($gamSites);
                            $share = round($usd * $ratio, 6);
                            $distributed += $share;
                        }
                        $upsert->execute([$GAM_OWNER, $site, $date, $share]);
                    }
                }
            } else {
                $usd = null;
            }

            // ── Save Meta spend (global, user_id = 0) ──
            $META_OWNER = 0;
            if ($metaSpend !== null && $metaSpend !== '' && !empty($campaigns)) {
                $newTotal = (float) $metaSpend;

                if (count($campaigns) === 1) {
                    $pdo->prepare("
                        INSERT INTO meta_data (user_id, campaign_name, date, amount_spent, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE amount_spent = VALUES(amount_spent), updated_at = NOW()
                    ")->execute([$META_OWNER, $campaigns[0], $date, $newTotal]);
                } else {
                    $placeholders = implode(',', array_fill(0, count($campaigns), '?'));
                    $stmt = $pdo->prepare("SELECT campaign_name, SUM(amount_spent) AS amount_spent FROM meta_data WHERE campaign_name IN ($placeholders) AND date = ? GROUP BY campaign_name");
                    $stmt->execute([...$campaigns, $date]);
                    $existing = [];
                    foreach ($stmt->fetchAll() as $row) {
                        $existing[$row['campaign_name']] = (float) $row['amount_spent'];
                    }
                    $oldTotal = array_sum($existing) ?: 1;

                    $upsert = $pdo->prepare("
                        INSERT INTO meta_data (user_id, campaign_name, date, amount_spent, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE amount_spent = VALUES(amount_spent), updated_at = NOW()
                    ");
                    $distributed = 0;
                    foreach ($campaigns as $i => $camp) {
                        if ($i === count($campaigns) - 1) {
                            $share = round($newTotal - $distributed, 4);
                        } else {
                            $ratio = isset($existing[$camp]) ? $existing[$camp] / $oldTotal : 1 / count($campaigns);
                            $share = round($newTotal * $ratio, 4);
                            $distributed += $share;
                        }
                        $upsert->execute([$META_OWNER, $camp, $date, $share]);
                    }
                }
                $spend = $newTotal;
            } else {
                if (!empty($campaigns)) {
                    $placeholders = implode(',', array_fill(0, count($campaigns), '?'));
                    $stmt = $pdo->prepare("SELECT SUM(amount_spent) FROM meta_data WHERE campaign_name IN ($placeholders) AND date = ?");
                    $stmt->execute([...$campaigns, $date]);
                    $spend = (float) $stmt->fetchColumn();
                } else {
                    $spend = 0;
                }
            }

            // ── Read final GAM USD (sum across sites, global) if not just saved ──
            if ($usd === null) {
                if (!empty($gamSites)) {
                    $placeholders = implode(',', array_fill(0, count($gamSites), '?'));
                    $stmt = $pdo->prepare("SELECT SUM(revenue_usd) FROM gam_data WHERE site IN ($placeholders) AND date = ?");
                    $stmt->execute([...$gamSites, $date]);
                    $usd = (float) ($stmt->fetchColumn() ?: 0);
                } else {
                    $usd = 0;
                }
            }

            // ── Recalculate derived values ──
            $gamInr = round($usd  * $usdRate, 2);
            $gst    = round($spend * $gstRate / 100, 2);
            $cost   = round($spend + $gst, 2);
            $netPL  = round($gamInr - $cost, 2);
            $margin = $cost > 0 ? round($netPL / $cost * 100, 1) : null;

            $f = fn (float $n) => '₹' . number_format($n, 2);

            return response()->json([
                'ok'          => true,
                'gam_usd'     => $usd,
                'gam_usd_fmt' => '$' . number_format($usd, 2),
                'gam_inr_fmt' => $f($gamInr),
                'spend_fmt'     => $f($spend),
                'spend_usd_fmt' => '$' . number_format($usdRate > 0 ? $spend / $usdRate : 0, 2),
                'gst_fmt'     => $f($gst),
                'cost_fmt'    => $f($cost),
                'netpl'       => $netPL,
                'netpl_fmt'   => $f($netPL),
                'margin'      => $margin,
                'margin_fmt'  => $margin !== null ? number_format($margin, 1) . '%' : '—',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
}
