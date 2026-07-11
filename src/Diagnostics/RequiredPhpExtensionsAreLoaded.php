<?php

namespace Laravel\Doctor\Diagnostics;

use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;
use Laravel\Doctor\Support\ComposerJson;

class RequiredPhpExtensionsAreLoaded extends Diagnostic
{
    public string $name = 'Required PHP extensions loaded';

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
            'composer-unreadable' => 'The application does not have a readable composer.json file.',
            'installed' => 'All Composer-required PHP extensions are installed.',
            'missing' => Message::make(
                summary: 'Some Composer-required PHP extensions are missing.',
                remediation: 'Install the missing PHP extensions for the PHP binary running Laravel.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if ($this->composer->contents() === null) {
            return $this->skip('composer-unreadable');
        }

        $missing = array_values(array_filter(
            $this->composer->requiredExtensions(),
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));

        if ($missing === []) {
            return $this->pass('installed');
        }

        return $this->fail('missing')
            ->withDetails(implode(PHP_EOL, array_map(
                static fn (string $extension): string => '- ext-'.$extension,
                $missing,
            )));
    }
}
