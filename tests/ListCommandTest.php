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

use Closure;
use Huangdijia\Trigger\Manager;
use Huangdijia\Trigger\Trigger;
use Huangdijia\Trigger\TriggerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class ListCommandTest extends TestCase
{
    public function testTableEscapesMarkupInRegisteredEventCells(): void
    {
        $this->bindEvents($this->markupEvents());

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--replication' => 'default']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('<fg=invalid>database', $display);
        self::assertStringContainsString('<options=nope>table', $display);
        self::assertStringContainsString('<info>write</error>', $display);
        self::assertStringContainsString('<fg=invalid>App\Handler@handle</>\\', $display);
    }

    public function testFiltersMatchRawValuesBeforeDisplayEscaping(): void
    {
        foreach ([
            '--database' => '<fg=invalid>database',
            '--table' => '<options=nope>table',
            '--event' => '<info>write</error>',
        ] as $option => $value) {
            $this->bindEvents($this->markupEvents());

            $tester = $this->commandTester();
            $exitCode = $tester->execute([
                '--replication' => 'default',
                $option => $value,
            ]);

            self::assertSame(0, $exitCode);
            self::assertStringContainsString('<options=nope>table', $tester->getDisplay());
        }

        $this->bindEvents($this->markupEvents());

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--replication' => 'default',
            '--database' => OutputFormatter::escape('<fg=invalid>database'),
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('<options=nope>table', $tester->getDisplay());
    }

    public function testMisparsedNameFragmentsStillRenderSafely(): void
    {
        $this->bindEvents([
            'database' => [
                'table' => [
                    'event' => [
                        '<fg=invalid>fragment' => [
                            'write' => ['App\Handler@handle'],
                        ],
                    ],
                ],
            ],
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--replication' => 'default']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('<fg=invalid>fragment', $tester->getDisplay());
    }

    public function testProductionEventShapeRendersUnchanged(): void
    {
        $this->bindEvents([
            '*' => [
                '*' => [
                    'heartbeat' => [static function (): void {}],
                ],
            ],
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--replication' => 'default']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('heartbeat', $display);
        self::assertStringContainsString(Closure::class, $display);
    }

    /**
     * @param mixed $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TriggerServiceProvider::class];
    }

    /**
     * @param array<string, mixed> $events
     */
    private function bindEvents(array $events): void
    {
        $trigger = $this->createMock(Trigger::class);
        $trigger->expects(self::once())->method('getEvents')->willReturn($events);

        $manager = $this->createMock(Manager::class);
        $manager->expects(self::once())->method('replication')->with('default')->willReturn($trigger);

        $this->app->instance('trigger.manager', $manager);
        \Huangdijia\Trigger\Facades\Trigger::clearResolvedInstance('trigger.manager');
    }

    private function commandTester(): CommandTester
    {
        return new CommandTester(Artisan::all()['trigger:list']);
    }

    /**
     * @return array<string, mixed>
     */
    private function markupEvents(): array
    {
        return [
            '<fg=invalid>database' => [
                '<options=nope>table' => [
                    '<info>write</error>' => ['<fg=invalid>App\Handler@handle</>\\'],
                ],
            ],
        ];
    }
}
