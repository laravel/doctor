<?php

use Laravel\Doctor\Diagnostics\QueueConnectionIsAsynchronous;

it('passes when queues run synchronously', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');

    config([
        'queue.default' => 'sync',
    ]);

    $result = (new QueueConnectionIsAsynchronous)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->summary)->toBe('Queued jobs run synchronously.');
});

it('warns when queues run synchronously in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['queue.default' => 'sync']);

    $result = (new QueueConnectionIsAsynchronous)->check();

    expect($result->status->value)->toBe('warn')
        ->and($result->summary)->toBe('Queued jobs run synchronously in production.');
});

it('warns when queues run synchronously in unmapped environments', function (): void {
    $this->app->detectEnvironment(fn (): string => 'staging');
    config(['queue.default' => 'sync']);

    $result = (new QueueConnectionIsAsynchronous)->check();

    expect($result->status->value)->toBe('warn')
        ->and($result->summary)->toBe('Queued jobs run synchronously in production.');
});

it('notices when queued jobs are processed asynchronously locally', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');

    config([
        'queue.default' => 'database',
    ]);

    $result = (new QueueConnectionIsAsynchronous)->check();

    expect($result->status->value)->toBe('notice')
        ->and($result->summary)->toBe('Queued jobs are processed asynchronously.')
        ->and($result->remediation)->toBe('Make sure a queue worker is running with `php artisan queue:work` if jobs are not being processed.');
});

it('passes when queued jobs are processed asynchronously outside local environments', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['queue.default' => 'database']);

    $result = (new QueueConnectionIsAsynchronous)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->summary)->toBe('Queued jobs are processed asynchronously.');
});

it('skips when the default queue connection is not configured', function (): void {
    config(['queue.default' => null]);

    $result = (new QueueConnectionIsAsynchronous)->check();

    expect($result->status->value)->toBe('skip')
        ->and($result->summary)->toBe('The application does not have a default queue connection configured.');
});
