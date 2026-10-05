<?php

namespace App\Console\Commands;

use App\Services\MetaAssetsSync;
use Illuminate\Console\Command;

/**
 * Sync Meta ad accounts / pages / pixels for the campaign builder.
 *   php artisan meta:assets-sync [--account=ID]
 */
class MetaAssetsSyncCommand extends Command
{
    protected $signature = 'meta:assets-sync {--account=0 : Limit to one Meta account id}';
    protected $description = 'Sync Meta ad accounts, pages and pixels for the campaign builder';

    public function handle(MetaAssetsSync $sync): int
    {
        $res = $sync->run((int) $this->option('account'));
        $this->line(($res['ok'] ? '[OK] ' : '[ERR] ') . $res['msg']);
        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
