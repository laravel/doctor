<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Database\Migrations\Migrator;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Details;
use Throwable;

class MigrationsAreUpToDate extends Diagnostic
{
    public string $name = 'Migrations are current';

    public string $group = 'database';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'no-files' => 'The application does not have migration files.',
            'repository-missing' => Message::make(
                summary: 'The migrations table does not exist.',
                remediation: 'Create the migrations table and run pending migrations.',
            ),
            'inspection-failed' => 'The application could not inspect database migrations.',
            'current' => 'Database migrations are current.',
            'pending' => Message::make(
                summary: 'Database migrations are pending.',
                remediation: 'Run pending database migrations.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        try {
            $files = $migrator->getMigrationFiles(array_values(array_unique([
                ...$migrator->paths(),
                base_path('database/migrations'),
            ])));

            if ($files === []) {
                return $this->pass('no-files');
            }

            if (! $migrator->repositoryExists()) {
                return $this->fail('repository-missing');
            }

            $pending = array_values(array_diff(
                array_keys($files),
                $migrator->getRepository()->getRan(),
            ));
        } catch (Throwable $e) {
            return $this->fail('inspection-failed')
                ->withDetails($e->getMessage());
        }

        if ($pending === []) {
            return $this->pass('current');
        }

        return $this->fail('pending')
            ->withDetails(Details::bullets($pending));
    }
}
