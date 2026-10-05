<?php

namespace App\Console\Commands;

use App\Services\GamSync;
use Illuminate\Console\Command;

/**
 * Port of the CLI mode of gam_sync.php.
 *   php artisan gam:sync [--account=ID] [--force] [--list-reports]
 */
class GamSyncCommand extends Command
{
    protected $signature = 'gam:sync {--account=0 : Limit to one ADX network id}
                                     {--force : Bypass the throttle}
                                     {--list-reports : List saved reports the service account can see}';

    protected $description = 'Pull GAM revenue from every active GAM-enabled ADX network into gam_data';

    public function handle(GamSync $sync): int
    {
        $account = (int) $this->option('account');

        if ($this->option('list-reports')) {
            $this->line($sync->listReports($account));
            return self::SUCCESS;
        }

        $res = $sync->run($account, (bool) $this->option('force'));
        $this->line(($res['ok'] ? '[OK] ' : '[ERR] ') . $res['msg']);
        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
