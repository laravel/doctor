<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Doctor\Console\DoctorCommand;
use Laravel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Laravel\Doctor\Diagnostics\ApplicationTimezoneIsValid;
use Laravel\Doctor\Diagnostics\BootstrapCacheMatchesEnvironment;
use Laravel\Doctor\Diagnostics\CacheStoreIsReachable;
use Laravel\Doctor\Diagnostics\ComposerAuditPasses;
use Laravel\Doctor\Diagnostics\ComposerAutoloadIsValid;
use Laravel\Doctor\Diagnostics\ComposerLockIsFresh;
use Laravel\Doctor\Diagnostics\ConfigurationCanBeCached;
use Laravel\Doctor\Diagnostics\ConfigurationFilesCanBeLoaded;
use Laravel\Doctor\Diagnostics\DatabaseConnectionIsReachable;
use Laravel\Doctor\Diagnostics\DebugModeMatchesEnvironment;
use Laravel\Doctor\Diagnostics\EnvironmentFileExists;
use Laravel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored;
use Laravel\Doctor\Diagnostics\FilesystemDisksAreReachable;
use Laravel\Doctor\Diagnostics\MigrationsAreUpToDate;
use Laravel\Doctor\Diagnostics\PhpVersionSatisfiesComposerRequirement;
use Laravel\Doctor\Diagnostics\PublicStorageLinkExists;
use Laravel\Doctor\Diagnostics\QueueConnectionIsAsynchronous;
use Laravel\Doctor\Diagnostics\QueueConnectionIsReachable;
use Laravel\Doctor\Diagnostics\RecommendedPhpExtensionsAreLoaded;
use Laravel\Doctor\Diagnostics\RedisConnectionsAreReachable;
use Laravel\Doctor\Diagnostics\RequiredConfigurationValuesAreSet;
use Laravel\Doctor\Diagnostics\RequiredPhpExtensionsAreLoaded;
use Laravel\Doctor\Diagnostics\ScheduledTasksRequireScheduler;
use Laravel\Doctor\Diagnostics\SessionDriverIsReachable;
use Laravel\Doctor\Diagnostics\SqliteDatabaseExists;
use Laravel\Doctor\Diagnostics\StorageIsWritable;
use Laravel\Doctor\DoctorServiceProvider;
use Laravel\Doctor\Facades\Doctor;
use Laravel\Doctor\Renderers\CliRenderer;
use Laravel\Doctor\Results\DiagnosticOutcome;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\FixableDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\FixesSharedStateDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\LinkedDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\PackagedNoticeDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\PassingDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\SharedStateWasFixedDiagnostic;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

it('loads the package service provider', function (): void {
    expect($this->app->getProvider(DoctorServiceProvider::class))->not->toBeNull();
});

it('does not redefine Symfony console verbosity options', function (): void {
    $command = new DoctorCommand;

    expect($command->getDefinition()->hasOption('verbose'))->toBeFalse()
        ->and($command->getDefinition()->hasOption('format'))->toBeTrue();
});

it('loads the default doctor configuration', function (): void {
    expect(config('doctor.only'))->toBe([])
        ->and(config('doctor.except'))->toBe([])
        ->and(config('doctor.environments'))->toBe([
            'local' => 'local',
            'production' => 'production',
        ]);
});

it('does not repeat diagnostics that were fixed', function (): void {
    config(['app.key' => '']);

    $environmentPath = sys_get_temp_dir().'/laravel-doctor-key-'.str_replace('.', '', uniqid('', true));

    mkdir($environmentPath, 0775, true);
    file_put_contents($environmentPath.'/.env', "APP_KEY=\n");

    $this->app->useEnvironmentPath($environmentPath);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'ApplicationKeyIsSet',
        '--fix' => true,
    ], $output);

    $contents = $output->fetch();

    expect(file_get_contents($environmentPath.'/.env'))
        ->toContain('APP_KEY=base64:')
        ->and($contents)->toContain('Re-running diagnostics after applying fixes...')
        ->and($contents)->toContain('App key is set: The application key was generated.')
        ->and($contents)->toContain('All diagnostics passed or were fixed.')
        ->and($exitCode)->toBe(0);
});

it('reruns diagnostics after applying fixes', function (): void {
    Doctor::diagnostic(FixesSharedStateDiagnostic::class);
    Doctor::diagnostic(SharedStateWasFixedDiagnostic::class);

    config(['doctor-testing.shared-state-fixed' => false]);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'testing',
        '--fix' => true,
    ], $output);

    expect($output->fetch())
        ->toContain('Fix')
        ->toContain('Re-running diagnostics after applying fixes...')
        ->toContain('Testing diagnostic fixes shared state')
        ->toContain('The shared state fix')
        ->toContain('ran.')
        ->toContain('All diagnostics passed or were fixed.')
        ->not->toContain('[pass] fix')
        ->not->toContain('The shared state is still broken.')
        ->and($exitCode)->toBe(0);
});

it('formats interactive fix callouts without remediation or confirmation text', function (): void {
    $renderer = new class(new OutputStyle(new ArrayInput([]), new NullOutput)) extends CliRenderer
    {
        public function content(DiagnosticOutcome $outcome): array
        {
            return $this->fixConfirmationCalloutContent($outcome);
        }
    };

    $command = new class extends DoctorCommand
    {
        public function prompt(DiagnosticOutcome $outcome): string
        {
            return $this->confirmationPrompt($outcome);
        }
    };

    $outcome = new DiagnosticOutcome(
        new FixableDiagnostic,
        DiagnosticResult::fail('The diagnostic failed.')
            ->suggest('Apply the testing diagnostic fix.')
            ->confirmUsing('Fix the testing diagnostic?'),
    );

    expect($renderer->content($outcome))->toBe(['The diagnostic failed.'])
        ->and($command->prompt($outcome))->toBe('Fix the testing diagnostic?');
});

it('renders issue callout sources with package footer', function (): void {
    config(['app.key' => '']);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'ApplicationKeyIsSet',
        '--fail-on' => 'fail',
        '--no-interaction' => true,
    ], $output);

    expect($output->fetch())
        ->toContain('The application key is not configured.')
        ->toContain('Suggested fix')
        ->toContain('Generate an application key with')
        ->toContain('php artisan key:generate')
        ->toContain('laravel/doctor')
        ->not->toContain('Confirmation')
        ->not->toContain('Would you like Doctor to generate an application key')
        ->not->toContain('File:')
        ->not->toContain('ApplicationKeyIsSet.php')
        ->not->toContain('laravel/doctor ApplicationKeyIsSet.php');
});

it('renders notice diagnostics without diagnostic source noise', function (): void {
    $basePath = sys_get_temp_dir().'/laravel-doctor-notice-output-'.str_replace('.', '', uniqid('', true));

    mkdir($basePath.'/bootstrap/cache', 0775, true);
    mkdir($basePath.'/storage/framework/views', 0775, true);

    $this->app->setBasePath($basePath);
    $this->app->useStoragePath($basePath.'/storage');
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['view.compiled' => $basePath.'/storage/framework/views']);

    file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
    file_put_contents($basePath.'/storage/framework/views/example.php', '<?php echo "cached";');

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', ['--only' => 'BootstrapCacheMatchesEnvironment'], $output);

    expect($output->fetch())
        ->toContain('Notice')
        ->toContain('Cached bootstrap files detected: events and views.')
        ->toContain('optimize:clear')
        ->not->toContain('Suggested fix')
        ->not->toContain('Notes:')
        ->not->toContain('[notice]')
        ->not->toContain('Bootstrap cache matches environment (laravel/doctor)')
        ->and($exitCode)->toBe(0);
});

it('renders multiple notice diagnostics in a single callout', function (): void {
    $basePath = sys_get_temp_dir().'/laravel-doctor-notices-output-'.str_replace('.', '', uniqid('', true));

    mkdir($basePath.'/bootstrap/cache', 0775, true);
    mkdir($basePath.'/storage/framework/views', 0775, true);

    $this->app->setBasePath($basePath);
    $this->app->useStoragePath($basePath.'/storage');
    $this->app->detectEnvironment(fn (): string => 'local');
    config([
        'queue.default' => 'database',
        'view.compiled' => $basePath.'/storage/framework/views',
    ]);

    file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
    file_put_contents($basePath.'/storage/framework/views/example.php', '<?php echo "cached";');

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => [
            'BootstrapCacheMatchesEnvironment',
            'QueueConnectionIsAsynchronous',
        ],
    ], $output);

    $contents = $output->fetch();

    expect($contents)
        ->toContain('Notices')
        ->toContain('Cached bootstrap files detected: events and views.')
        ->toContain('Queued jobs are processed asynchronously.')
        ->toContain('laravel/doctor')
        ->toContain('optimize:clear')
        ->toContain('queue:work')
        ->not->toContain('Suggested fix')
        ->and(substr_count($contents, 'Notice'))->toBe(1)
        ->and($exitCode)->toBe(0);
});

it('groups notice diagnostics by package source', function (): void {
    Doctor::diagnostic(PackagedNoticeDiagnostic::class);

    $basePath = sys_get_temp_dir().'/laravel-doctor-notice-packages-'.str_replace('.', '', uniqid('', true));

    mkdir($basePath.'/bootstrap/cache', 0775, true);
    mkdir($basePath.'/storage/framework/views', 0775, true);

    $this->app->setBasePath($basePath);
    $this->app->useStoragePath($basePath.'/storage');
    $this->app->detectEnvironment(fn (): string => 'local');
    config([
        'queue.default' => 'database',
        'view.compiled' => $basePath.'/storage/framework/views',
    ]);

    file_put_contents($this->app->getCachedEventsPath(), '<?php return [];');
    file_put_contents($basePath.'/storage/framework/views/example.php', '<?php echo "cached";');

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => [
            'BootstrapCacheMatchesEnvironment',
            'QueueConnectionIsAsynchronous',
            'PackagedNoticeDiagnostic',
        ],
    ], $output);

    $contents = $output->fetch();

    expect($contents)
        ->toContain('Cached bootstrap files detected: events and views.')
        ->toContain('Queued jobs are processed asynchronously.')
        ->toContain('The packaged diagnostic noticed.')
        ->toContain('laravel/doctor')
        ->toContain('vendor/package')
        ->and(substr_count($contents, 'Notice'))->toBe(2)
        ->and($exitCode)->toBe(0);
});

it('renders diagnostic links in cli output', function (): void {
    Doctor::diagnostic(LinkedDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', ['--only' => 'LinkedDiagnostic'], $output);

    expect($output->fetch())
        ->toContain('Testing diagnostic has links')
        ->toContain('The linked diagnostic warned.')
        ->toContain('Detailed link context.')
        ->toContain('Follow the linked documentation.')
        ->not->toContain('[warn]')
        ->toContain('Laravel Docs')
        ->toContain('https://laravel.com/docs')
        ->and($exitCode)->toBe(0);
});

it('renders diagnostic links in json output', function (): void {
    Doctor::diagnostic(LinkedDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'LinkedDiagnostic',
        '--format' => 'json',
    ], $output);

    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['diagnostics'][0]['source'])->toBe([
        'label' => 'laravel/doctor',
        'package' => 'laravel/doctor',
        'file' => 'tests/Fixtures/Diagnostics/LinkedDiagnostic.php',
        'application' => true,
    ])
        ->and($payload['diagnostics'][0]['links'])->toBe(['Laravel Docs' => 'https://laravel.com/docs'])
        ->and($exitCode)->toBe(0);
});

it('renders diagnostic links in github output', function (): void {
    Doctor::diagnostic(LinkedDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'LinkedDiagnostic',
        '--format' => 'github',
    ], $output);

    expect($output->fetch())
        ->toContain('title=Testing diagnostic has links (laravel/doctor)')
        ->toContain('Laravel Docs: https://laravel.com/docs')
        ->and($exitCode)->toBe(0);
});

it('rejects fixes with json output', function (): void {
    $this->artisan('doctor --only=ApplicationKeyIsSet --fix --format=json')
        ->expectsOutputToContain('The --fix option may only be used with --format=cli.')
        ->doesntExpectOutputToContain('"diagnostics"')
        ->assertExitCode(1);
});

it('rejects fixes with github output', function (): void {
    $this->artisan('doctor --only=ApplicationKeyIsSet --fix --format=github')
        ->expectsOutputToContain('The --fix option may only be used with --format=cli.')
        ->doesntExpectOutputToContain('::')
        ->assertExitCode(1);
});

it('validates fail-on before running diagnostics', function (): void {
    Doctor::diagnostic(LinkedDiagnostic::class);

    $this->artisan('doctor --only=LinkedDiagnostic --fail-on=broken')
        ->expectsOutputToContain('The --fail-on option must be one of: fail, warn, never.')
        ->doesntExpectOutputToContain('The linked diagnostic warned.')
        ->assertExitCode(1);
});

it('applies configured only selectors', function (): void {
    config(['doctor.only' => ['ApplicationKeyIsSet']]);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--format' => 'json',
        '--fail-on' => 'never',
    ], $output);

    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['diagnostics'])->toHaveCount(1)
        ->and($payload['diagnostics'][0]['class'])->toBe(ApplicationKeyIsSet::class)
        ->and($exitCode)->toBe(0);
});

it('applies configured except selectors', function (): void {
    config([
        'doctor.only' => ['ApplicationKeyIsSet', 'EnvironmentFileExists'],
        'doctor.except' => ['ApplicationKeyIsSet'],
    ]);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--format' => 'json',
        '--fail-on' => 'never',
    ], $output);

    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['diagnostics'])->toHaveCount(1)
        ->and($payload['diagnostics'][0]['class'])->toBe(EnvironmentFileExists::class)
        ->and($exitCode)->toBe(0);
});

it('narrows configured only selectors with command only selectors', function (): void {
    config(['doctor.only' => ['security']]);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'EnvironmentFileExists',
        '--format' => 'json',
        '--fail-on' => 'never',
    ], $output);

    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['diagnostics'])->toBe([])
        ->and($exitCode)->toBe(0);
});

it('binds the doctor service and facade', function (): void {
    Doctor::diagnostic(PassingDiagnostic::class);

    expect($this->app->make(Laravel\Doctor\Doctor::class)->registered())
        ->toBe([
            EnvironmentFileExists::class,
            ApplicationKeyIsSet::class,
            PhpVersionSatisfiesComposerRequirement::class,
            RequiredPhpExtensionsAreLoaded::class,
            RecommendedPhpExtensionsAreLoaded::class,
            ApplicationTimezoneIsValid::class,
            ComposerAutoloadIsValid::class,
            ComposerLockIsFresh::class,
            ConfigurationFilesCanBeLoaded::class,
            ConfigurationCanBeCached::class,
            RequiredConfigurationValuesAreSet::class,
            BootstrapCacheMatchesEnvironment::class,
            DatabaseConnectionIsReachable::class,
            SqliteDatabaseExists::class,
            MigrationsAreUpToDate::class,
            CacheStoreIsReachable::class,
            RedisConnectionsAreReachable::class,
            QueueConnectionIsReachable::class,
            QueueConnectionIsAsynchronous::class,
            SessionDriverIsReachable::class,
            ScheduledTasksRequireScheduler::class,
            PublicStorageLinkExists::class,
            FilesystemDisksAreReachable::class,
            StorageIsWritable::class,
            DebugModeMatchesEnvironment::class,
            EnvironmentFileIsGitIgnored::class,
            ComposerAuditPasses::class,
            PassingDiagnostic::class,
        ]);
});

it('registers the default diagnostics', function (): void {
    expect($this->app->make(Laravel\Doctor\Doctor::class)->registered())
        ->toBe([
            EnvironmentFileExists::class,
            ApplicationKeyIsSet::class,
            PhpVersionSatisfiesComposerRequirement::class,
            RequiredPhpExtensionsAreLoaded::class,
            RecommendedPhpExtensionsAreLoaded::class,
            ApplicationTimezoneIsValid::class,
            ComposerAutoloadIsValid::class,
            ComposerLockIsFresh::class,
            ConfigurationFilesCanBeLoaded::class,
            ConfigurationCanBeCached::class,
            RequiredConfigurationValuesAreSet::class,
            BootstrapCacheMatchesEnvironment::class,
            DatabaseConnectionIsReachable::class,
            SqliteDatabaseExists::class,
            MigrationsAreUpToDate::class,
            CacheStoreIsReachable::class,
            RedisConnectionsAreReachable::class,
            QueueConnectionIsReachable::class,
            QueueConnectionIsAsynchronous::class,
            SessionDriverIsReachable::class,
            ScheduledTasksRequireScheduler::class,
            PublicStorageLinkExists::class,
            FilesystemDisksAreReachable::class,
            StorageIsWritable::class,
            DebugModeMatchesEnvironment::class,
            EnvironmentFileIsGitIgnored::class,
            ComposerAuditPasses::class,
        ]);
});
