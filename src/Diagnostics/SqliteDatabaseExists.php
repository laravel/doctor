<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Facades\File;
use Laravel\Doctor\Contracts\Fixable;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\FixResult;
use Laravel\Doctor\Results\Link;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Configured;

class SqliteDatabaseExists extends Diagnostic implements Fixable
{
    public string $name = 'SQLite database exists';

    public string $group = 'database';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'not-sqlite' => 'The default database connection is not SQLite.',
            'not-file' => 'The SQLite connection does not use a database file.',
            'exists' => 'The SQLite database file exists.',
            'missing' => Message::make(
                summary: 'The SQLite database file does not exist.',
                remediation: 'Create the SQLite database file with `touch {database}`.',
                confirmation: 'Create the SQLite database file?',
            )->link(Link::docs('database', 'configuration')),
            'already-exists' => 'The SQLite database file already exists.',
            'creation-failed' => 'The SQLite database file could not be created.',
            'created' => 'The SQLite database file was created.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (config('database.default') !== 'sqlite') {
            return $this->skip('not-sqlite');
        }

        $database = Configured::string('database.connections.sqlite.database');

        if ($database === null || $database === ':memory:') {
            return $this->skip('not-file');
        }

        if (! $this->isAbsolutePath($database)) {
            $database = base_path($database);
        }

        if (is_file($database)) {
            return $this->pass('exists')
                ->withContext('database', $database);
        }

        return $this->fail('missing', ['database' => $database])
            ->fixable()
            ->withContext('database', $database);
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        /** @var string $database */
        $database = $result->context['database'];

        if (is_file($database)) {
            return $this->fixed('already-exists');
        }

        File::ensureDirectoryExists(dirname($database));

        if (File::put($database, '') === false) {
            return $this->fixFailed('creation-failed');
        }

        return $this->fixed('created');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1;
    }
}
