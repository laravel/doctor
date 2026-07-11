<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Laravel\Doctor\Diagnostics\PublicStorageLinkExists;
use Laravel\Doctor\Results\DiagnosticResult;

function doctor_public_storage_link_base_path(): string
{
    $basePath = sys_get_temp_dir().'/laravel-doctor-public-storage-link-'.str_replace('.', '', uniqid('', true));

    mkdir($basePath, 0775, true);

    return $basePath;
}

it('reports a missing public storage link and can fix it', function (): void {
    $basePath = doctor_public_storage_link_base_path();
    mkdir($basePath.'/storage/app/public', 0775, true);
    mkdir($basePath.'/public', 0775, true);
    touch($basePath.'/storage/app/public/avatar.jpg');

    $this->app->setBasePath($basePath);
    config(['filesystems.disks.public' => [
        'driver' => 'local',
        'root' => $basePath.'/storage/app/public',
    ]]);

    Process::fake([
        '*' => Process::result(output: 'The [public/storage] link has been connected.'),
    ]);

    $diagnostic = new PublicStorageLinkExists;
    $result = $diagnostic->check();
    $fix = $diagnostic->fix(DiagnosticResult::fail('missing'));

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'storage:link']
        && $process->path === $basePath);

    expect($result->status->value)->toBe('fail')
        ->and($result->confirmation)->toBe('Would you like Doctor to create the public storage link using `php artisan storage:link`?')
        ->and($result->remediation)->toBe('Create the public storage link with `php artisan storage:link`.')
        ->and($fix->status->value)->toBe('pass');
});

it('skips when public storage only contains a gitignore placeholder', function (): void {
    $basePath = doctor_public_storage_link_base_path();
    mkdir($basePath.'/storage/app/public', 0775, true);
    mkdir($basePath.'/public', 0775, true);
    touch($basePath.'/storage/app/public/.gitignore');

    $this->app->setBasePath($basePath);
    config(['filesystems.disks.public' => [
        'driver' => 'local',
        'root' => $basePath.'/storage/app/public',
    ]]);

    $result = (new PublicStorageLinkExists)->check();

    expect($result->status->value)->toBe('skip')
        ->and($result->summary)->toBe('The public storage directory does not contain public files.');
});
