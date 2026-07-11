<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Facades\Redis;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Configured;
use Laravel\Doctor\Support\Details;
use Throwable;

class RedisConnectionsAreReachable extends Diagnostic
{
    public string $name = 'Redis connects';

    public string $group = 'cache';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application is not using Redis-backed cache, queue, or session storage.',
            'unreachable' => Message::make(
                summary: 'The application cannot reach every active Redis connection.',
                remediation: 'Check Redis host, port, credentials, and client configuration.',
            ),
            'reachable' => 'The application can reach every active Redis connection.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $connections = $this->connections();

        if ($connections === []) {
            return $this->skip('not-configured');
        }

        $failures = [];

        foreach ($connections as $connection) {
            try {
                Redis::connection($connection)->ping();
            } catch (Throwable $e) {
                $failures[$connection] = $e->getMessage();
            }
        }

        if ($failures !== []) {
            return $this->fail('unreachable')
                ->withDetails(Details::failures($failures));
        }

        return $this->pass('reachable');
    }

    /**
     * Get Redis connection names used by selected Laravel services.
     *
     * @return list<string>
     */
    private function connections(): array
    {
        return array_values(array_unique(array_filter([
            $this->cacheConnection(),
            $this->queueConnection(),
            $this->sessionConnection(),
        ], is_string(...))));
    }

    /**
     * Get the Redis connection used by the default cache store.
     */
    private function cacheConnection(): ?string
    {
        $store = Configured::string('cache.default');

        if ($store === null) {
            return null;
        }

        $configuration = config("cache.stores.{$store}");

        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'redis') {
            return null;
        }

        return $this->configuredConnection($configuration['connection'] ?? null);
    }

    /**
     * Get the Redis connection used by the default queue connection.
     */
    private function queueConnection(): ?string
    {
        $connection = Configured::string('queue.default');

        if ($connection === null) {
            return null;
        }

        $configuration = config("queue.connections.{$connection}");

        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'redis') {
            return null;
        }

        return $this->configuredConnection($configuration['connection'] ?? null);
    }

    /**
     * Get the Redis connection used by the configured session driver.
     */
    private function sessionConnection(): ?string
    {
        if (config('session.driver') !== 'redis') {
            return null;
        }

        return Configured::string('session.connection', 'default');
    }

    /**
     * Normalize a Redis connection name from configuration.
     */
    private function configuredConnection(mixed $connection): string
    {
        return is_string($connection) && $connection !== '' ? $connection : 'default';
    }
}
