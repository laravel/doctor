<p align="center">
<a href="https://github.com/laravel/doctor/actions"><img src="https://github.com/laravel/doctor/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/doctor"><img src="https://img.shields.io/packagist/dt/laravel/doctor" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/doctor"><img src="https://img.shields.io/packagist/v/laravel/doctor" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/doctor"><img src="https://img.shields.io/packagist/l/laravel/doctor" alt="License"></a>
</p>

## Introduction

Laravel Doctor diagnoses common configuration, environment, and infrastructure problems in your application.

Each diagnostic is a single check. It inspects one thing, such as whether Laravel can write to its storage directories, and reports one of several statuses. A diagnostic may also offer a fix when the repair is safe and deterministic. Issues that can't be repaired safely, such as a failed asset build, are reported with remediation steps instead.

## Installation

Install Laravel Doctor using Composer:

```bash
composer require laravel/doctor --dev
```

## Running Doctor

Once installed, the package registers the `doctor` Artisan command:

```bash
php artisan doctor
```

When a failing diagnostic can be fixed, Doctor reports the problem and prompts before applying the fix:

```text
Storage is writable: The application cannot write to every required storage directory.

 Would you like Doctor to make the storage directories writable? (yes/no) [yes]
```

To run available fixes without prompting, pass the `--fix` option:

```bash
php artisan doctor --fix
```

> [!NOTE]
> Fixes are only available with the default CLI output. Doctor rejects `--fix` with JSON or GitHub [output formats](#output-formats) so machine-readable reports never mutate the application.

## Diagnostic Statuses

Every diagnostic returns one of the following statuses. Doctor uses them to render output and to determine the command's exit code:

| Status   | Meaning                                                 | Affects exit code          |
| -------- | ------------------------------------------------------- | -------------------------- |
| `pass`   | The check succeeded and nothing is wrong.               | No                         |
| `notice` | Informational context worth surfacing to the developer. | No                         |
| `warn`   | A potential problem that may not require action.        | Only with `--fail-on=warn` |
| `fail`   | The check found a problem that should be resolved.      | Yes                        |
| `skip`   | The check did not apply to the current environment.     | No                         |
| `error`  | The diagnostic threw an exception while running.        | Yes                        |

By default, the command exits with a failing status when a diagnostic fails or errors. Use `--fail-on=warn` to also fail on warnings, or `--fail-on=never` when Doctor should only report issues.

## Selecting Diagnostics

Diagnostics may be selected or excluded by class name, group, package, or package wildcard. Multiple values may be passed either by repeating the option or by separating values with commas.

```bash
php artisan doctor --only=storage

php artisan doctor --only=StorageIsWritable

php artisan doctor --only=vendor/package

php artisan doctor --except=laravel/*

php artisan doctor --except=laravel/doctor

php artisan doctor --except=storage
```

You may also choose diagnostic groups interactively:

```bash
php artisan doctor --interactive
```

## Configuring Doctor

To configure persistent diagnostic selection and environment modes, publish Doctor's configuration file:

```bash
php artisan vendor:publish --tag=doctor-config
```

The `only` and `except` options accept the same selectors as the command line: diagnostic class names, groups, packages, or package wildcards.

```php
'only' => [
    // 'laravel/doctor',
    // 'vendor/*',
    // 'security',
],

'except' => [
    // \Laravel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored::class,
],
```

Configured `only` selectors act as a persistent allowlist. Passing `--only` at runtime narrows that allowlist further, while configured `except` selectors and `--except` are combined.

## Environment Modes

Some facts about an application cannot be judged on their own. A `sync` queue connection is a sensible default on a developer's machine, but in production it means queued jobs silently run inside web requests. Uncached bootstrap files are correct while code is changing, but in production they mean the deployment never ran `php artisan optimize`. Debug mode is essential locally and a security problem on a public server.

To judge these diagnostics, Doctor resolves the application to one of two modes:

| Mode         | Expectations                                                                                                                                |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `local`      | The application is under active development. Debug mode, `sync` queues, and uncached bootstrap files are normal.                              |
| `production` | The application serves real traffic. Debug mode is a security risk, queues should run asynchronously, and bootstrap files should be cached.   |

The mode changes the verdict, not just the message. Missing bootstrap caches warn in production but pass locally, while present bootstrap caches pass in production but produce a notice locally, since a stale cache is a common reason recent changes do not appear during development.

Doctor recognizes Laravel's conventional `local` and `production` environment names out of the box. If your application uses other names, such as `staging` or `qa`, map each one to a Doctor mode in the configuration file:

```php
'environments' => [
    'local' => 'local',
    'production' => 'production',
    'staging' => 'production',
],
```

Any environment not listed is treated as `production`, so unfamiliar environments are held to the strictest expectations rather than quietly excused. Mapping an environment to an unsupported mode throws an exception, since a misconfigured Doctor cannot be trusted to diagnose anything else.

Custom diagnostics may branch on the current mode:

```php
use Laravel\Doctor\EnvironmentMode;

if (EnvironmentMode::current()->isProduction()) {
    return $this->warn('sync-in-production');
}
```

## Default Diagnostics

Doctor ships with a focused suite of diagnostics that cover common configuration, environment, dependency, database, queue, and storage problems. The default suite includes:

- **Environment** — `.env` presence, `APP_KEY`, PHP version, required and recommended PHP extensions, and timezone.
- **Composer** — `vendor/autoload.php` exists, Composer can dump optimized autoload files, and `composer.lock` is present and fresh.
- **Configuration** — configuration files can be loaded and cached, configuration values required by the active drivers are set, and bootstrap cache files are reported when their presence does not match the current environment.
- **Database** — configured connections are reachable, the SQLite database file exists when needed, and pending migrations are reported.
- **Cache, queue, scheduler, and session** — configured drivers are reachable, active Redis connections are checked, `sync` queues are flagged outside local environments, and registered scheduled tasks are surfaced as a notice.
- **Storage** — configured disks are reachable, required directories are writable, and the `storage:link` symlink exists when expected.
- **Security** — debug mode matches the environment, `.env` is ignored, and Composer dependencies are audited.

## Creating Diagnostics

A diagnostic is a single class that, like an Artisan command, can declare its definition as properties. Extend `Laravel\Doctor\Diagnostic` and implement a `check` method that returns a `DiagnosticResult`. When `$name` or `$group` are not set, Doctor derives defaults from the class name.

To offer a fix, implement the `Laravel\Doctor\Contracts\Fixable` contract. Doctor only attempts a fix on diagnostics that implement it.

The following diagnostic checks whether the application key is set. Because Laravel already provides a safe key generator, it implements `Fixable`:

```php
<?php

namespace App\Doctor\Diagnostics;

use Illuminate\Support\Facades\Artisan;
use Laravel\Doctor\Contracts\Fixable;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\FixResult;
use Laravel\Doctor\Results\Message;

class ApplicationKeyIsSet extends Diagnostic implements Fixable
{
    public string $name = 'App key is set';

    public string $group = 'environment';

    protected function messages(): array
    {
        return [
            'configured' => 'The application key is configured.',

            'missing' => Message::make(
                summary: 'The application key is not configured.',
                remediation: 'Generate an application key with `php artisan key:generate`.',
                confirmation: 'Would you like Doctor to generate an application key using `php artisan key:generate`?',
            ),

            'generated' => 'The application key was generated.',

            'generation-failed' => 'The application key could not be generated.',
        ];
    }

    public function check(): DiagnosticResult
    {
        $key = config('app.key');

        if (is_string($key) && trim($key) !== '') {
            return $this->pass('configured');
        }

        return $this->fail('missing');
    }

    public function fix(DiagnosticResult $result): FixResult
    {
        Artisan::call('key:generate', ['--force' => true]);

        $key = config('app.key');

        if (! is_string($key) || trim($key) === '') {
            return $this->fixFailed('generation-failed')
                ->withDetails(trim(Artisan::output()));
        }

        return $this->fixed('generated');
    }
}
```

Diagnostics should explain what failed and how to recover. Copy lives in the `messages()` registry: a plain string is used as the result's summary, while `Message::make()` may also provide remediation text, documentation links, or a confirmation prompt for fixes.

Statuses are declared where the decision is made. Return `$this->pass()`, `$this->fail()`, `$this->warn()`, `$this->notice()`, `$this->skip()`, or `$this->error()` from `check()`, and `$this->fixed()` or `$this->fixFailed()` from `fix()`. Each result also receives a stable machine-readable code derived from the class and message names, such as `application-key-is-set.missing`.

Summaries and remediation text may interpolate values using `{placeholder}` tokens supplied at the return site:

```php
'unsatisfied' => Message::make(
    summary: 'PHP {version} does not satisfy [{constraint}].',
    remediation: 'Use a PHP binary that satisfies the composer.json PHP constraint.',
),
```

```php
return $this->fail('unsatisfied', [
    'version' => PHP_VERSION,
    'constraint' => $constraint,
]);
```

Reserve tokens for short identifying values such as versions, paths, and counts. Attach unbounded evidence such as exception messages, process output, or lists of failures with `withDetails()` instead.

If the check gathers state the fix will need, store it with `withContext()` on the result. Only implement `Fixable` when the repair is predictable and safe.

## Registering Diagnostics

Applications may register diagnostics through the Doctor facade, typically from a service provider:

```php
use App\Doctor\Diagnostics\ApplicationKeyIsSet;
use Laravel\Doctor\Facades\Doctor;

public function boot(): void
{
    Doctor::diagnostic(ApplicationKeyIsSet::class);
}
```

Packages use the same registration API from their service providers:

```php
use Laravel\Doctor\Facades\Doctor;
use Vendor\Package\Diagnostics\ApplicationKeyIsSet;

public function boot(): void
{
    Doctor::diagnostic(ApplicationKeyIsSet::class);
}
```

Reports show each diagnostic's source next to its name. The source is the Composer package that provides the diagnostic:

```text
[fail] Storage is writable (laravel/doctor): The application cannot write to every required storage directory.
[pass] SQLite database exists (acme/application): The SQLite database file exists.
[warn] Horizon is running (laravel/horizon): Horizon is not currently running.
```

## Running Doctor Programmatically

Doctor may also be run without the Artisan command. The `run` method runs the registered diagnostics and returns a `DiagnosticReport`:

```php
use Laravel\Doctor\Facades\Doctor;

$report = Doctor::run();

if ($report->hasFailures()) {
    // ...
}
```

Programmatic runs honor the `only` and `except` selectors from Doctor's configuration file. To run a different set of diagnostics, pass a `DiagnosticSelection`:

```php
use Laravel\Doctor\DiagnosticSelection;

$report = Doctor::run(DiagnosticSelection::make(only: ['security']));
```

To apply fixes as part of a run, build the run using the `runner` method. The `fixUsing` callback receives each failing diagnostic that offers a fix and determines whether the fix should be applied. When any fixes are applied, Doctor re-runs the diagnostics so the report reflects the repaired application:

```php
$report = Doctor::runner()
    ->fixUsing(fn ($outcome) => true)
    ->run();

$report->fixes();
```

## Output Formats

Doctor renders readable CLI output by default. JSON and GitHub Actions annotations are also available:

```bash
php artisan doctor --format=json

php artisan doctor --format=github
```

## Contributing

Thank you for considering contributing to Laravel Doctor! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/doctor/security/policy) on how to report security vulnerabilities.

## License

Laravel Doctor is open-sourced software licensed under the [MIT license](LICENSE.md).
