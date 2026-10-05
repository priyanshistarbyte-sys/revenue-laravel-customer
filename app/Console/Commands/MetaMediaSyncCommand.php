<?php

namespace App\Console\Commands;

use App\Services\MetaMediaSync;
use Illuminate\Console\Command;

/**
 * Sync Creative-Hub media (ad images / ad videos) for the campaign builder.
 *   php artisan meta:media-sync [--account=ID]
 */
class MetaMediaSyncCommand extends Command
{
    protected $signature = 'meta:media-sync {--account=0 : Limit to one Meta account id}';
    protected $description = 'Sync Meta ad images and videos for the campaign builder media pickers';

    public function handle(MetaMediaSync $sync): int
    {
        $res = $sync->run((int) $this->option('account'));
        $this->line(($res['ok'] ? '[OK] ' : '[ERR] ') . $res['msg']);
        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
