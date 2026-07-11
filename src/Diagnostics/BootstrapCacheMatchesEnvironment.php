<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Str;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\EnvironmentMode;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;

class BootstrapCacheMatchesEnvironment extends Diagnostic
{
    public string $name = 'Bootstrap cache matches environment';

    public string $group = 'configuration';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'uncached-production' => Message::make(
                summary: 'The application bootstrap files are not cached.',
                remediation: 'Run `php artisan optimize` or `php artisan config:cache` during deployment.',
            ),
            'uncached-local' => 'The application bootstrap files are not cached.',
            'cached-production' => 'The application bootstrap files are cached.',
            'cached-local' => Message::make(
                summary: 'Cached bootstrap {files} detected: {cached}.',
                remediation: 'If recent changes are not appearing, run `php artisan optimize:clear`.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $cached = $this->cachedFiles();

        if ($cached === []) {
            if (EnvironmentMode::current()->isProduction()) {
                return $this->warn('uncached-production');
            }

            return $this->pass('uncached-local');
        }

        if (EnvironmentMode::current()->isProduction()) {
            return $this->pass('cached-production');
        }

        return $this->notice('cached-local', [
            'files' => Str::plural('file', count($cached)),
            'cached' => $this->formatCachedFiles($cached),
        ]);
    }

    /**
     * @return list<string>
     */
    private function cachedFiles(): array
    {
        $cached = [];

        if (is_file(app()->getCachedConfigPath())) {
            $cached[] = 'config';
        }

        if (is_file(app()->getCachedEventsPath())) {
            $cached[] = 'events';
        }

        if (is_file(app()->getCachedRoutesPath())) {
            $cached[] = 'routes';
        }

        if ($this->viewsAreCached()) {
            $cached[] = 'views';
        }

        return $cached;
    }

    private function viewsAreCached(): bool
    {
        $path = config('view.compiled');

        if (! is_string($path)) {
            return false;
        }

        $files = glob($path.'/*.php');

        return is_array($files) && $files !== [];
    }

    /**
     * @param  list<string>  $cached
     */
    private function formatCachedFiles(array $cached): string
    {
        return collect($cached)->join(', ', ' and ');
    }
}
