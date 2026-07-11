<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Database\Schema\Builder;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Configured;
use RuntimeException;
use Throwable;

class SessionDriverIsReachable extends Diagnostic
{
    public string $name = 'Session connects';

    public string $group = 'session';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default session driver configured.',
            'unreachable' => Message::make(
                summary: 'The application cannot reach the default session driver.',
                remediation: 'Check SESSION_DRIVER and the backing session store configuration.',
            ),
            'reachable' => 'The application can reach the default session driver.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $driver = Configured::string('session.driver');

        if ($driver === null) {
            return $this->skip('not-configured');
        }

        try {
            $this->probe($driver);
        } catch (Throwable $e) {
            return $this->fail('unreachable')
                ->withDetails($e->getMessage());
        }

        return $this->pass('reachable');
    }

    /**
     * Probe the session driver.
     */
    private function probe(string $driver): void
    {
        match ($driver) {
            'array', 'cookie' => null,
            'file' => $this->probeFileSessions(),
            'database' => $this->probeDatabaseSessions(),
            'redis' => $this->probeRedisSessions(),
            default => $this->probeSessionManager(),
        };
    }

    /**
     * Probe file-backed sessions.
     */
    private function probeFileSessions(): void
    {
        $path = Configured::string('session.files');

        if ($path === null) {
            throw new RuntimeException('The session file path is not configured.');
        }

        if (! is_dir($path) || ! is_writable($path)) {
            throw new RuntimeException(sprintf('The session file path [%s] is not writable.', $path));
        }
    }

    /**
     * Probe database-backed sessions.
     */
    private function probeDatabaseSessions(): void
    {
        $table = Configured::string('session.table', 'sessions');

        if (! $this->schema()->hasTable($table)) {
            throw new RuntimeException(sprintf('The [%s] session table does not exist.', $table));
        }
    }

    /**
     * Probe Redis-backed sessions.
     */
    private function probeRedisSessions(): void
    {
        Redis::connection(Configured::string('session.connection', 'default'))->ping();
    }

    /**
     * Get the schema builder for the session connection.
     */
    private function schema(): Builder
    {
        return Schema::connection(Configured::string('session.connection'));
    }

    /**
     * Resolve custom session drivers through Laravel's session manager.
     */
    private function probeSessionManager(): void
    {
        app(SessionManager::class)->driver();
    }
}
