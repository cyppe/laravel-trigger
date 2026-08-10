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

use Closure;
use Doctrine\DBAL\Connection;
use Exception;
use Huangdijia\Trigger\Contracts\ReplicationFilterProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\DTO\HeartbeatDTO;
use MySQLReplication\Event\DTO\QueryDTO;
use MySQLReplication\Event\DTO\RowsDTO;
use MySQLReplication\Event\DTO\XidDTO;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\EventObserverInterface;
use MySQLReplication\MySQLReplicationFactory;
use ReflectionException;
use ReflectionMethod;
use Throwable;

class Trigger
{
    private const CHECKPOINT_MODE_LEGACY = 'legacy';

    private const CHECKPOINT_MODE_SAFE = 'safe';

    private const CHECKPOINT_MODE_SHADOW = 'shadow';

    private const UNSUPPORTED_MUTATION_EVENT_TYPES = [39, 40];

    protected \Illuminate\Contracts\Cache\Repository $cache;

    protected ?Connection $dbConnection = null;

    protected int $lastKeepalivePingAt = 0;

    protected int $lastCheckpointAt = 0;

    protected int $lastSafeCheckpointAt = 0;

    protected int $lastControlPollAt = 0;

    /**
     * @var array<int, callable(EventInfo, ?EventDTO, bool): void>
     */
    protected array $processedEventObservers = [];

    /**
     * @var array<string, array<string, array<string, array<int, mixed>>>>
     */
    protected array $events = [];

    protected int $bootTime;

    protected string $checkpointMode;

    protected string $replicationCacheKey;

    protected string $safeReplicationCacheKey;

    protected string $resetCacheKey;

    protected string $restartCacheKey;

    /**
     * @var array<int, class-string<EventSubscriber>>
     */
    protected array $defaultSubscribers = [
        Subscribers\Trigger::class,
        Subscribers\Terminate::class,
        Subscribers\Heartbeat::class,
    ];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected string $name = 'default', protected array $config = [])
    {
        $this->bootTime = time();

        $this->resetCacheKey = sprintf('triggers:%s:reset', $name);
        $this->restartCacheKey = sprintf('triggers:%s:restart', $name);
        $this->replicationCacheKey = sprintf('triggers:%s:replication', $name);
        $this->safeReplicationCacheKey = sprintf('triggers:%s:replication:safe', $name);
        $this->checkpointMode = $this->resolveCheckpointMode();

        $this->cache = Cache::store();
    }

    /**
     * Auto detect databases and tables.
     */
    public function detectDatabasesAndTables(): void
    {
        $databases = $this->getDatabases();
        $tables = $this->getTables();
        $eventTypes = $this->getRouteEventTypes();

        foreach ($this->getReplicationFilterProviders() as $provider) {
            $databases = [...$databases, ...$provider->replicationDatabases()];
            $tables = [...$tables, ...$provider->replicationTables()];
            $eventTypes = [...$eventTypes, ...$provider->replicationEventTypes()];
        }

        $this->config['databases'] = $this->normalizeStringList($databases);
        $this->config['tables'] = $this->normalizeStringList($tables);

        if ($eventTypes !== [] && ! $this->hasWildcardEventRoute()) {
            $this->config['events_only'] = $this->normalizeIntegerList($eventTypes);
        }
    }

    /**
     * Get config.
     *
     * @param mixed $default
     *
     * @return array|mixed
     */
    public function getConfig(string $key = '', $default = null)
    {
        if ($key) {
            return $this->config[$key] ?? $default;
        }

        return $this->config;
    }

    /**
     * Get subscribers.
     *
     * @return array<int, mixed>
     */
    public function getSubscribers(): array
    {
        return array_merge(
            $this->getConfig('subscribers') ?: [],
            $this->defaultSubscribers
        );
    }

    /**
     * Builder config.
     */
    public function configure(bool $keepUp = true): \MySQLReplication\Config\Config
    {
        return tap(new ConfigBuilder(), function (ConfigBuilder $builder) use ($keepUp) {
            $builder->withSlaveId(time())
                ->withHost($this->getConfig('host'))
                ->withPort($this->getConfig('port'))
                ->withUser($this->getConfig('user'))
                ->withPassword($this->getConfig('password'))
                ->withDatabasesOnly($this->stringListConfig('databases'))
                ->withTablesOnly($this->stringListConfig('tables'))
                ->withEventsOnly($this->integerListConfig('events_only'))
                ->withEventsIgnore($this->integerListConfig('events_ignore'))
                ->withHeartbeatPeriod($this->getConfig('heartbeat') ?: 3);

            // @phpstan-ignore function.alreadyNarrowedType (compatibility with php-mysql-replication 8.0/8.1)
            if (method_exists($builder, 'withDatabasesRegex')) {
                $builder->withDatabasesRegex($this->stringListConfig('databases_regex'));
            }

            // @phpstan-ignore function.alreadyNarrowedType (compatibility with php-mysql-replication 8.0/8.1)
            if (method_exists($builder, 'withTablesRegex')) {
                $builder->withTablesRegex($this->stringListConfig('tables_regex'));
            }

            // @phpstan-ignore function.alreadyNarrowedType (compatibility with php-mysql-replication < 11)
            if (method_exists($builder, 'withDatabasesIgnore')) {
                $builder->withDatabasesIgnore($this->stringListConfig('databases_ignore'));
            }

            // @phpstan-ignore function.alreadyNarrowedType (compatibility with php-mysql-replication < 11)
            if (method_exists($builder, 'withTablesIgnore')) {
                $builder->withTablesIgnore($this->stringListConfig('tables_ignore'));
            }

            // @phpstan-ignore function.alreadyNarrowedType (compatibility with php-mysql-replication < 11)
            if (method_exists($builder, 'withUseTableMapMetadata')) {
                $builder->withUseTableMapMetadata((bool) $this->getConfig('use_table_map_metadata', false));
            }

            if ($this->checkpointMode !== self::CHECKPOINT_MODE_LEGACY && ! interface_exists(EventObserverInterface::class)) {
                $eventsOnly = $this->integerListConfig('events_only');
                $xidFiltered = in_array(ConstEventType::XID_EVENT->value, $this->integerListConfig('events_ignore'), true)
                    || ($eventsOnly !== [] && ! in_array(ConstEventType::XID_EVENT->value, $eventsOnly, true));

                if ($xidFiltered) {
                    throw new LogicException('Filtering XID events in shadow or safe checkpoint mode requires php-mysql-replication event observer support');
                }
            }

            if ($keepUp) {
                $binLogCurrent = $this->getCurrent();

                if ($binLogCurrent === null && $this->checkpointMode === self::CHECKPOINT_MODE_SAFE) {
                    throw new LogicException(sprintf(
                        "Safe checkpoint for replication '%s' is missing; run in shadow mode first or explicitly reset the listener",
                        $this->name,
                    ));
                }

                if ($binLogCurrent !== null) {
                    $builder->withBinLogFileName($binLogCurrent->getBinFileName())
                        ->withBinLogPosition($binLogCurrent->getBinLogPosition());
                }
            }
        })->build();
    }

    /**
     * Load routes of trigger.
     */
    public function loadRoutes(): void
    {
        $routeFile = $this->config['route'] ?? '';

        if (! $routeFile || ! is_file($routeFile)) {
            return;
        }

        $trigger = $this;

        require $routeFile;
    }

    /**
     * Start.
     */
    public function start(bool $keepUp = true): void
    {
        $config = $this->configure($keepUp);
        $binLogStream = interface_exists(EventObserverInterface::class)
            ? new MySQLReplicationFactory($config, eventObserver: new ProcessedEventObserver($this))
            : new MySQLReplicationFactory($config);

        $this->dbConnection = $binLogStream->getDbConnection();
        $this->applySessionVariables($this->dbConnection);
        $this->validateSourceConfiguration($this->dbConnection);

        collect($this->getSubscribers())
            ->reject(fn ($subscriber) => ! is_subclass_of($subscriber, EventSubscriber::class))
            ->unique()
            ->each(function (mixed $subscriber) use ($binLogStream): void {
                if (! is_string($subscriber) || ! is_subclass_of($subscriber, EventSubscriber::class)) {
                    return;
                }

                $binLogStream->registerSubscriber(new $subscriber($this));
            });

        $binLogStream->run();
    }

    /**
     * Reset.
     */
    public function reset(): void
    {
        $this->cache->forever($this->resetCacheKey, time());
    }

    /**
     * IsReseted.
     */
    public function isReseted(): bool
    {
        return $this->cache->get($this->resetCacheKey, 0) > $this->bootTime;
    }

    /**
     * Terminate.
     */
    public function terminate(): void
    {
        $this->cache->forever($this->restartCacheKey, time());
    }

    /**
     * Is terminated.
     */
    public function isTerminated(): bool
    {
        return $this->cache->get($this->restartCacheKey, 0) > $this->bootTime;
    }

    /**
     * Remember current by heartbeat.
     */
    public function heartbeat(EventDTO $event): void
    {
        if ($this->checkpointMode !== self::CHECKPOINT_MODE_SAFE) {
            $this->rememberCurrent($event->getEventInfo()->binLogCurrent);
        }

        $this->keepalive();
    }

    /**
     * Register a post-consume observer that also receives filtered events.
     *
     * Observers run synchronously after application subscribers complete. An
     * observer that throws prevents a semi-sync acknowledgement and stops the
     * stream, so telemetry observers should handle their own transient errors.
     *
     * @param callable(EventInfo, ?EventDTO, bool): void $observer
     */
    public function observeProcessedEvents(callable $observer): void
    {
        if (! interface_exists(EventObserverInterface::class)) {
            throw new LogicException('Processed-event observers require php-mysql-replication event observer support');
        }

        $this->processedEventObservers[] = $observer;
    }

    /**
     * Handle an event reported by php-mysql-replication after successful
     * parsing and synchronous subscriber dispatch.
     */
    public function handleProcessedEvent(EventInfo $eventInfo, ?EventDTO $eventDTO, bool $dispatched): void
    {
        if (
            (bool) $this->getConfig('reject_unsupported_mutation_events', false)
            && in_array($eventInfo->type, self::UNSUPPORTED_MUTATION_EVENT_TYPES, true)
        ) {
            throw new LogicException(sprintf(
                'Unsupported mutation-bearing binlog event type %d encountered; refusing to advance the listener',
                $eventInfo->type,
            ));
        }

        $this->keepalive();

        if ($eventInfo->type === ConstEventType::HEARTBEAT_LOG_EVENT->value && ! $dispatched) {
            $this->heartbeat(new HeartbeatDTO($eventInfo));
        }

        if ((int) $this->getConfig('control_poll_interval', 0) > 0) {
            $this->pollControlSignals();
        }

        foreach ($this->processedEventObservers as $observer) {
            $observer($eventInfo, $eventDTO, $dispatched);
        }

        if ($eventInfo->type === ConstEventType::XID_EVENT->value) {
            $this->checkpointSafePosition($eventInfo->binLogCurrent);
        }
    }

    /**
     * Poll reset and termination controls with one cache round trip.
     */
    public function pollControlSignals(): void
    {
        $interval = (int) $this->getConfig('control_poll_interval', 0);
        $now = time();

        if ($interval > 0 && $this->lastControlPollAt > 0 && ($now - $this->lastControlPollAt) < $interval) {
            return;
        }

        $this->lastControlPollAt = $now;
        $signals = [];

        foreach ($this->cache->getMultiple([$this->resetCacheKey, $this->restartCacheKey]) as $key => $value) {
            $signals[$key] = $value;
        }

        if (($signals[$this->resetCacheKey] ?? 0) > $this->bootTime) {
            $this->clearCurrent();
        }

        if (($signals[$this->restartCacheKey] ?? 0) > $this->bootTime) {
            exit('Terminated');
        }
    }

    /**
     * Remember current.
     */
    public function rememberCurrent(BinLogCurrent $binLogCurrent): void
    {
        // Resuming from a stale position is strictly better than silently
        // starting at the stream head: a purged position is rejected on
        // connect and trigger:start fails closed for an explicit operator
        // decision instead of clearing the cursor automatically.
        $this->storeCurrent($this->replicationCacheKey, $binLogCurrent);
    }

    /**
     * Seed a trusted transaction-boundary cursor for shadow/safe rollout.
     */
    public function rememberSafeCurrent(BinLogCurrent $binLogCurrent): void
    {
        if ($this->checkpointMode === self::CHECKPOINT_MODE_LEGACY) {
            throw new LogicException('A safe checkpoint can only be seeded in shadow or safe mode');
        }

        $this->storeCurrent($this->safeReplicationCacheKey, $binLogCurrent, forever: true);
    }

    /**
     * Get current.
     */
    public function getCurrent(): ?BinLogCurrent
    {
        $cacheKey = $this->checkpointMode === self::CHECKPOINT_MODE_SAFE
            ? $this->safeReplicationCacheKey
            : $this->replicationCacheKey;

        if (! $cache = $this->cache->get($cacheKey)) {
            return null;
        }

        try {
            $current = @unserialize($cache, ['allowed_classes' => [BinLogCurrent::class]]);
        } catch (Throwable) {
            $current = false;
        }

        if (! $current instanceof BinLogCurrent) {
            $this->clearCurrent();

            return null;
        }

        return $current;
    }

    /**
     * Clear current.
     */
    public function clearCurrent(): void
    {
        $this->cache->forget($this->replicationCacheKey);
        $this->cache->forget($this->safeReplicationCacheKey);
    }

    /**
     * Bind events.
     *
     * @param array<string, mixed>|string $eventType
     * @param null|array<int|string, mixed>|callable|Closure|string $action
     */
    public function on(string $table, array|string $eventType, null|array|callable|Closure|string $action = null): void
    {
        // table as db.tb1,db.tb2,...
        if (str_contains($table, ',')) {
            collect(explode(',', $table))->transform(fn ($table) => trim($table))
                ->filter()
                ->each(fn ($table) => $this->on($table, $eventType, $action));
            return;
        }

        // * to *.*
        if ($table == '*') {
            $table .= '.*';
        }

        // default database
        $table = ltrim($table, '.');
        if (! str_contains($table, '.')) { // table to database.table
            $table = sprintf('%s.%s', $this->config['databases'][0] ?? '*', $table);
        } elseif (substr($table, -1) == '.') { // database. to database.*
            $table .= '*';
        }

        // eventType as array
        if (is_array($eventType)) {
            collect($eventType)->each(fn ($action, $eventType) => $this->on($table, $eventType, $action));
            return;
        }

        // to lower
        $eventType = strtolower($eventType);

        // eventType as write,update,delete...
        if (str_contains($eventType, ',')) {
            collect(explode(',', $eventType))
                ->transform(fn ($eventType) => trim($eventType))
                ->filter()
                ->each(fn ($eventType) => $this->on($table, $eventType, $action));
            return;
        }

        $key = sprintf('%s.%s', $table, $eventType);

        // append to actions
        $actions = Arr::get($this->events, $key) ?: [];
        $actions[] = $action;

        // restore to array
        Arr::set($this->events, $key, $actions);
    }

    /**
     * Fire events.
     */
    public function dispatch(EventDTO $event): void
    {
        // MySQL only emits heartbeat events while the whole binlog is silent,
        // so on a busy stream (e.g. one bulk UPDATE rewriting many rows for
        // longer than wait_timeout) the heartbeat-driven keepalive never runs
        // and the metadata connection is closed server-side (error 4031); the
        // next schema lookup then kills the daemon mid-transaction and the
        // whole transaction replays from the checkpoint. Pinging on the
        // dispatch path keeps the connection alive under load; the ping is
        // throttled internally to once per keepalive period.
        $this->keepalive();

        $events = [];
        $eventType = $event->getType();

        if ($event instanceof RowsDTO) {
            $database = $event->tableMap->database;
            $table = $event->tableMap->table;
            $events[] = sprintf('%s.%s.%s', $database, $table, $eventType);
            $events[] = sprintf('%s.%s.%s', $database, $table, '*');
            $events[] = sprintf('%s.%s.%s', $database, '*', $eventType);
        }

        $events[] = "*.*.{$eventType}";
        $events[] = '*.*.*';

        $this->fire($events, $event);

        // Checkpoint AFTER the event has been fully processed so a restart
        // replays (at-least-once) instead of skipping (at-most-once).
        $this->checkpoint($event);
    }

    /**
     * Remember the stream position from the dispatch path, throttled.
     *
     * MySQL only emits heartbeat events while the whole binlog is silent, so
     * on a busy stream the heartbeat-driven rememberCurrent() never runs: the
     * checkpoint freezes at the position where the load began and eventually
     * expires. A process restart during a long catch-up then resumes from the
     * stream head, silently skipping everything between the last processed
     * event and now. Advancing the checkpoint here keeps the resume position
     * honest under load; the cache write is throttled to once per
     * checkpoint_interval seconds. Set checkpoint_interval to 0 to restore
     * the previous heartbeat-only behavior.
     */
    public function checkpoint(EventDTO $event): void
    {
        $interval = (int) $this->getConfig('checkpoint_interval', 5);

        if ($interval <= 0) {
            if ($this->checkpointMode !== self::CHECKPOINT_MODE_LEGACY) {
                $this->checkpointSafeCurrent($event, $interval);
            }

            return;
        }

        if ($this->checkpointMode !== self::CHECKPOINT_MODE_SAFE) {
            $this->checkpointLegacyCurrent($event, $interval);
        }

        if ($this->checkpointMode !== self::CHECKPOINT_MODE_LEGACY) {
            $this->checkpointSafeCurrent($event, $interval);
        }
    }

    /**
     * Fire events.
     *
     * @param array<int, string> $events
     */
    public function fire($events, ?EventDTO $event = null): void
    {
        collect($events)->each(function ($e) use ($event) {
            /** @var array<int, mixed> $actions */
            $actions = Arr::get($this->events, $e, []);

            collect($actions)->each(fn ($action) => $this->call(...$this->parseAction($action, $event)));
        });
    }

    /**
     * Get all events.
     *
     * @return array<string, array<string, array<string, array<int, mixed>>>>
     */
    public function getEvents(): array
    {
        return $this->events ?: [];
    }

    /**
     * Get all databases.
     *
     * @return array<int, string>
     */
    public function getDatabases(): array
    {
        $databases = array_keys($this->getEvents());
        $databases = array_filter($databases, fn ($item) => $item != '*');

        return array_values($databases);
    }

    /**
     * Get all tables.
     *
     * @return array<int, string>
     */
    public function getTables(): array
    {
        $tables = [];

        collect($this->getEvents())->each(function ($listeners, $database) use (&$tables) {
            if (! empty($listeners)) {
                $tables = [...$tables, ...array_filter(array_keys($listeners), fn ($item) => $item != '*')];
            }
        });

        return $tables;
    }

    protected function validateSourceConfiguration(?Connection $connection): void
    {
        if ($connection === null) {
            return;
        }

        if ((bool) $this->getConfig('require_full_row_image', false)) {
            $rowImage = strtoupper((string) $connection->fetchOne('SELECT @@GLOBAL.binlog_row_image'));

            if ($rowImage !== 'FULL') {
                throw new LogicException("The replication source must use binlog_row_image=FULL; got '{$rowImage}'");
            }
        }

        if ((bool) $this->getConfig('reject_transaction_compression', false)) {
            $compression = strtoupper((string) $connection->fetchOne('SELECT @@GLOBAL.binlog_transaction_compression'));

            if (! in_array($compression, ['OFF', '0'], true)) {
                throw new LogicException("Compressed binlog transactions are not supported; got '{$compression}'");
            }
        }

        if ((bool) $this->getConfig('reject_partial_json_updates', false)) {
            $rowValueOptions = trim((string) $connection->fetchOne('SELECT @@GLOBAL.binlog_row_value_options'));

            if ($rowValueOptions !== '') {
                throw new LogicException("Partial JSON binlog updates are not supported; got '{$rowValueOptions}'");
            }
        }
    }

    /**
     * @return array<int, ReplicationFilterProvider>
     */
    private function getReplicationFilterProviders(): array
    {
        $providers = [];

        foreach ($this->getConfig('subscribers', []) ?: [] as $subscriber) {
            if (! is_string($subscriber) || ! is_subclass_of($subscriber, ReplicationFilterProvider::class)) {
                continue;
            }

            $provider = new $subscriber($this);
            $providers[] = $provider;
        }

        return $providers;
    }

    /**
     * @return array<int, int>
     */
    private function getRouteEventTypes(): array
    {
        $types = [];

        foreach ($this->getEvents() as $tables) {
            foreach ($tables as $events) {
                foreach (array_keys($events) as $eventName) {
                    $types = [...$types, ...$this->eventTypesForName($eventName)];
                }
            }
        }

        return $this->normalizeIntegerList($types);
    }

    private function hasWildcardEventRoute(): bool
    {
        foreach ($this->getEvents() as $tables) {
            foreach ($tables as $events) {
                if (array_key_exists('*', $events)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function eventTypesForName(string $eventName): array
    {
        return match (strtolower($eventName)) {
            'write' => [ConstEventType::WRITE_ROWS_EVENT_V1->value, ConstEventType::WRITE_ROWS_EVENT_V2->value],
            'update' => [ConstEventType::UPDATE_ROWS_EVENT_V1->value, ConstEventType::UPDATE_ROWS_EVENT_V2->value],
            'delete' => [ConstEventType::DELETE_ROWS_EVENT_V1->value, ConstEventType::DELETE_ROWS_EVENT_V2->value],
            'query' => [ConstEventType::QUERY_EVENT->value],
            'xid' => [ConstEventType::XID_EVENT->value],
            'rows_query' => [ConstEventType::ROWS_QUERY_LOG_EVENT->value],
            'heartbeat' => [ConstEventType::HEARTBEAT_LOG_EVENT->value],
            'tablemap' => [ConstEventType::TABLE_MAP_EVENT->value],
            'rotate' => [ConstEventType::ROTATE_EVENT->value],
            'gtid' => [ConstEventType::GTID_LOG_EVENT->value],
            'mariadb gtid' => [ConstEventType::MARIA_GTID_EVENT->value],
            'format description' => [ConstEventType::FORMAT_DESCRIPTION_EVENT->value],
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function stringListConfig(string $key): array
    {
        $value = $this->getConfig($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return is_array($value) ? $this->normalizeStringList($value) : [];
    }

    /**
     * @return array<int, int>
     */
    private function integerListConfig(string $key): array
    {
        $value = $this->getConfig($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return is_array($value) ? $this->normalizeIntegerList($value) : [];
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int, string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int, int>
     */
    private function normalizeIntegerList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
                $normalized[] = (int) $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function checkpointLegacyCurrent(EventDTO $event, int $interval): void
    {
        $now = time();

        if ($this->lastCheckpointAt > 0 && ($now - $this->lastCheckpointAt) < $interval) {
            return;
        }

        $this->lastCheckpointAt = $now;

        $this->rememberCurrent($event->getEventInfo()->binLogCurrent);
    }

    private function checkpointSafeCurrent(EventDTO $event, int $interval): void
    {
        if (! $this->isSafeCheckpointBoundary($event)) {
            return;
        }

        $this->checkpointSafePosition($event->getEventInfo()->binLogCurrent, $interval);
    }

    private function checkpointSafePosition(BinLogCurrent $binLogCurrent, ?int $interval = null): void
    {
        if ($this->checkpointMode === self::CHECKPOINT_MODE_LEGACY) {
            return;
        }

        $interval ??= (int) $this->getConfig('checkpoint_interval', 5);

        $now = time();

        if ($interval > 0 && $this->lastSafeCheckpointAt > 0 && ($now - $this->lastSafeCheckpointAt) < $interval) {
            return;
        }

        $this->lastSafeCheckpointAt = $now;

        $this->rememberSafeCurrent($binLogCurrent);
    }

    private function isSafeCheckpointBoundary(EventDTO $event): bool
    {
        if ($event instanceof XidDTO) {
            return true;
        }

        if (! $event instanceof QueryDTO) {
            return false;
        }

        $query = rtrim(trim($event->query), "; \t\n\r\0\x0B");

        return preg_match(
            '/^(?:COMMIT|ROLLBACK)(?:\s+WORK)?(?:\s+AND\s+(?:NO\s+)?CHAIN)?(?:\s+(?:NO\s+)?RELEASE)?$/i',
            $query,
        ) === 1;
    }

    private function resolveCheckpointMode(): string
    {
        $mode = strtolower(trim((string) $this->getConfig('checkpoint_mode', self::CHECKPOINT_MODE_LEGACY)));

        if (! in_array($mode, [
            self::CHECKPOINT_MODE_LEGACY,
            self::CHECKPOINT_MODE_SHADOW,
            self::CHECKPOINT_MODE_SAFE,
        ], true)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid checkpoint mode '%s'; expected legacy, shadow, or safe",
                $mode,
            ));
        }

        return $mode;
    }

    private function storeCurrent(string $cacheKey, BinLogCurrent $binLogCurrent, bool $forever = false): void
    {
        if ($forever) {
            $this->cache->forever($cacheKey, serialize($binLogCurrent));

            return;
        }

        $this->cache->put(
            $cacheKey,
            serialize($binLogCurrent),
            Carbon::now()->addSeconds((int) $this->getConfig('checkpoint_ttl', 86400)),
        );
    }

    private function applySessionVariables(?Connection $connection): void
    {
        if ($connection === null) {
            return;
        }

        $variables = $this->getConfig('session_variables', []);

        if (is_string($variables)) {
            $variables = array_filter(array_map('trim', explode(',', $variables)));
        }

        if (! is_array($variables) || $variables === []) {
            return;
        }

        foreach ($variables as $name => $value) {
            if (is_int($name)) {
                $pair = trim((string) $value);

                if ($pair === '' || ! str_contains($pair, '=')) {
                    continue;
                }

                [$name, $value] = array_map('trim', explode('=', $pair, 2));
            }

            if ($name === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                continue;
            }

            if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                $value = (int) $value;
            }

            try {
                $connection->executeStatement("SET SESSION {$name} = ?", [$value]);
            } catch (Throwable) {
                // Ignore session variable failures (permission/unsupported variables).
            }
        }
    }

    private function keepalive(): void
    {
        $period = (int) $this->getConfig('keepalive', 0);

        if ($period <= 0 || $this->dbConnection === null) {
            return;
        }

        $now = time();

        if ($this->lastKeepalivePingAt > 0 && ($now - $this->lastKeepalivePingAt) < $period) {
            return;
        }

        $this->lastKeepalivePingAt = $now;

        try {
            $this->dbConnection->executeQuery($this->dbConnection->getDatabasePlatform()->getDummySelectSQL());
        } catch (Throwable) {
            try {
                $this->dbConnection->close();

                // DBAL will reconnect automatically on the next query.
                $this->dbConnection->executeQuery($this->dbConnection->getDatabasePlatform()->getDummySelectSQL());

                $this->applySessionVariables($this->dbConnection);
            } catch (Throwable) {
                // If reconnect fails, the next metadata query will throw and the daemon can restart.
            }
        }
    }

    /**
     * Parse action.
     *
     * @param mixed $action
     * @param mixed $event
     * @return array{callable, array<int, mixed>} [callable $callback, array $parameters]
     */
    private function parseAction($action, $event): array
    {
        // callable
        if (is_callable($action)) {
            return [$action, [$event]];
        }

        // parse class from action
        $action = explode('@', $action);
        $class = $action[0];

        // class is not exists
        if (! class_exists($class)) {
            throw new Exception("class '{$class}' is not exists", 1);
        }

        // action as job
        if (is_subclass_of($class, ShouldQueue::class)) {
            $method = $action[1] ?? '';
            $method = in_array($method, ['dispatch', 'dispatch_now']) ? $method : 'dispatch';

            if (! is_callable($method)) {
                throw new Exception("function '{$method}' is not callable", 1);
            }

            return [Closure::fromCallable($method), [new $class($event)]];
        }

        // action as common callable
        $method = $action[1] ?? 'handle';

        // check is method callable
        if (! is_callable([$class, $method])) {
            throw new Exception("{$class}::{$method}() is not callable or not exists", 1);
        }

        $reflectionMethod = new ReflectionMethod($class, $method);

        if (! $reflectionMethod->isPublic()) {
            throw new ReflectionException("{$class}::{$method}() is not public", 1);
        }

        // static method
        if ($reflectionMethod->isStatic()) {
            /** @var callable $callback */
            $callback = [$class, $method];

            return [
                $callback,
                [$event],
            ];
        }

        /** @var callable $callback */
        $callback = [Container::getInstance()->make($class), $method];

        return [
            $callback,
            [$event],
        ];
    }

    /**
     * Execute action.
     *
     * @param array<int, mixed> $parameters
     */
    private function call(callable $action, array $parameters = []): mixed
    {
        return call_user_func_array($action, $parameters);
    }
}
