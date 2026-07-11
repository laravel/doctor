<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Facades\File;
use Laravel\Doctor\Contracts\Fixable;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\FixResult;
use Laravel\Doctor\Results\Message;

class EnvironmentFileExists extends Diagnostic implements Fixable
{
    public string $name = '.env file exists';

    public string $group = 'environment';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'exists' => 'The application has an environment file.',
            'missing' => Message::make(
                summary: 'The application does not have an environment file.',
                confirmation: 'Would you like Doctor to copy .env.example to .env?',
            ),
            'missing-with-example' => Message::make(
                summary: 'The application does not have an environment file.',
                remediation: 'Copy the example environment file to .env, then review its values.',
                confirmation: 'Would you like Doctor to copy .env.example to .env?',
            ),
            'already-exists' => 'The .env file already exists.',
            'example-missing' => 'The .env.example file does not exist.',
            'creation-failed' => 'The .env file could not be created from .env.example.',
            'created' => 'The .env file was created from .env.example.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if ($this->environmentFiles() !== []) {
            return $this->pass('exists');
        }

        if (is_file(base_path('.env.example'))) {
            return $this->fail('missing-with-example');
        }

        return $this->fail('missing');
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result): FixResult
    {
        $target = base_path('.env');
        $example = base_path('.env.example');

        if (is_file($target)) {
            return $this->fixed('already-exists');
        }

        if (! is_file($example)) {
            return $this->fixFailed('example-missing');
        }

        if (! @copy($example, $target)) {
            return $this->fixFailed('creation-failed');
        }

        return $this->fixed('created')
            ->withDetails('Review .env and run `php artisan key:generate` if APP_KEY is empty.');
    }

    /**
     * Get the application's environment files.
     *
     * @return list<string>
     */
    private function environmentFiles(): array
    {
        return array_values(array_filter(
            File::glob(base_path('.env*')) ?: [],
            static fn (string $file): bool => is_file($file)
                && ! str_ends_with(basename($file), '.example'),
        ));
    }
}
