<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Laravel\Doctor\Diagnostics\ApplicationTrustedHostIsConfigured;
use Laravel\Doctor\Results\Status;

function reenableTrustedHosts(): void
{
    TrustHosts::flushState();

    $kernel = app(HttpKernel::class);
    $kernel->setGlobalMiddleware(array_values(array_filter(
        $kernel->getGlobalMiddleware(),
        fn (mixed $middleware): bool => ! (is_string($middleware) && is_a($middleware, TrustHosts::class, true)),
    )));
}

function enableTrustedHosts(array|callable|null $hosts = null, bool $subdomains = true, string $middleware = TrustHosts::class): void
{
    if (! is_null($hosts)) {
        TrustHosts::at($hosts, $subdomains);
    }

    app(HttpKernel::class)->prependMiddleware($middleware);
}

function addSignedRoute(): void
{
    $router = app('router');
    $route = new Route(['GET'], '/test', ['uses' => 'TestController@test']);
    $route->middleware(ValidateSignature::class);
    $router->getRoutes()->add($route);
}

beforeEach(function (): void {
    reenableTrustedHosts();
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
    enableTrustedHosts(['example.com']);

    // Access password broker to trigger binding
    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Pass)
        ->and($result->summary)->toBe('Trusted host is configured for security-sensitive features.');
});

it('warns when trusted host is not configured in local environment with password reset', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    reenableTrustedHosts();

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Warn)
        ->and($result->summary)->toBe('Trusted host is not configured while using password reset, email verification, or temporary signed URLs.');
});

it('fails when trusted host is not configured in production with password reset', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    reenableTrustedHosts();

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->summary)->toBe('Trusted host must be configured in production while using password reset, email verification, or temporary signed URLs.');
});

it('fails when trusted host is not configured in production with email verification', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    reenableTrustedHosts();
    config([
        'auth.providers.users.model' => User::class,
    ]);

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail);
});

it('fails when trusted host is not configured in production with signed URLs', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    reenableTrustedHosts();

    addSignedRoute();

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail);
});

it('passes when trusted host is configured as callable', function (): void {
    enableTrustedHosts(fn () => ['example.com', 'another.com']);

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Pass)
        ->and($result->summary)->toBe('Trusted host is configured for security-sensitive features.');
});

it('detects signed middleware in routes', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    reenableTrustedHosts();

    addSignedRoute();

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->summary)->toContain('must be configured');
});

it('passes when trust hosts middleware is enabled without explicit hosts', function (): void {
    config(['app.url' => 'https://example.com']);
    enableTrustedHosts();

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Pass);
});

it('passes when a subclass of trust hosts middleware is registered', function (): void {
    enableTrustedHosts(['example.com'], middleware: CustomTrustHosts::class);

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Pass);
});

it('fails when hosts are set but the middleware is not registered', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    TrustHosts::at(['example.com']);

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail);
});

it('fails when middleware is enabled but no host pattern can be resolved', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['app.url' => '']);
    enableTrustedHosts();

    app('auth.password');

    $result = (new ApplicationTrustedHostIsConfigured)->check();

    expect($result->status)->toBe(Status::Fail);
});

class CustomTrustHosts extends TrustHosts {}
