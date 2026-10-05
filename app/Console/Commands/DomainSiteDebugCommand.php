<?php

namespace App\Console\Commands;

use App\Services\GamAdUnit;
use Illuminate\Console\Command;

/**
 * Diagnostic: print the raw GAM Site record for a domain so we can see every
 * field GAM returns (approvalStatus, active, …) and map "Ready" correctly.
 *
 *   php artisan domains:site-debug --domain=128
 */
class DomainSiteDebugCommand extends Command
{
    protected $signature = 'domains:site-debug {--domain=0 : Domain id to inspect}';
    protected $description = 'Dump the raw GAM Site record for a domain (diagnostic)';

    public function handle(GamAdUnit $svc): int
    {
        $id = (int) $this->option('domain');
        if (!$id) { $this->error('Pass --domain=ID'); return self::FAILURE; }

        $stmt = getDB()->prepare("SELECT d.name, d.gam_site_id, a.network_code, a.key_file
                                  FROM domains d LEFT JOIN adx a ON a.id = d.adx_id WHERE d.id = ?");
        $stmt->execute([$id]);
        $d = $stmt->fetch();
        if (!$d)                        { $this->error("Domain #$id not found.");            return self::FAILURE; }
        if (empty($d['gam_site_id']))   { $this->error("{$d['name']} has no gam_site_id yet — run domains:sync-sites."); return self::FAILURE; }

        $res = $svc->siteRaw(['network_code' => $d['network_code'], 'key_file' => $d['key_file']], (string) $d['gam_site_id']);
        if (!$res['ok']) { $this->error($res['msg']); return self::FAILURE; }

        $this->info("Raw GAM Site record for {$d['name']} (site {$d['gam_site_id']}):");
        $this->line($res['xml']);
        return self::SUCCESS;
    }
}
