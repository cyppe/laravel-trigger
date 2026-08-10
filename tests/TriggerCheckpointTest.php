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

use Illuminate\Support\Facades\Cache;
use LogicException;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\HeartbeatDTO;
use MySQLReplication\Event\DTO\QueryDTO;
use MySQLReplication\Event\DTO\TableMapDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\XidDTO;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\RowEvent\ColumnDTOCollection;
use MySQLReplication\Event\RowEvent\TableMap;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * @internal
 */
final class TriggerCheckpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function testLegacyModeRemainsTheDefault(): void
    {
        $trigger = $this->trigger('legacy-default', []);

        $trigger->dispatch($this->tableMapEvent('120'));

        self::assertSame('120', $trigger->getCurrent()?->getBinLogPosition());
    }

    public function testShadowModeNeverPromotesATableMapOrRowPositionToTheSafeCursor(): void
    {
        $name = 'forced-mid-transaction-resume';

        $this->trigger($name, ['checkpoint_mode' => 'shadow'])
            ->dispatch($this->xidEvent('4'));

        $this->trigger($name, ['checkpoint_mode' => 'shadow'])
            ->dispatch($this->tableMapEvent('120'));

        self::assertSame('4', $this->safePosition($name));

        $this->trigger($name, ['checkpoint_mode' => 'shadow'])
            ->dispatch($this->updateRowsEvent('220'));

        self::assertSame('4', $this->safePosition($name));

        $this->trigger($name, ['checkpoint_mode' => 'shadow'])
            ->dispatch($this->xidEvent('240'));

        self::assertSame('240', $this->safePosition($name));
    }

    public function testOnlyConfirmedQueryTerminatorsPromoteTheSafeCursor(): void
    {
        $cases = [
            'COMMIT' => true,
            ' commit work and no chain no release; ' => true,
            'ROLLBACK' => true,
            'ROLLBACK WORK AND CHAIN RELEASE' => true,
            'ROLLBACK TO SAVEPOINT before_import' => false,
            'BEGIN' => false,
            'ALTER TABLE products ADD COLUMN example INT' => false,
        ];

        foreach ($cases as $query => $isBoundary) {
            $name = 'query-' . md5($query);

            $this->trigger($name, ['checkpoint_mode' => 'shadow'])
                ->dispatch($this->xidEvent('4'));

            $this->trigger($name, ['checkpoint_mode' => 'shadow'])
                ->dispatch($this->queryEvent('100', $query));

            self::assertSame($isBoundary ? '100' : '4', $this->safePosition($name), $query);
        }
    }

    public function testHeartbeatDoesNotPromoteAnUnprovenPosition(): void
    {
        $name = 'heartbeat-safety';

        $this->trigger($name, ['checkpoint_mode' => 'shadow'])
            ->dispatch($this->xidEvent('4'));

        $this->trigger($name, ['checkpoint_mode' => 'shadow'])
            ->heartbeat(new HeartbeatDTO($this->eventInfo(ConstEventType::HEARTBEAT_LOG_EVENT, '120')));

        self::assertSame('4', $this->safePosition($name));

        $this->trigger($name, ['checkpoint_mode' => 'safe'])
            ->heartbeat(new HeartbeatDTO($this->eventInfo(ConstEventType::HEARTBEAT_LOG_EVENT, '140')));

        self::assertSame('4', $this->safePosition($name));
    }

    public function testCallbackFailureLeavesThePreviousSafeCursorUnchanged(): void
    {
        $name = 'callback-failure';

        $this->trigger($name, ['checkpoint_mode' => 'shadow'])
            ->dispatch($this->xidEvent('4'));

        $trigger = $this->trigger($name, ['checkpoint_mode' => 'shadow']);
        $trigger->on('*', '*', static function (): void {
            throw new RuntimeException('capture failed');
        });

        try {
            $trigger->dispatch($this->updateRowsEvent('220'));
            self::fail('The capture callback should have failed.');
        } catch (RuntimeException $exception) {
            self::assertSame('capture failed', $exception->getMessage());
        }

        self::assertSame('4', $this->safePosition($name));
    }

    public function testSafeModeFailsClosedWithoutASafeCursor(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Safe checkpoint');

        $this->trigger('missing-safe-cursor', ['checkpoint_mode' => 'safe'])
            ->configure();
    }

    public function testSafeCursorCanBeSeededAtATrustedTransactionBoundary(): void
    {
        $current = new BinLogCurrent();
        $current->setBinFileName('mysql-bin.000001');
        $current->setBinLogPosition('4');

        $trigger = $this->trigger('seeded-safe-cursor', ['checkpoint_mode' => 'safe']);
        $trigger->rememberSafeCurrent($current);

        $config = $trigger->configure();

        self::assertSame('mysql-bin.000001', $config->binLogFileName);
        self::assertSame('4', $config->binLogPosition);
    }

    public function testSafeCursorCannotBeSeededInLegacyMode(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('shadow or safe');

        $current = new BinLogCurrent();
        $this->trigger('legacy-safe-seed', [])->rememberSafeCurrent($current);
    }

    public function testZeroIntervalStillStoresEverySafeBoundary(): void
    {
        $name = 'zero-safe-interval';

        $this->trigger($name, [
            'checkpoint_mode' => 'shadow',
            'checkpoint_interval' => 0,
        ])->dispatch($this->xidEvent('240'));

        self::assertSame('240', $this->safePosition($name));
    }

    public function testSafeCursorDoesNotExpireWhileTheSourceIsIdle(): void
    {
        $name = 'non-expiring-safe-cursor';

        $this->trigger($name, [
            'checkpoint_mode' => 'shadow',
            'checkpoint_ttl' => 1,
        ])->dispatch($this->xidEvent('240'));

        $this->travel(2)->seconds();

        self::assertSame('240', $this->safePosition($name));
    }

    public function testLegacySerializedCheckpointRemainsReadable(): void
    {
        $current = new BinLogCurrent();
        $current->setBinFileName('mysql-bin.000001');
        $current->setBinLogPosition('4');

        Cache::forever('triggers:legacy-serialized-checkpoint:replication', serialize($current));

        $restored = $this->trigger('legacy-serialized-checkpoint', [])->getCurrent();

        self::assertInstanceOf(BinLogCurrent::class, $restored);
        self::assertSame('mysql-bin.000001', $restored->getBinFileName());
        self::assertSame('4', $restored->getBinLogPosition());
    }

    public function testUnexpectedCheckpointClassesAreRejectedWithoutWakeupHooks(): void
    {
        $this->assertCheckpointClassIsRejectedWithoutHydration(CheckpointWakeupProbe::class);
    }

    public function testUnexpectedCheckpointClassesAreRejectedWithoutUnserializeHooks(): void
    {
        $this->assertCheckpointClassIsRejectedWithoutHydration(CheckpointUnserializeProbe::class);
    }

    public function testMalformedAndScalarCheckpointPayloadsRemainFailClosed(): void
    {
        foreach (['not-serialized', 'i:4;', 'a:1:{i:0;i:1;}', 'b:1;'] as $payload) {
            $name = 'invalid-' . md5($payload);
            $key = sprintf('triggers:%s:replication', $name);

            Cache::forever($key, $payload);

            self::assertNull($this->trigger($name, [])->getCurrent());
            self::assertFalse(Cache::has($key));
        }
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function trigger(string $name, array $overrides): \Huangdijia\Trigger\Trigger
    {
        return new \Huangdijia\Trigger\Trigger($name, array_replace([
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
        ], $overrides));
    }

    /**
     * @param class-string<CheckpointUnserializeProbe|CheckpointWakeupProbe> $probeClass
     */
    private function assertCheckpointClassIsRejectedWithoutHydration(string $probeClass): void
    {
        $name = 'unexpected-' . md5($probeClass);
        $key = sprintf('triggers:%s:replication', $name);
        $probeClass::$hydrated = false;

        Cache::forever($key, serialize(new $probeClass()));

        self::assertNull($this->trigger($name, [])->getCurrent());
        self::assertFalse($probeClass::$hydrated, $probeClass);
        self::assertFalse(Cache::has($key));
    }

    private function safePosition(string $name): ?string
    {
        $config = $this->trigger($name, ['checkpoint_mode' => 'safe'])->configure();

        self::assertSame('mysql-bin.000001', $config->binLogFileName);

        return $config->binLogPosition;
    }

    private function tableMapEvent(string $position): TableMapDTO
    {
        return new TableMapDTO(
            $this->eventInfo(ConstEventType::TABLE_MAP_EVENT, $position),
            $this->tableMap(),
        );
    }

    private function updateRowsEvent(string $position): UpdateRowsDTO
    {
        return new UpdateRowsDTO(
            $this->eventInfo(ConstEventType::UPDATE_ROWS_EVENT_V2, $position),
            $this->tableMap(),
            1,
            [['before' => ['id' => 1], 'after' => ['id' => 1]]],
        );
    }

    private function xidEvent(string $position): XidDTO
    {
        return new XidDTO(
            $this->eventInfo(ConstEventType::XID_EVENT, $position),
            '1',
        );
    }

    private function queryEvent(string $position, string $query): QueryDTO
    {
        return new QueryDTO(
            $this->eventInfo(ConstEventType::QUERY_EVENT, $position),
            'db',
            0,
            $query,
            1,
        );
    }

    private function eventInfo(ConstEventType $type, string $position): EventInfo
    {
        $current = new BinLogCurrent();
        $current->setBinFileName('mysql-bin.000001');

        return new EventInfo(
            0,
            $type->value,
            1,
            19,
            $position,
            0,
            false,
            $current,
        );
    }

    private function tableMap(): TableMap
    {
        return new TableMap(
            'db',
            'af_products',
            '1',
            0,
            new ColumnDTOCollection(),
        );
    }
}

final class CheckpointWakeupProbe
{
    public static bool $hydrated = false;

    public function __wakeup(): void
    {
        self::$hydrated = true;
    }
}

final class CheckpointUnserializeProbe
{
    public static bool $hydrated = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        self::$hydrated = true;
    }
}
