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
use Huangdijia\Trigger\Manager;
use Huangdijia\Trigger\Trigger;
use Huangdijia\Trigger\TriggerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\Exception\MySQLReplicationException;
use MySQLReplication\Socket\SocketException;
use Orchestra\Testbench\TestCase;
use PDOException;
use ReflectionMethod;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testVerboseStartupEscapesDynamicConfigurationCheckpointAndSubscriberValues(): void
    {
        $current = new BinLogCurrent();
        $current->setBinFileName('<fg=invalid>mysql-bin.000001');
        $current->setBinLogPosition('<fg=invalid>4');

        $trigger = $this->createMock(Trigger::class);
        $trigger->expects(self::once())
            ->method('getConfig')
            ->willReturn([
                '<fg=invalid>host' => '<fg=invalid>db</>',
                'nested' => ['value' => '<fg=invalid>nested</>'],
                'password' => '<fg=invalid>secret</>',
                'enabled' => false,
                'trailing' => 'value\\',
            ]);
        $trigger->expects(self::once())->method('getCurrent')->willReturn($current);
        $trigger->expects(self::once())->method('getSubscribers')->willReturn(['<fg=invalid>Subscriber</>']);
        $trigger->expects(self::once())->method('start')->with(true);
        $this->bindTrigger($trigger);

        $tester = $this->commandTester('trigger:start');
        $exitCode = $tester->execute([
            '--replication' => 'default',
            '-vvv' => true,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('<fg=invalid>host', $display);
        self::assertStringContainsString('<fg=invalid>db</>', $display);
        self::assertStringContainsString('{"value":"<fg=invalid>nested<\/>"}', $display);
        self::assertStringContainsString('******', $display);
        self::assertStringNotContainsString('<fg=invalid>secret</>', $display);
        self::assertStringContainsString('value\\', $display);
        self::assertStringContainsString('<fg=invalid>mysql-bin.000001', $display);
        self::assertStringContainsString('<fg=invalid>4', $display);
        self::assertStringContainsString('<fg=invalid>Subscriber</>', $display);
    }

    public function testNonRetryableReplicationErrorsRemainTheTerminalExceptionAfterSafeRendering(): void
    {
        $expected = new MySQLReplicationException('<fg=invalid>parser failed</>\\');
        $trigger = $this->createMock(Trigger::class);
        $trigger->expects(self::once())->method('start')->with(true)->willThrowException($expected);
        $this->bindTrigger($trigger);

        $tester = $this->commandTester('trigger:start');

        try {
            $tester->execute(['--replication' => 'default']);
            self::fail('The replication exception should be rethrown.');
        } catch (MySQLReplicationException $exception) {
            self::assertSame($expected, $exception);
        }

        self::assertStringContainsString('<fg=invalid>parser failed</>\\', $tester->getDisplay());
    }

    public function testNonRetryableDatabaseErrorsRemainTheTerminalExceptionAfterSafeRendering(): void
    {
        $expected = new PDOException('<fg=invalid>access denied</>\\');
        $trigger = $this->createMock(Trigger::class);
        $trigger->expects(self::once())->method('start')->with(true)->willThrowException($expected);
        $this->bindTrigger($trigger);

        $tester = $this->commandTester('trigger:start');

        try {
            $tester->execute(['--replication' => 'default']);
            self::fail('The database exception should be rethrown.');
        } catch (PDOException $exception) {
            self::assertSame($expected, $exception);
        }

        self::assertStringContainsString('<fg=invalid>access denied</>\\', $tester->getDisplay());
    }

    public function testRetryableReplicationErrorsRenderSafelyBeforeTheListenerRecovers(): void
    {
        $trigger = $this->createMock(Trigger::class);
        $trigger->expects(self::exactly(2))
            ->method('start')
            ->with(true)
            ->willReturnOnConsecutiveCalls(
                self::throwException(new SocketException('<fg=invalid>connection reset</>\\', 104)),
                null,
            );
        $this->bindTrigger($trigger);

        $tester = $this->commandTester('trigger:start');
        $exitCode = $tester->execute(['--replication' => 'default']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('<fg=invalid>connection reset</>\\', $tester->getDisplay());
        self::assertStringContainsString('Retry now', $tester->getDisplay());
    }

    /**
     * @param mixed $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TriggerServiceProvider::class];
    }

    private function bindTrigger(Trigger $trigger): void
    {
        $manager = $this->createMock(Manager::class);
        $manager->expects(self::once())->method('replication')->with('default')->willReturn($trigger);

        $this->app->instance('trigger.manager', $manager);
        \Huangdijia\Trigger\Facades\Trigger::clearResolvedInstance('trigger.manager');
    }

    private function commandTester(string $command): CommandTester
    {
        $command = Artisan::all()[$command];

        return new CommandTester($command);
    }
}
