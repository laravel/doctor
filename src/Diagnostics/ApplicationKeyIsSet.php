<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Facades\Artisan;
use Laravel\Doctor\Contracts\Fixable;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\FixResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Details;

class ApplicationKeyIsSet extends Diagnostic implements Fixable
{
    public string $name = 'App key is set';

    public string $group = 'environment';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'configured' => 'The application key is configured.',
            'missing' => Message::make(
                summary: 'The application key is not configured.',
                remediation: 'Generate an application key with `php artisan key:generate`.',
                confirmation: 'Would you like Doctor to generate an application key using `php artisan key:generate`?',
            ),
            'generated' => 'The application key was generated.',
            'generation-failed' => 'The application key could not be generated.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if ($this->keyIsConfigured()) {
            return $this->pass('configured');
        }

        return $this->fail('missing');
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result): FixResult
    {
        $path = app()->environmentFilePath();

        // The key:generate command reports success even when the write fails...
        if (! is_file($path) || ! is_writable($path)) {
            return $this->fixFailed('generation-failed')
                ->withDetails(sprintf('The application environment file [%s] could not be updated.', $path));
        }

        Artisan::call('key:generate', ['--force' => true]);

        if (! $this->keyIsConfigured()) {
            return $this->fixFailed('generation-failed')
                ->withDetails(Details::processOutput(
                    Artisan::output(),
                    '',
                    'The key:generate command did not set an application key.',
                ));
        }

        return $this->fixed('generated');
    }

    /**
     * Determine whether the application key is configured.
     */
    private function keyIsConfigured(): bool
    {
        $key = config('app.key');

        return is_string($key) && trim($key) !== '';
    }
}
