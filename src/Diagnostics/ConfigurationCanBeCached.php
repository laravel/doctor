<?php

namespace Laravel\Doctor\Diagnostics;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use LogicException;
use RuntimeException;
use Throwable;
use UnitEnum;

class ConfigurationCanBeCached extends Diagnostic
{
    public string $name = 'Config can be cached';

    public string $group = 'configuration';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'cannot-cache' => Message::make(
                summary: 'The application configuration cannot be cached.',
                remediation: 'Remove non-serializable values from configuration files before running `php artisan config:cache`.',
            ),
            'can-cache' => 'The application configuration can be cached.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        try {
            $this->cacheConfiguration();
        } catch (Throwable $e) {
            return $this->fail('cannot-cache')
                ->withDetails($e->getMessage());
        }

        return $this->pass('can-cache');
    }

    /**
     * Cache the current configuration to a temporary file.
     */
    private function cacheConfiguration(): void
    {
        $path = $this->temporaryCachePath();

        try {
            File::put($path, '<?php return '.var_export(config()->all(), true).';'.PHP_EOL);

            require $path;
        } catch (Throwable $e) {
            throw $this->serializationException($e);
        } finally {
            File::delete($path);
        }
    }

    /**
     * Build the most specific serialization exception available.
     */
    private function serializationException(Throwable $previous): Throwable
    {
        foreach (Arr::dot(config()->all()) as $key => $value) {
            if (is_object($value) && ! $value instanceof UnitEnum && ! method_exists($value, '__set_state')) {
                return new LogicException(
                    sprintf('The configuration value at [%s] is not serializable.', $key),
                    0,
                    $previous,
                );
            }
        }

        return new LogicException('The configuration files are not serializable.', 0, $previous);
    }

    /**
     * Get a temporary config cache path.
     */
    private function temporaryCachePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'laravel-doctor-config-');

        if ($path === false) {
            throw new RuntimeException('Doctor could not create a temporary config cache file.');
        }

        return $path;
    }
}
