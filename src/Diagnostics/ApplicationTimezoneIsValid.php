<?php

namespace Laravel\Doctor\Diagnostics;

use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Message;

class ApplicationTimezoneIsValid extends Diagnostic
{
    public string $name = 'App timezone is valid';

    public string $group = 'environment';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'missing' => Message::make(
                summary: 'The application does not have a timezone configured.',
                remediation: 'Set APP_TIMEZONE or app.timezone to a valid PHP timezone.',
            ),
            'valid' => 'The application has a valid timezone.',
            'invalid' => Message::make(
                summary: 'The application timezone [{timezone}] is not a valid PHP timezone.',
                remediation: 'Set app.timezone to one of PHP\'s supported timezone identifiers.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $timezone = config('app.timezone');

        if (! is_string($timezone) || $timezone === '') {
            return $this->fail('missing');
        }

        if ($this->isValidTimezone($timezone)) {
            return $this->pass('valid');
        }

        return $this->fail('invalid', ['timezone' => $timezone]);
    }

    /**
     * Determine whether the timezone is recognized by PHP.
     */
    private function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }
}
