<?php

declare(strict_types=1);
/**
 * This file is part of huangdijia/laravel-trigger.
 *
 * @link     https://github.com/huangdijia/laravel-trigger
 * @document https://github.com/huangdijia/laravel-trigger/blob/4.x/README.md
 * @contact  huangdijia@gmail.com
 */

namespace Huangdijia\Trigger\Facades;

use Illuminate\Support\Facades\Facade;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\EventInfo;

/**
 * @see \Huangdijia\Trigger\Manager
 * @method static \Huangdijia\Trigger\Trigger replication(?string $name = null)
 * @method static array<string, \Huangdijia\Trigger\Trigger> replications()
 * @see Huangdijia\Trigger\Trigger
 * @method static \MySQLReplication\Config\Config configure(bool $keepUp = true)
 * @method static mixed getConfig(string $key = '', mixed $default = null)
 * @method static array<int, mixed> getSubscribers()
 * @method static void loadRoutes()
 * @method static void start(bool $keepUp)
 * @method static void terminate()
 * @method static boolean isTerminated()
 * @method static void heartbeat(EventDTO $event)
 * @method static void observeProcessedEvents(callable(EventInfo, ?EventDTO, bool): void $observer)
 * @method static void rememberCurrent(BinLogCurrent $binLogCurrent)
 * @method static null|\MySQLReplication\BinLog\BinLogCurrent getCurrent()
 * @method static void clearCurrent()
 * @method static void on(string $table, $eventType, $action = null)
 * @method static void dispatch(EventDTO $event)
 * @method static void fire($events, EventDTO $event = null)
 * @method static array<string, array<string, array<string, array<int, mixed>>>> getEvents()
 */
class Trigger extends Facade
{
    public static function getFacadeAccessor()
    {
        return 'trigger.manager';
    }
}
