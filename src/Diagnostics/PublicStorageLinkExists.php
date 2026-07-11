<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Facades\Process;
use Laravel\Doctor\Contracts\Fixable;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\FixResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Details;

class PublicStorageLinkExists extends Diagnostic implements Fixable
{
    public string $name = 'Public storage is linked';

    public string $group = 'storage';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'not-default-local-path' => 'The public disk does not use Laravel\'s default local public storage path.',
            'unused' => 'The public storage directory does not contain public files.',
            'linked' => 'The public storage link exists.',
            'missing' => Message::make(
                summary: 'The public storage link does not exist.',
                remediation: 'Create the public storage link with `php artisan storage:link`.',
                confirmation: 'Would you like Doctor to create the public storage link using `php artisan storage:link`?',
            ),
            'creation-failed' => 'The public storage link could not be created.',
            'created' => 'The public storage link was created.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (! $this->expectsPublicStorageLink()) {
            return $this->skip('not-default-local-path');
        }

        if ($this->publicDiskIsUnused()) {
            return $this->skip('unused');
        }

        if (file_exists($this->publicStoragePath())) {
            return $this->pass('linked');
        }

        return $this->fail('missing');
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result): FixResult
    {
        $process = Process::path(base_path())->run([PHP_BINARY, 'artisan', 'storage:link']);

        if (! $process->successful()) {
            return $this->fixFailed('creation-failed')
                ->withDetails(Details::processOutput(
                    $process->output(),
                    $process->errorOutput(),
                    'The storage:link command exited without output.',
                ));
        }

        return $this->fixed('created');
    }

    private function expectsPublicStorageLink(): bool
    {
        $disk = config('filesystems.disks.public');

        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local') {
            return false;
        }

        $root = $disk['root'] ?? null;

        return is_string($root)
            && $this->normalize($root) === $this->normalize(base_path('storage/app/public'));
    }

    private function publicStoragePath(): string
    {
        return base_path('public/storage');
    }

    private function publicDiskIsUnused(): bool
    {
        $path = base_path('storage/app/public');

        if (! is_dir($path)) {
            return true;
        }

        $files = scandir($path);

        if ($files === false) {
            return false;
        }

        return array_values(array_diff($files, ['.', '..', '.gitignore'])) === [];
    }

    private function normalize(string $path): string
    {
        $realPath = realpath($path);

        return $realPath === false ? rtrim($path, DIRECTORY_SEPARATOR) : $realPath;
    }
}
