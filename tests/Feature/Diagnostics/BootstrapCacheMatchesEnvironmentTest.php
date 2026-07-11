<?php

use Laravel\Doctor\Diagnostics\BootstrapCacheMatchesEnvironment;

function doctor_cached_bootstrap_base_path(): string
{
    $basePath = sys_get_temp_dir().'/laravel-doctor-cached-bootstrap-'.str_replace('.', '', uniqid('', true));

    mkdir($basePath.'/bootstrap/cache', 0775, true);
    mkdir($basePath.'/storage/framework/views', 0775, true);

    return $basePath;
}

it('passes when bootstrap files are not cached locally', function (): void {
    $basePath = doctor_cached_bootstrap_base_path();

    $this->app->setBasePath($basePath);
    $this->app->useStoragePath($basePath.'/storage');
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['view.compiled' => $basePath.'/storage/framework/views']);

    $result = (new BootstrapCacheMatchesEnvironment)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->summary)->toBe('The application bootstrap files are not cached.');
});

it('warns when bootstrap files are not cached outside local environments', function (): void {
    $basePath = doctor_cached_bootstrap_base_path();

    $this->app->setBasePath($basePath);
    $this->app->useStoragePath($basePath.'/storage');
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['view.compiled' => $basePath.'/storage/framework/views']);

    $result = (new BootstrapCacheMatchesEnvironment)->check();

    expect($result->status->value)->toBe('warn')
        ->and($result->summary)->toBe('The application bootstrap files are not cached.');
});

it('notices when bootstrap files are cached locally', function (): void {
    $basePath = doctor_cached_bootstrap_base_path();

    $this->app->setBasePath($basePath);
    $this->app->useStoragePath($basePath.'/storage');
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['view.compiled' => $basePath.'/storage/framework/views']);

    file_put_contents($this->app->getCachedConfigPath(), '<?php return [];');
    file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
    file_put_contents($this->app->getCachedRoutesPath(), '<?php return [];');
    file_put_contents($basePath.'/storage/framework/views/example.php', '<?php echo "cached";');

    $result = (new BootstrapCacheMatchesEnvironment)->check();

    expect($result->status->value)->toBe('notice')
        ->and($result->summary)->toBe('Cached bootstrap files detected: config, events, routes and views.')
        ->and($result->details)->toBeNull()
        ->and($result->remediation)->toBe('If recent changes are not appearing, run `php artisan optimize:clear`.');
});

it('notices when one bootstrap file is cached locally', function (): void {
    $basePath = doctor_cached_bootstrap_base_path();

    $this->app->setBasePath($basePath);
    $this->app->useStoragePath($basePath.'/storage');
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['view.compiled' => $basePath.'/storage/framework/views']);

    file_put_contents($this->app->getCachedConfigPath(), '<?php return [];');

    $result = (new BootstrapCacheMatchesEnvironment)->check();

    expect($result->status->value)->toBe('notice')
        ->and($result->summary)->toBe('Cached bootstrap file detected: config.')
        ->and($result->details)->toBeNull()
        ->and($result->remediation)->toBe('If recent changes are not appearing, run `php artisan optimize:clear`.');
});

it('passes when bootstrap files are cached outside local environments', function (): void {
    $basePath = doctor_cached_bootstrap_base_path();

    $this->app->setBasePath($basePath);
    $this->app->useStoragePath($basePath.'/storage');
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['view.compiled' => $basePath.'/storage/framework/views']);

    file_put_contents($this->app->getCachedConfigPath(), '<?php return [];');

    $result = (new BootstrapCacheMatchesEnvironment)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->summary)->toBe('The application bootstrap files are cached.');
});
