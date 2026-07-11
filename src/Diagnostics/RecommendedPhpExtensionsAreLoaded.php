<?php

namespace Laravel\Doctor\Diagnostics;

use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\ComposerJson;

class RecommendedPhpExtensionsAreLoaded extends Diagnostic
{
    public string $name = 'Recommended PHP extensions loaded';

    public string $group = 'environment';

    /**
     * Create a new diagnostic instance.
     */
    public function __construct(protected ComposerJson $composer)
    {
        parent::__construct();
    }

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'none-declared' => 'No Composer-suggested PHP extensions are declared.',
            'installed' => 'All Composer-suggested PHP extensions are installed.',
            'missing' => Message::make(
                summary: 'Some Composer-suggested PHP extensions are missing.',
                remediation: 'Install the missing PHP extensions when the related optional features are used.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $extensions = $this->composer->suggestedExtensions();

        if ($extensions === []) {
            return $this->pass('none-declared');
        }

        $missing = $this->missingExtensions($extensions);

        if ($missing === []) {
            return $this->pass('installed');
        }

        return $this->warn('missing')
            ->withDetails($this->formatExtensions($missing));
    }

    /**
     * Get the missing PHP extensions.
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function missingExtensions(array $extensions): array
    {
        return array_values(array_filter(
            $extensions,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));
    }

    /**
     * Format extension names for diagnostic details.
     *
     * @param  list<string>  $extensions
     */
    private function formatExtensions(array $extensions): string
    {
        return implode(PHP_EOL, array_map(
            static fn (string $extension): string => '- ext-'.$extension,
            $extensions,
        ));
    }
}
