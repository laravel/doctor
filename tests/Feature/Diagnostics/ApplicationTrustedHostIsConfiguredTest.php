<?php

use App\Models\User;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Laravel\Doctor\Diagnostics\ApplicationTrustedHostIsConfigured;
use Laravel\Doctor\Results\Status;
use ReflectionClass;

function resetTrustedHosts(): void
{
    $reflection = new ReflectionClass(TrustHosts::class);
    $property = $reflection->getProperty('alwaysTrust');
    $property->setAccessible(true);
    $property->setValue(null);

    $subdomains = $reflection->getProperty('subdomains');
    $subdomains->setAccessible(true);
    $subdomains->setValue(null);
}

function setTrustedHosts(array|callable $hosts, bool $subdomains = true): void
{
    TrustHosts::at($hosts, $subdomains);
}

function addSignedRoute(): void
{
    $router = app('router');
    $route = new Route(['GET'], '/test', ['uses' => 'TestController@test']);
    $route->middleware(ValidateSignature::class);
    $router->getRoutes()->add($route);
}

beforeEach(function (): void {
    resetTrustedHosts();
});

it('skips when no security-sensitive features are enabled', function (): void {
    // Explicitly clear security-sensitive features
    config([
        'auth.passwords' => [],
        'auth.providers.users.model' => null,
    ]);

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Skip)
        ->and($result->summary)->toBe('Trusted host configuration is not required as no security-sensitive features are enabled.');
});

it('passes when trusted host is configured and password reset is enabled', function (): void {
    setTrustedHosts(['example.com']);

    // Access password broker to trigger binding
    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Pass)
        ->and($result->summary)->toBe('Trusted host is configured for security-sensitive features.');
});

it('warns when trusted host is not configured in local environment with password reset', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    resetTrustedHosts();

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Warn)
        ->and($result->summary)->toBe('Trusted host is not configured while using password reset, email verification, or temporary signed URLs.');
});

it('fails when trusted host is not configured in production with password reset', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    resetTrustedHosts();

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->summary)->toBe('Trusted host must be configured in production while using password reset, email verification, or temporary signed URLs.');
});

it('fails when trusted host is not configured in production with email verification', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    resetTrustedHosts();
    config([
        'auth.providers.users.model' => User::class,
    ]);

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail);
});

it('fails when trusted host is not configured in production with signed URLs', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    resetTrustedHosts();

    addSignedRoute();

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail);
});

it('passes when trusted host is configured as callable', function (): void {
    setTrustedHosts(fn () => ['example.com', 'another.com']);

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Pass)
        ->and($result->summary)->toBe('Trusted host is configured for security-sensitive features.');
});

it('detects signed middleware in routes', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    resetTrustedHosts();

    addSignedRoute();

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->summary)->toContain('must be configured');
});
