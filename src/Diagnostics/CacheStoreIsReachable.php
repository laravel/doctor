<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Facades\Cache;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Configured;
use Throwable;

class CacheStoreIsReachable extends Diagnostic
{
    public string $name = 'Cache connects';

    public string $group = 'cache';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default cache store configured.',
            'unreachable' => Message::make(
                summary: 'The application cannot reach the default cache store.',
                remediation: 'Check CACHE_STORE and the backing cache service configuration.',
            ),
            'reachable' => 'The application can reach the default cache store.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $store = Configured::string('cache.default');

        if ($store === null) {
            return $this->skip('not-configured');
        }

        try {
            $this->probe($store);
        } catch (Throwable $e) {
            return $this->fail('unreachable')
                ->withDetails($e->getMessage());
        }

        return $this->pass('reachable');
    }

    /**
     * Probe a cache store with a short-lived key.
     */
    private function probe(string $store): void
    {
        $key = $this->key();

        Cache::store($store)->put($key, true, 10);
        Cache::store($store)->get($key);
        Cache::store($store)->forget($key);
    }

    /**
     * Get a temporary cache key.
     */
    private function key(): string
    {
        return 'laravel-doctor:'.str_replace('.', '', uniqid('', true));
    }
}
