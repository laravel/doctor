<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Facades\Storage;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\Configured;
use RuntimeException;
use Throwable;

class FilesystemDisksAreReachable extends Diagnostic
{
    public string $name = 'Filesystem disks connect';

    public string $group = 'storage';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default filesystem disk configured.',
            'disk-missing' => 'The default filesystem disk [{disk}] is not configured.',
            'unreachable' => Message::make(
                summary: 'The application cannot reach the default filesystem disk.',
                remediation: 'Check filesystem disk roots, credentials, and network access.',
            ),
            'reachable' => 'The application can reach the default filesystem disk.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $disk = Configured::string('filesystems.default');

        if ($disk === null) {
            return $this->skip('not-configured');
        }

        $configuration = $this->configuration($disk);

        if ($configuration === null) {
            return $this->skip('disk-missing', ['disk' => $disk]);
        }

        try {
            $this->probe($disk, $configuration);
        } catch (Throwable $e) {
            return $this->fail('unreachable')
                ->withDetails($e->getMessage());
        }

        return $this->pass('reachable');
    }

    /**
     * Probe a filesystem disk.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function probe(string $disk, array $configuration): void
    {
        if (($configuration['driver'] ?? null) === 'local') {
            $this->probeLocalDisk($configuration);

            return;
        }

        Storage::disk($disk)->exists('.laravel-doctor');
    }

    /**
     * Probe a local filesystem disk.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function probeLocalDisk(array $configuration): void
    {
        $root = $configuration['root'] ?? null;

        if (! is_string($root) || $root === '') {
            throw new RuntimeException('The local disk root is not configured.');
        }

        if (! is_dir($root) || ! is_readable($root)) {
            throw new RuntimeException(sprintf('The local disk root [%s] is not readable.', $root));
        }
    }

    /**
     * Get the default filesystem disk configuration.
     *
     * @return array<string, mixed>|null
     */
    private function configuration(string $disk): ?array
    {
        $configuration = config("filesystems.disks.{$disk}");

        return is_array($configuration) ? $configuration : null;
    }
}
