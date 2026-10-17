<?php

namespace Laravel\Doctor\Diagnostics;

use DateTimeZone;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Link;
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
            )->link(Link::to('PHP timezone list', 'https://www.php.net/manual/en/timezones.php')),
            'valid' => 'The application has a valid timezone.',
            'backward-compatible' => Message::make(
                summary: 'The application timezone [{timezone}] only exists for backward compatibility.',
                remediation: 'Use the location-based identifier for the region, such as America/New_York instead of US/Eastern.',
            )->link(Link::to('PHP backward compatible timezones', 'https://www.php.net/manual/en/timezones.others.php')),
            'invalid' => Message::make(
                summary: 'The application timezone [{timezone}] is not a valid PHP timezone.',
                remediation: 'Set APP_TIMEZONE or app.timezone to a valid PHP timezone.',
            )->link(Link::to('PHP timezone list', 'https://www.php.net/manual/en/timezones.php')),
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

        if ($this->isBackwardCompatibleTimezone($timezone)) {
            return $this->warn('backward-compatible', ['timezone' => $timezone]);
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

    /**
     * Determine whether the timezone is one PHP keeps only for backward compatibility.
     *
     * These identifiers still work, since Laravel boots the application with
     * date_default_timezone_set, but PHP recommends against them and may drop
     * them from the IANA database.
     */
    private function isBackwardCompatibleTimezone(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true);
    }
}
