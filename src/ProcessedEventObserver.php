<?php

declare(strict_types=1);
/**
 * This file is part of huangdijia/laravel-trigger.
 *
 * @link     https://github.com/huangdijia/laravel-trigger
 * @document https://github.com/huangdijia/laravel-trigger/blob/4.x/README.md
 * @contact  huangdijia@gmail.com
 */

namespace Huangdijia\Trigger;

use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\EventObserverInterface;

final readonly class ProcessedEventObserver implements EventObserverInterface
{
    public function __construct(private Trigger $trigger)
    {
    }

    public function onEventProcessed(EventInfo $eventInfo, ?EventDTO $eventDTO, bool $dispatched): void
    {
        $this->trigger->handleProcessedEvent($eventInfo, $eventDTO, $dispatched);
    }
}
