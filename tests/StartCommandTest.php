<?php

declare(strict_types=1);
/**
 * This file is part of huangdijia/laravel-trigger.
 *
 * @link     https://github.com/huangdijia/laravel-trigger
 * @document https://github.com/huangdijia/laravel-trigger/blob/4.x/README.md
 * @contact  huangdijia@gmail.com
 */

namespace Huangdijia\Trigger\Tests;

use Huangdijia\Trigger\Console\StartCommand;
use MySQLReplication\Exception\MySQLReplicationException;
use MySQLReplication\Socket\SocketException;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;

/**
 * @internal
 */
final class StartCommandTest extends TestCase
{
    public function testOnlyTransientSocketFailuresAreRetriedAutomatically(): void
    {
        $method = new ReflectionMethod(StartCommand::class, 'shouldRetryReplication');
        $command = new StartCommand();

        self::assertTrue($method->invoke($command, new SocketException('Connection reset', 104)));
        self::assertFalse($method->invoke($command, new MySQLReplicationException('Unknown row type')));
    }
}
