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

use Huangdijia\Trigger\Manager;
use Huangdijia\Trigger\Trigger;
use Huangdijia\Trigger\TriggerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use MySQLReplication\BinLog\BinLogCurrent;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class StatusCommandTest extends TestCase
{
    public function testMissingCheckpointWarningEscapesTheReplicationName(): void
    {
        $trigger = $this->createMock(Trigger::class);
        $trigger->expects(self::once())->method('getCurrent')->willReturn(null);
        $this->bindTrigger('<fg=invalid>default</>\\', $trigger);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--replication' => '<fg=invalid>default</>\\']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString(
            'binlog info of <fg=invalid>default</>\ is empty.',
            $tester->getDisplay(),
        );
    }

    public function testCheckpointTableEscapesStoredFilenameAndPosition(): void
    {
        $current = new BinLogCurrent();
        $current->setBinFileName('<fg=invalid>mysql-bin.000001');
        $current->setBinLogPosition('<fg=invalid>4</>\\');

        $trigger = $this->createMock(Trigger::class);
        $trigger->expects(self::once())->method('getCurrent')->willReturn($current);
        $this->bindTrigger('default', $trigger);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--replication' => 'default']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('<fg=invalid>mysql-bin.000001', $display);
        self::assertStringContainsString('<fg=invalid>4</>\\', $display);
    }

    /**
     * @param mixed $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TriggerServiceProvider::class];
    }

    private function bindTrigger(string $replication, Trigger $trigger): void
    {
        $manager = $this->createMock(Manager::class);
        $manager->expects(self::once())->method('replication')->with($replication)->willReturn($trigger);

        $this->app->instance('trigger.manager', $manager);
        \Huangdijia\Trigger\Facades\Trigger::clearResolvedInstance('trigger.manager');
    }

    private function commandTester(): CommandTester
    {
        $command = Artisan::all()['trigger:status'];

        return new CommandTester($command);
    }
}
