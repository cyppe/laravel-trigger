<?php

declare(strict_types=1);
/**
 * This file is part of huangdijia/laravel-trigger.
 *
 * @link     https://github.com/huangdijia/laravel-trigger
 * @document https://github.com/huangdijia/laravel-trigger/blob/4.x/README.md
 * @contact  huangdijia@gmail.com
 */

namespace Huangdijia\Trigger\Console;

use Doctrine\DBAL\Exception as DbalException;
use Huangdijia\Trigger\Facades\Trigger;
use Illuminate\Console\Command;
use InvalidArgumentException;
use MySQLReplication\Exception\MySQLReplicationException;
use MySQLReplication\Socket\SocketException;
use PDOException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class StartCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trigger:start {--R|replication=default : replication} {--reset} {--with-secrets : Reveal secret config values (e.g. password) in verbose output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start trigger service.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->listenForSignals();

        $keepUp = $this->option('reset') ? false : true;
        $replication = $this->option('replication');

        if (! is_string($replication)) {
            throw new InvalidArgumentException('The replication option must be a string.');
        }

        $trigger = Trigger::replication($replication);

        start:
        try {
            if ($this->option('verbose')) {
                $triggerConfig = $trigger->getConfig();

                if (! is_array($triggerConfig)) {
                    $triggerConfig = [];
                }

                $showSecrets = (bool) $this->option('with-secrets');

                $this->info('Configure');
                $this->table(
                    ['Name', 'Value'],
                    collect($triggerConfig)
                        ->merge(['bootat' => date('Y-m-d H:i:s')])
                        ->transform(function ($item, $key) use ($showSecrets) {
                            // Redact secret-like values (password/token/secret)
                            // unless explicitly revealed with --with-secrets. A
                            // verbose config dump otherwise leaks credentials into
                            // container/aggregated logs.
                            if (! $showSecrets
                                && is_string($key)
                                && preg_match('/pass|secret|token/i', $key)
                                && filled($item)
                            ) {
                                $item = '******';
                            }

                            if (! is_scalar($item)) {
                                $item = json_encode($item, JSON_THROW_ON_ERROR);
                            }

                            return [
                                OutputFormatter::escape(ucfirst((string) $key)),
                                OutputFormatter::escape((string) $item),
                            ];
                        })
                );

                $binLogCurrent = $trigger->getCurrent();

                if ($keepUp && ! is_null($binLogCurrent)) {
                    $this->info('BinLog');

                    $this->table(
                        ['Name', 'Value'],
                        [
                            ['BinLogPosition', OutputFormatter::escape($binLogCurrent->getBinLogPosition())],
                            ['BinFileName', OutputFormatter::escape($binLogCurrent->getBinFileName())],
                        ]
                    );
                }

                $this->info('Subscribers');
                $this->table(
                    ['Subscriber', 'Registered'],
                    collect($trigger->getSubscribers())
                        ->transform(fn ($subscriber) => [OutputFormatter::escape((string) $subscriber), '√'])
                );
            }

            $trigger->start($keepUp);
        } catch (MySQLReplicationException $e) {
            $this->error(OutputFormatter::escape($e->getMessage()));

            if (! $this->shouldRetryReplication($e)) {
                throw $e;
            }

            // Transient socket failures retry from the last persisted cursor.
            // Parser, protocol, source-configuration, and purged-position errors
            // fail closed and require an explicit operator decision; clearing a
            // cursor here would silently restart at the current source head.
            $this->info('Retry now');
            sleep(1);

            goto start;
        } catch (DbalException|PDOException $e) {
            $this->error(OutputFormatter::escape($e->getMessage()));

            if (! $this->shouldRetry($e)) {
                throw $e;
            }

            // Keep current binlog position so we can resume after reconnect.
            $this->info('Retry now');
            sleep(1);

            goto start;
        }

        return Command::SUCCESS;
    }

    /**
     * Register SIGTERM/SIGINT handlers for immediate shutdown.
     *
     * The start() method blocks inside MySQLReplicationFactory::run() reading
     * from a socket. Setting a flag is insufficient because the blocking read
     * never returns to check it. We must exit directly from the signal handler,
     * matching the pattern used by the library's own Terminate subscriber.
     */
    protected function listenForSignals(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal) {
            $name = $signal === SIGTERM ? 'SIGTERM' : 'SIGINT';
            $this->info("Received {$name}, shutting down...");

            exit(0);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function shouldRetry(Throwable $e): bool
    {
        $pdo = $e instanceof PDOException ? $e : null;

        if ($pdo === null && $e->getPrevious() instanceof PDOException) {
            $pdo = $e->getPrevious();
        }

        if ($pdo !== null && isset($pdo->errorInfo[1])) {
            $driverCode = (int) $pdo->errorInfo[1];

            return in_array($driverCode, [2006, 2013, 2055, 4031], true);
        }

        $message = strtolower($e->getMessage());

        if (str_contains($message, 'access denied') || str_contains($message, 'unknown database')) {
            return false;
        }

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'disconnected by the server')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'connection timed out')
            || str_contains($message, 'broken pipe');
    }

    private function shouldRetryReplication(MySQLReplicationException $exception): bool
    {
        return $exception instanceof SocketException;
    }
}
