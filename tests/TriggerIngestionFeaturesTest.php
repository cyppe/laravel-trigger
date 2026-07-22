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

use Doctrine\DBAL\Connection;
use Huangdijia\Trigger\Contracts\ReplicationFilterProvider;
use Huangdijia\Trigger\EventSubscriber;
use Huangdijia\Trigger\Trigger;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use LogicException;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\EventInfo;
use Orchestra\Testbench\TestCase;

/**
 * @internal
 */
final class TriggerIngestionFeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function testLowLevelFiltersAndMetadataSwitchAreMappedToTheReplicationConfig(): void
    {
        $config = $this->trigger('filter-mapping', [
            'events_only' => '23,24,25',
            'events_ignore' => ['2', 16, 29],
            'databases_ignore' => ['archive'],
            'tables_ignore' => 'logs,audit',
            'databases_regex' => ['/^shop_/'],
            'tables_regex' => '/^af_/',
            'use_table_map_metadata' => true,
        ])->configure(false);

        self::assertSame([23, 24, 25], $config->eventsOnly);
        self::assertSame([2, 16, 29], $config->eventsIgnore);
        self::assertFalse($config->checkDataBasesOnly('shop_se'));
        self::assertFalse($config->checkTablesOnly('af_products'));

        if (property_exists($config, 'databasesIgnore')) {
            self::assertSame(['archive'], $config->databasesIgnore);
            self::assertSame(['logs', 'audit'], $config->tablesIgnore);
            self::assertTrue($config->useTableMapMetadata);
            self::assertTrue($config->checkTablesIgnore('audit'));
        }
    }

    public function testNewBehaviorChangingOptionsDefaultOff(): void
    {
        $config = $this->trigger('defaults')->configure(false);

        self::assertSame([], $config->eventsOnly);
        self::assertSame([], $config->eventsIgnore);

        if (property_exists($config, 'databasesIgnore')) {
            self::assertSame([], $config->databasesIgnore);
            self::assertSame([], $config->tablesIgnore);
            self::assertFalse($config->useTableMapMetadata);
        }
    }

    public function testIgnoredXidRequiresRawObserverSupportForSafeCheckpoints(): void
    {
        if (interface_exists('MySQLReplication\Event\EventObserverInterface')) {
            self::assertSame(
                [ConstEventType::XID_EVENT->value],
                $this->trigger('observer-xid', [
                    'checkpoint_mode' => 'shadow',
                    'events_ignore' => [ConstEventType::XID_EVENT->value],
                ])->configure(false)->eventsIgnore,
            );

            return;
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires php-mysql-replication event observer support');

        $this->trigger('no-observer-xid', [
            'checkpoint_mode' => 'shadow',
            'events_ignore' => [ConstEventType::XID_EVENT->value],
        ])->configure(false);
    }

    public function testEventsOnlyCannotHideXidFromSafeCheckpointsWithoutRawObserverSupport(): void
    {
        if (interface_exists('MySQLReplication\Event\EventObserverInterface')) {
            self::assertTrue(true);

            return;
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires php-mysql-replication event observer support');

        $this->trigger('no-observer-events-only', [
            'checkpoint_mode' => 'shadow',
            'events_only' => [ConstEventType::UPDATE_ROWS_EVENT_V2->value],
        ])->configure(false);
    }

    public function testFilterProviderCompilesStaticDatabaseTableAndEventFilters(): void
    {
        $trigger = $this->trigger('provider', [
            'subscribers' => [TestReplicationFilterProvider::class],
        ]);

        $trigger->detectDatabasesAndTables();
        $config = $trigger->configure(false);

        self::assertSame(['source'], $config->databasesOnly);
        self::assertSame(['products', 'categories'], $config->tablesOnly);
        self::assertSame([
            ConstEventType::WRITE_ROWS_EVENT_V2->value,
            ConstEventType::UPDATE_ROWS_EVENT_V2->value,
            ConstEventType::DELETE_ROWS_EVENT_V2->value,
        ], $config->eventsOnly);
    }

    public function testWildcardRouteStillBlocksAutomaticEventFiltering(): void
    {
        $trigger = $this->trigger('wildcard-provider', [
            'subscribers' => [TestReplicationFilterProvider::class],
        ]);
        $trigger->on('*', '*', static fn (): null => null);

        $trigger->detectDatabasesAndTables();

        self::assertSame([], $trigger->configure(false)->eventsOnly);
    }

    public function testProcessedEventTapSeesFilteredEventsAndAdvancesIgnoredXidSafely(): void
    {
        if (! interface_exists('MySQLReplication\Event\EventObserverInterface')) {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Processed-event observers require');

            $this->trigger('unsupported-raw-observer')->observeProcessedEvents(static function (): void {});

            return;
        }

        $name = 'raw-observer';
        $trigger = $this->trigger($name, [
            'checkpoint_mode' => 'shadow',
            'checkpoint_interval' => 1,
        ]);
        $observed = [];
        $trigger->observeProcessedEvents(static function (EventInfo $eventInfo, ?EventDTO $eventDTO, bool $dispatched) use (&$observed): void {
            $observed[] = [$eventInfo->type, $eventDTO, $dispatched];
        });

        $trigger->handleProcessedEvent($this->eventInfo(ConstEventType::XID_EVENT, '240'), null, false);

        self::assertSame([[ConstEventType::XID_EVENT->value, null, false]], $observed);
        self::assertSame('240', $this->safePosition($name));
    }

    public function testProcessedObserverFailureDoesNotAdvanceSafeCursor(): void
    {
        if (! interface_exists('MySQLReplication\Event\EventObserverInterface')) {
            self::assertTrue(true);

            return;
        }

        $name = 'raw-observer-failure';
        $trigger = $this->trigger($name, ['checkpoint_mode' => 'shadow']);
        $trigger->observeProcessedEvents(static function (): void {
            throw new LogicException('telemetry failed');
        });

        try {
            $trigger->handleProcessedEvent($this->eventInfo(ConstEventType::XID_EVENT, '240'), null, false);
            self::fail('The processed-event observer should have failed.');
        } catch (LogicException $exception) {
            self::assertSame('telemetry failed', $exception->getMessage());
        }

        self::assertNull(Cache::get("triggers:{$name}:replication:safe"));
    }

    public function testUnsupportedMutationEventsFailClosedBeforeObserversOrCheckpoints(): void
    {
        if (! interface_exists('MySQLReplication\Event\EventObserverInterface')) {
            self::assertTrue(true);

            return;
        }

        $observed = false;
        $trigger = $this->trigger('unsupported-event', [
            'checkpoint_mode' => 'shadow',
            'reject_unsupported_mutation_events' => true,
        ]);

        $trigger->observeProcessedEvents(static function () use (&$observed): void {
            $observed = true;
        });

        try {
            $trigger->handleProcessedEvent($this->eventInfoForType(40, '300'), null, false);
            self::fail('The unsupported mutation event should have failed.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Unsupported mutation-bearing binlog event', $exception->getMessage());
        }

        self::assertFalse($observed);
        self::assertNull(Cache::get('triggers:unsupported-event:replication:safe'));
    }

    public function testControlPollingUsesOneReadAndHonorsThePositiveInterval(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->expects(self::once())
            ->method('getMultiple')
            ->willReturn([]);
        $trigger = $this->validatingTrigger(['control_poll_interval' => 10]);
        $trigger->useCache($cache);

        $trigger->handleProcessedEvent($this->eventInfo(ConstEventType::QUERY_EVENT, '100'), null, false);
        $trigger->handleProcessedEvent($this->eventInfo(ConstEventType::QUERY_EVENT, '120'), null, false);
    }

    public function testFilteredHeartbeatStillRefreshesLegacyCheckpoint(): void
    {
        $name = 'filtered-heartbeat';
        $trigger = $this->trigger($name);

        $trigger->handleProcessedEvent($this->eventInfo(ConstEventType::HEARTBEAT_LOG_EVENT, '500'), null, false);

        self::assertSame('500', $trigger->getCurrent()?->getBinLogPosition());
    }

    public function testSourceSafetyRequirementsFailClosed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT @@GLOBAL.binlog_row_image')
            ->willReturn('MINIMAL');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('binlog_row_image=FULL');

        $this->validatingTrigger(['require_full_row_image' => true])->validate($connection);
    }

    public function testDisabledSourceSafetyRequirementsPerformNoQueries(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        $this->validatingTrigger([])->validate($connection);
    }

    public function testSafeSourceDefaultsPassEveryEnabledRequirement(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnCallback(static fn (string $query): string => match ($query) {
                'SELECT @@GLOBAL.binlog_row_image' => 'FULL',
                'SELECT @@GLOBAL.binlog_transaction_compression' => 'OFF',
                'SELECT @@GLOBAL.binlog_row_value_options' => '',
            });

        $this->validatingTrigger([
            'require_full_row_image' => true,
            'reject_transaction_compression' => true,
            'reject_partial_json_updates' => true,
        ])->validate($connection);

        self::assertTrue(true);
    }

    public function testTransactionCompressionFailsClosed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT @@GLOBAL.binlog_transaction_compression')
            ->willReturn('ON');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Compressed binlog transactions are not supported');

        $this->validatingTrigger(['reject_transaction_compression' => true])->validate($connection);
    }

    public function testPartialJsonRowValuesFailClosed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT @@GLOBAL.binlog_row_value_options')
            ->willReturn('PARTIAL_JSON');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Partial JSON binlog updates are not supported');

        $this->validatingTrigger(['reject_partial_json_updates' => true])->validate($connection);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function trigger(string $name, array $overrides = []): Trigger
    {
        return new Trigger($name, array_replace($this->baseConfig(), $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function validatingTrigger(array $overrides): TriggerSourceValidator
    {
        return new TriggerSourceValidator('source-validation', array_replace($this->baseConfig(), $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'replication',
            'password' => '',
            'databases' => [],
            'tables' => [],
            'heartbeat' => 3,
            'keepalive' => 0,
            'checkpoint_interval' => 1,
            'checkpoint_ttl' => 86400,
        ];
    }

    private function safePosition(string $name): ?string
    {
        return $this->trigger($name, ['checkpoint_mode' => 'safe'])->configure()->binLogPosition;
    }

    private function eventInfo(ConstEventType $type, string $position): EventInfo
    {
        return $this->eventInfoForType($type->value, $position);
    }

    private function eventInfoForType(int $type, string $position): EventInfo
    {
        $current = new BinLogCurrent();
        $current->setBinFileName('mysql-bin.000001');

        return new EventInfo(0, $type, 1, 19, $position, 0, false, $current);
    }
}

final class TestReplicationFilterProvider extends EventSubscriber implements ReplicationFilterProvider
{
    public function replicationDatabases(): array
    {
        return ['source'];
    }

    public function replicationTables(): array
    {
        return ['products', 'categories'];
    }

    public function replicationEventTypes(): array
    {
        return [
            ConstEventType::WRITE_ROWS_EVENT_V2->value,
            ConstEventType::UPDATE_ROWS_EVENT_V2->value,
            ConstEventType::DELETE_ROWS_EVENT_V2->value,
        ];
    }
}

final class TriggerSourceValidator extends Trigger
{
    public function validate(Connection $connection): void
    {
        $this->validateSourceConfiguration($connection);
    }

    public function useCache(CacheRepository $cache): void
    {
        $this->cache = $cache;
    }
}
