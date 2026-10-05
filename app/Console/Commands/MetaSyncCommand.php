<?php

namespace App\Console\Commands;

use App\Services\MetaSync;
use Illuminate\Console\Command;

/**
 * Port of the CLI mode of meta_sync.php.
 *   php artisan meta:sync [--account=ID] [--force] [--list-accounts]
 */
class MetaSyncCommand extends Command
{
    protected $signature = 'meta:sync {--account=0 : Limit to one Meta account id}
                                      {--force : Bypass the throttle}
                                      {--list-accounts : List ad accounts the token can see}';

    protected $description = 'Pull Meta (Facebook) Ads spend from every sync-ready Meta account into meta_data';

    public function handle(MetaSync $sync): int
    {
        $account = (int) $this->option('account');

        if ($this->option('list-accounts')) {
            $this->line($sync->listAdAccounts($account));
            return self::SUCCESS;
        }

        $res = $sync->run($account, (bool) $this->option('force'));
        $this->line(($res['ok'] ? '[OK] ' : '[ERR] ') . $res['msg']);
        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
