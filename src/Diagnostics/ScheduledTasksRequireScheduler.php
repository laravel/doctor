<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;

class ScheduledTasksRequireScheduler extends Diagnostic
{
    public string $name = 'Scheduled tasks registered';

    public string $group = 'scheduler';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'no-tasks' => 'The application does not have scheduled tasks.',
            'tasks-registered' => Message::make(
                summary: 'The application has scheduled tasks.',
                remediation: 'Make sure the scheduler is running with `php artisan schedule:run` every minute or `php artisan schedule:work` during development.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (app(Schedule::class)->events() === []) {
            return $this->pass('no-tasks');
        }

        return $this->notice('tasks-registered');
    }
}
