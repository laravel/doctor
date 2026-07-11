<?php

namespace Laravel\Doctor\Diagnostics;

use Laravel\Doctor\Contracts\Fixable;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\FixResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Details;
use RuntimeException;

class StorageIsWritable extends Diagnostic implements Fixable
{
    public string $name = 'Storage is writable';

    public string $group = 'storage';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'writable' => 'The application storage directories are writable.',
            'not-writable' => Message::make(
                summary: 'The application cannot write to every required storage directory.',
                remediation: 'Ensure storage directories and bootstrap/cache exist and are writable by the PHP process.',
                confirmation: 'Would you like Doctor to make the storage directories writable?',
            ),
            'still-not-writable' => 'Some storage directories are still not writable.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $failures = $this->failures();

        if ($failures === []) {
            return $this->pass('writable');
        }

        return $this->fail('not-writable')
            ->withDetails(Details::failures($failures));
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result): FixResult
    {
        $changed = [];

        foreach ($this->paths() as $relative => $path) {
            if (! is_dir($path)) {
                if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
                    throw new RuntimeException(sprintf('Unable to create [%s].', $relative));
                }

                $changed[] = sprintf('Created %s.', $relative);
            }

            if (! @chmod($path, 0775)) {
                throw new RuntimeException(sprintf('Unable to change permissions for [%s].', $relative));
            }
        }

        $failures = $this->failures();

        if ($failures !== []) {
            return $this->fixFailed('still-not-writable')
                ->withDetails(Details::failures($failures));
        }

        if ($changed === []) {
            return $this->fixed('writable');
        }

        return $this->fixed('writable')
            ->withDetails(implode(PHP_EOL, $changed));
    }

    /**
     * @return array<string, string>
     */
    private function paths(): array
    {
        return [
            'storage/' => base_path('storage'),
            'storage/app/' => base_path('storage/app'),
            'storage/framework/cache/' => base_path('storage/framework/cache'),
            'storage/framework/sessions/' => base_path('storage/framework/sessions'),
            'storage/framework/views/' => base_path('storage/framework/views'),
            'storage/logs/' => base_path('storage/logs'),
            'bootstrap/cache/' => base_path('bootstrap/cache'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function failures(): array
    {
        $failures = [];

        foreach ($this->paths() as $relative => $path) {
            if (! is_dir($path)) {
                $failures[$relative] = 'directory does not exist';

                continue;
            }

            if (! $this->canWriteToDirectory($path)) {
                $failures[$relative] = 'directory is not writable';
            }
        }

        return $failures;
    }

    private function canWriteToDirectory(string $path): bool
    {
        $file = $path.DIRECTORY_SEPARATOR.'.doctor-write-test-'.str_replace('.', '', uniqid('', true));

        $handle = @fopen($file, 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return @unlink($file);
    }
}
