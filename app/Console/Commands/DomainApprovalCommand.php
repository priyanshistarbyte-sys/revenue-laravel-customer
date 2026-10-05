<?php

namespace App\Console\Commands;

use App\Services\GamAdUnit;
use Illuminate\Console\Command;

/**
 * Check the GAM Ad Exchange site approval status for each domain that has a
 * registered site, and flip the domain to Approved once GAM reports APPROVED.
 *
 *   php artisan domains:check-approval [--force] [--domain=ID]
 */
class DomainApprovalCommand extends Command
{
    protected $signature = 'domains:check-approval
                                {--force  : Re-check domains already marked approved}
                                {--domain=0 : Limit to one domain id}';

    protected $description = 'Mark domains approved when their GAM site is approved';

    public function handle(GamAdUnit $svc): int
    {
        $pdo = getDB();

        $where = "d.gam_site_id IS NOT NULL AND d.gam_site_id <> ''";
        if (!$this->option('force'))       $where .= " AND d.approved = 0";
        if ((int) $this->option('domain')) $where .= " AND d.id = " . (int) $this->option('domain');

        $rows = $pdo->query("SELECT d.id, d.name, d.gam_site_id, a.network_code, a.key_file
                             FROM domains d LEFT JOIN adx a ON a.id = d.adx_id
                             WHERE $where ORDER BY d.name")->fetchAll();

        if (!$rows) {
            $this->info('No domains with a GAM site to check.');
            return self::SUCCESS;
        }

        $upd = $pdo->prepare("UPDATE domains SET approved = 1, updated_at = NOW() WHERE id = ?");
        $checked = $approved = $errors = 0;

        foreach ($rows as $d) {
            $checked++;
            $res = $svc->siteApprovalStatus(
                ['network_code' => $d['network_code'], 'key_file' => $d['key_file']],
                (string) $d['gam_site_id']
            );

            if (!$res['ok']) {
                $errors++;
                $this->warn("  {$d['name']}: ERROR {$res['msg']}");
                continue;
            }

            // Treat any "serving / ready" status as approved. GAM SiteService uses
            // APPROVED; the AdSense/monetization Sites view shows READY.
            $status  = strtoupper((string) ($res['status'] ?? ''));
            $okStates = ['APPROVED', 'READY', 'ACTIVE', 'SERVING'];
            if (in_array($status, $okStates, true)) {
                $upd->execute([$d['id']]);
                $approved++;
                $this->line("  {$d['name']}: {$status} → marked approved");
            } else {
                $this->line("  {$d['name']}: {$status}");
            }
        }

        $this->info("Checked $checked · newly approved $approved · errors $errors.");
        return self::SUCCESS;
    }
}
