<?php

use Laravel\Doctor\Diagnostics\SqliteDatabaseExists;
use Laravel\Doctor\Results\Link;

function doctor_sqlite_database_base_path(): string
{
    $basePath = sys_get_temp_dir().'/laravel-doctor-sqlite-database-'.str_replace('.', '', uniqid('', true));

    mkdir($basePath, 0775, true);

    return $basePath;
}

it('passes when the sqlite database file exists', function (): void {
    $basePath = doctor_sqlite_database_base_path();
    mkdir($basePath.'/database');
    touch($basePath.'/database/database.sqlite');

    $this->app->setBasePath($basePath);
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => 'database/database.sqlite',
    ]);

    $result = (new SqliteDatabaseExists)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->context['database'])->toBe($basePath.'/database/database.sqlite');
});

it('preserves an absolute Windows sqlite database path', function (string $database): void {
    $this->app->setBasePath(doctor_sqlite_database_base_path());
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $database,
    ]);

    $result = (new SqliteDatabaseExists)->check();

    expect($result->status->value)->toBe('fail')
        ->and($result->context['database'])->toBe($database);
})->with([
    'backslash separators' => 'C:\\Users\\MyUser\\Herd\\my-project\\database\\database.sqlite',
    'forward slash separators' => 'C:/Users/MyUser/Herd/my-project/database/database.sqlite',
]);

it('reports a missing sqlite database file and carries the path to the fix', function (): void {
    $basePath = doctor_sqlite_database_base_path();

    $this->app->setBasePath($basePath);
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => 'database/database.sqlite',
    ]);

    $diagnostic = new SqliteDatabaseExists;
    $result = $diagnostic->check();

    config(['database.connections.sqlite.database' => 'database/changed.sqlite']);

    $fix = $diagnostic->fix($result);

    expect($result->status->value)->toBe('fail')
        ->and($result->fixable)->toBeTrue()
        ->and($result->context['database'])->toBe($basePath.'/database/database.sqlite')
        ->and($result->links)->toEqual([Link::docs('database', 'configuration')])
        ->and($fix->status->value)->toBe('pass')
        ->and(is_file($basePath.'/database/database.sqlite'))->toBeTrue()
        ->and(is_file($basePath.'/database/changed.sqlite'))->toBeFalse();
});
