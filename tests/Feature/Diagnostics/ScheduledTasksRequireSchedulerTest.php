<?php

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Doctor\Diagnostics\ScheduledTasksRequireScheduler;

it('passes when no scheduled tasks are registered', function (): void {
    $this->app->instance(Schedule::class, new Schedule);

    $result = (new ScheduledTasksRequireScheduler)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->summary)->toBe('The application does not have scheduled tasks.');
});

it('notices when scheduled tasks are registered', function (): void {
    $schedule = new Schedule;
    $schedule->call(fn (): bool => true)->everyMinute();

    $this->app->instance(Schedule::class, $schedule);

    $result = (new ScheduledTasksRequireScheduler)->check();

    expect($result->status->value)->toBe('notice')
        ->and($result->summary)->toBe('The application has scheduled tasks.')
        ->and($result->remediation)->toContain('php artisan schedule:run');
});
