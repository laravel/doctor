<?php

namespace Laravel\Doctor\Diagnostics;

use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\EnvironmentMode;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;

class DebugModeMatchesEnvironment extends Diagnostic
{
    public string $name = 'Debug matches environment';

    public string $group = 'security';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'enabled-in-production' => Message::make(
                summary: 'Debug mode is enabled in production.',
                remediation: 'Set APP_DEBUG=false in production.',
            ),
            'matches' => 'Debug mode matches the application environment.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if ($this->debugIsEnabledInProduction()) {
            return $this->fail('enabled-in-production');
        }

        return $this->pass('matches');
    }

    /**
     * Determine whether debug mode is enabled in production.
     */
    private function debugIsEnabledInProduction(): bool
    {
        return (bool) config('app.debug') && EnvironmentMode::current()->isProduction();
    }
}
