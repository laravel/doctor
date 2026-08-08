<?php

use Laravel\Doctor\Diagnostics\ApplicationTimezoneIsValid;
use Laravel\Doctor\Results\Link;

it('reports a missing application timezone', function (): void {
    config(['app.timezone' => null]);

    $result = (new ApplicationTimezoneIsValid)->check();

    expect($result->status->value)->toBe('fail')
        ->and($result->code)->toBe('application-timezone-is-valid.missing')
        ->and($result->summary)->toBe('The application does not have a timezone configured.')
        ->and($result->links)->toEqual([Link::to('PHP timezone list', 'https://www.php.net/manual/en/timezones.php')]);
});

it('warns about a backward-compatible timezone identifier', function (string $timezone): void {
    config(['app.timezone' => $timezone]);

    $result = (new ApplicationTimezoneIsValid)->check();

    expect($result->status->value)->toBe('warn')
        ->and($result->code)->toBe('application-timezone-is-valid.backward-compatible')
        ->and($result->summary)->toBe("The application timezone [{$timezone}] only exists for backward compatibility.")
        ->and($result->remediation)->toBe('Use the location-based identifier for the region, such as America/New_York instead of US/Eastern.')
        ->and($result->links)->toEqual([Link::to('PHP backward compatible timezones', 'https://www.php.net/manual/en/timezones.others.php')]);
})->with([
    'region alias' => 'US/Eastern',
    'abbreviation' => 'GMT',
    'renamed zone' => 'Asia/Calcutta',
]);

it('passes for UTC, which PHP exempts from its backward compatibility warning', function (): void {
    config(['app.timezone' => 'UTC']);

    $result = (new ApplicationTimezoneIsValid)->check();

    expect($result->status->value)->toBe('pass');
});

it('reports an invalid application timezone', function (): void {
    config(['app.timezone' => 'Invalid/Timezone']);

    $result = (new ApplicationTimezoneIsValid)->check();

    expect($result->status->value)->toBe('fail')
        ->and($result->code)->toBe('application-timezone-is-valid.invalid')
        ->and($result->summary)->toBe('The application timezone [Invalid/Timezone] is not a valid PHP timezone.')
        ->and($result->details)->toBeNull()
        ->and($result->links)->toEqual([Link::to('PHP timezone list', 'https://www.php.net/manual/en/timezones.php')]);
});
