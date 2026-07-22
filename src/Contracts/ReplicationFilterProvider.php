<?php

declare(strict_types=1);
/**
 * This file is part of huangdijia/laravel-trigger.
 *
 * @link     https://github.com/huangdijia/laravel-trigger
 * @document https://github.com/huangdijia/laravel-trigger/blob/4.x/README.md
 * @contact  huangdijia@gmail.com
 */

namespace Huangdijia\Trigger\Contracts;

interface ReplicationFilterProvider
{
    /**
     * @return array<int, string>
     */
    public function replicationDatabases(): array;

    /**
     * @return array<int, string>
     */
    public function replicationTables(): array;

    /**
     * Return low-level integer binlog event type values.
     *
     * @return array<int, int>
     */
    public function replicationEventTypes(): array;
}
