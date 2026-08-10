<?php

declare(strict_types=1);
/**
 * This file is part of huangdijia/laravel-trigger.
 *
 * @link     https://github.com/huangdijia/laravel-trigger
 * @document https://github.com/huangdijia/laravel-trigger/blob/4.x/README.md
 * @contact  huangdijia@gmail.com
 */

namespace Huangdijia\Trigger\Console;

use Huangdijia\Trigger\Facades\Trigger;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'trigger:status {--R|replication=default : replication}';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Install config and routes.';

    public function handle()
    {
        $replication = $this->option('replication');
        $trigger = Trigger::replication($replication);
        $binLogCurrent = $trigger->getCurrent();

        if (is_null($binLogCurrent)) {
            $this->warn('binlog info of ' . OutputFormatter::escape((string) $replication) . ' is empty.');
            return;
        }

        $this->table(
            ['Name', 'Value'],
            [
                ['BinLogPosition', OutputFormatter::escape($binLogCurrent->getBinLogPosition())],
                ['BinFileName', OutputFormatter::escape($binLogCurrent->getBinFileName())],
                // ['Gtid', $binLogCurrent->getGtid()],
                // ['MariaDbGtid', $binLogCurrent->getMariaDbGtid()],
            ]
        );
    }
}
