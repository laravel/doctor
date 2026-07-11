<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Configured;
use RuntimeException;
use Throwable;

class QueueConnectionIsReachable extends Diagnostic
{
    public string $name = 'Queue connects';

    public string $group = 'queue';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default queue connection configured.',
            'unreachable' => Message::make(
                summary: 'The application cannot reach the default queue connection.',
                remediation: 'Check QUEUE_CONNECTION and the backing queue service configuration.',
            ),
            'reachable' => 'The application can reach the default queue connection.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $connection = Configured::string('queue.default');

        if ($connection === null) {
            return $this->skip('not-configured');
        }

        try {
            $this->probe($connection, $this->configuration($connection));
        } catch (Throwable $e) {
            return $this->fail('unreachable')
                ->withDetails($e->getMessage());
        }

        return $this->pass('reachable');
    }

    /**
     * Probe the configured queue connection.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function probe(string $connection, array $configuration): void
    {
        match ($configuration['driver'] ?? null) {
            'sync' => null,
            'database' => $this->probeDatabaseQueue($configuration),
            'redis' => $this->probeRedisQueue($configuration),
            default => Queue::connection($connection),
        };
    }

    /**
     * Probe a database-backed queue.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function probeDatabaseQueue(array $configuration): void
    {
        $table = $configuration['table'] ?? 'jobs';

        if (! is_string($table) || ! $this->schema($configuration)->hasTable($table)) {
            throw new RuntimeException(sprintf('The [%s] queue table does not exist.', is_string($table) ? $table : 'jobs'));
        }
    }

    /**
     * Probe a Redis-backed queue.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function probeRedisQueue(array $configuration): void
    {
        $connection = $configuration['connection'] ?? 'default';

        Redis::connection(is_string($connection) && $connection !== '' ? $connection : 'default')->ping();
    }

    /**
     * Get the queue connection configuration.
     *
     * @return array<string, mixed>
     */
    private function configuration(string $connection): array
    {
        $configuration = config("queue.connections.{$connection}");

        if (! is_array($configuration)) {
            throw new RuntimeException(sprintf('The [%s] queue connection is not configured.', $connection));
        }

        return $configuration;
    }

    /**
     * Get the schema builder for the queue connection.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function schema(array $configuration): Builder
    {
        $connection = $configuration['connection'] ?? null;

        return Schema::connection(is_string($connection) && $connection !== '' ? $connection : null);
    }
}
