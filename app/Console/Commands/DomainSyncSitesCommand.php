<?php

namespace App\Console\Commands;

use App\Services\GamAdUnit;
use Illuminate\Console\Command;

/**
 * Backfill gam_site_id for domains that already exist as GAM sites but don't
 * have the id stored yet (e.g. added before site-tracking, or created in GAM).
 * Looks each domain up in GAM by its URL and stores the matching site id.
 *
 *   php artisan domains:sync-sites [--force] [--domain=ID]
 */
class DomainSyncSitesCommand extends Command
{
    protected $signature = 'domains:sync-sites
                                {--force  : Re-look-up even domains that already have a site id}
                                {--domain=0 : Limit to one domain id}';

    protected $description = 'Backfill each domain\'s GAM site id by looking it up in GAM by URL';

    public function handle(GamAdUnit $svc): int
    {
        $pdo = getDB();

        // Only domains attached to a GAM-enabled ADX network can be looked up.
        $where = "a.network_code IS NOT NULL AND a.network_code <> ''";
        if (!$this->option('force'))       $where .= " AND (d.gam_site_id IS NULL OR d.gam_site_id = '')";
        if ((int) $this->option('domain')) $where .= " AND d.id = " . (int) $this->option('domain');

        $rows = $pdo->query("SELECT d.id, d.name, a.network_code, a.key_file
                             FROM domains d LEFT JOIN adx a ON a.id = d.adx_id
                             WHERE $where ORDER BY d.name")->fetchAll();

        if (!$rows) {
            $this->info('No domains to look up (all have a site id, or none are on a GAM network).');
            return self::SUCCESS;
        }

        $upd     = $pdo->prepare("UPDATE domains SET gam_site_id = ?, updated_at = NOW() WHERE id = ?");
        $found   = $missing = $errors = 0;

        foreach ($rows as $d) {
            $res = $svc->findSite(
                ['network_code' => $d['network_code'], 'key_file' => $d['key_file']],
                (string) $d['name']
            );

            if (!$res['ok']) {
                $errors++;
                $this->warn("  {$d['name']}: ERROR {$res['msg']}");
                continue;
            }
            if (empty($res['site_id'])) {
                $missing++;
                $this->line("  {$d['name']}: no matching GAM site");
                continue;
            }

            $upd->execute([$res['site_id'], $d['id']]);
            $found++;
            $this->line("  {$d['name']}: site {$res['site_id']}" . (!empty($res['status']) ? " ({$res['status']})" : ''));
        }

        $this->info("Linked $found · not-found $missing · errors $errors.");
        return self::SUCCESS;
    }
}
