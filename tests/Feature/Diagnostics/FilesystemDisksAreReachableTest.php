<?php

use Laravel\Doctor\Diagnostics\FilesystemDisksAreReachable;

function doctor_filesystem_disk_root(): string
{
    $path = sys_get_temp_dir().'/laravel-doctor-filesystem-disk-'.str_replace('.', '', uniqid('', true));

    mkdir($path, 0775, true);

    return $path;
}

it('passes when the default local filesystem disk is reachable', function (): void {
    config([
        'filesystems.default' => 'doctor',
        'filesystems.disks' => [
            'doctor' => [
                'driver' => 'local',
                'root' => doctor_filesystem_disk_root(),
            ],
            's3' => [
                'driver' => 's3',
                'key' => 'unused',
                'secret' => 'unused',
                'region' => 'us-east-1',
                'bucket' => 'unused',
            ],
        ],
    ]);

    $result = (new FilesystemDisksAreReachable)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->summary)->toBe('The application can reach the default filesystem disk.');
});

it('skips when the default filesystem disk is not configured', function (): void {
    config([
        'filesystems.default' => 'missing',
        'filesystems.disks' => [
            'doctor' => [
                'driver' => 'local',
                'root' => doctor_filesystem_disk_root(),
            ],
        ],
    ]);

    $result = (new FilesystemDisksAreReachable)->check();

    expect($result->status->value)->toBe('skip')
        ->and($result->summary)->toBe('The default filesystem disk [missing] is not configured.')
        ->and($result->details)->toBeNull();
});
