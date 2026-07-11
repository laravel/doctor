<?php

use Laravel\Doctor\Diagnostics\DebugModeMatchesEnvironment;

it('reports debug mode in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => true]);

    $result = (new DebugModeMatchesEnvironment)->check();

    expect($result->status->value)->toBe('fail')
        ->and($result->summary)->toBe('Debug mode is enabled in production.');
});

it('treats unmapped environments as production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'staging');
    config(['app.debug' => true]);

    $result = (new DebugModeMatchesEnvironment)->check();

    expect($result->status->value)->toBe('fail')
        ->and($result->summary)->toBe('Debug mode is enabled in production.');
});

it('honors custom local environment mappings', function (): void {
    $this->app->detectEnvironment(fn (): string => 'dev');
    config([
        'app.debug' => true,
        'doctor.environments.dev' => 'local',
    ]);

    $result = (new DebugModeMatchesEnvironment)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->summary)->toBe('Debug mode matches the application environment.');
});
