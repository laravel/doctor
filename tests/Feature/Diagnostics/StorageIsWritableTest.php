<?php

use Laravel\Doctor\Diagnostics\StorageIsWritable;
use Laravel\Doctor\DiagnosticSelection;
use Laravel\Doctor\Doctor;
use Laravel\Doctor\Results\DiagnosticOutcome;
use Laravel\Doctor\Results\DiagnosticResult;

function doctor_writable_storage_base_path(): string
{
    $basePath = sys_get_temp_dir().'/laravel-doctor-'.str_replace('.', '', uniqid('', true));

    mkdir($basePath, 0775, true);

    return $basePath;
}

function doctor_create_writable_storage_tree(string $basePath): void
{
    foreach (doctor_writable_storage_paths() as $relative) {
        mkdir($basePath.'/'.$relative, 0775, true);
    }
}

/**
 * @return list<string>
 */
function doctor_writable_storage_paths(): array
{
    return [
        'storage',
        'storage/app',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ];
}

it('passes when laravel can write to the required storage directories', function (): void {
    $basePath = doctor_writable_storage_base_path();
    doctor_create_writable_storage_tree($basePath);

    $this->app->setBasePath($basePath);

    $report = $this->app->make(Doctor::class)
        ->run(DiagnosticSelection::make(only: ['StorageIsWritable']));

    $outcome = $report->diagnostics()[0];

    expect($outcome->diagnostic::class)->toBe(StorageIsWritable::class)
        ->and($outcome->result->status->value)->toBe('pass')
        ->and($outcome->result->summary)->toBe('The application storage directories are writable.');
});

it('reports missing writable storage directories', function (): void {
    $basePath = doctor_writable_storage_base_path();

    $this->app->setBasePath($basePath);

    $report = $this->app->make(Doctor::class)
        ->run(DiagnosticSelection::make(only: ['StorageIsWritable']));

    $outcome = $report->diagnostics()[0];

    expect($outcome->result->status->value)->toBe('fail')
        ->and($outcome->result->details)->toContain('storage/: directory does not exist')
        ->and($outcome->result->confirmation)->toBe('Would you like Doctor to make the storage directories writable?')
        ->and($outcome->result->remediation)->toBe('Ensure storage directories and bootstrap/cache exist and are writable by the PHP process.');
});

it('creates missing writable storage directories when fixed', function (): void {
    $basePath = doctor_writable_storage_base_path();

    $this->app->setBasePath($basePath);

    $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
        new StorageIsWritable,
        DiagnosticResult::fail('The application cannot write to every required storage directory.'),
    ));

    expect($fix->result->status->value)->toBe('pass')
        ->and($fix->result->summary)->toBe('The application storage directories are writable.');

    foreach (doctor_writable_storage_paths() as $relative) {
        expect(is_dir($basePath.'/'.$relative))->toBeTrue()
            ->and(is_writable($basePath.'/'.$relative))->toBeTrue();
    }
});
