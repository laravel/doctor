<?php

namespace Laravel\Doctor\Diagnostics;

use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\EnvironmentMode;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;

class QueueConnectionIsAsynchronous extends Diagnostic
{
    public string $name = 'Queue runs asynchronously';

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
            'sync-production' => Message::make(
                summary: 'Queued jobs run synchronously in production.',
                remediation: 'Set QUEUE_CONNECTION to a background queue driver such as database, redis, sqs, or beanstalkd.',
            ),
            'sync-local' => 'Queued jobs run synchronously.',
            'async' => 'Queued jobs are processed asynchronously.',
            'async-local' => Message::make(
                summary: 'Queued jobs are processed asynchronously.',
                remediation: 'Make sure a queue worker is running with `php artisan queue:work` if jobs are not being processed.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $connection = config('queue.default');

        if (! is_string($connection) || $connection === '') {
            return $this->skip('not-configured');
        }

        if ($connection === 'sync' && EnvironmentMode::current()->isProduction()) {
            return $this->warn('sync-production');
        }

        if ($connection === 'sync') {
            return $this->pass('sync-local');
        }

        if (EnvironmentMode::current()->isProduction()) {
            return $this->pass('async');
        }

        return $this->notice('async-local');
    }
}
