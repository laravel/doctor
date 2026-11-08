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
use Laravel\Doctor\Results\DiagnosticFixOutcome;
use Laravel\Doctor\Results\DiagnosticOutcome;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\FixResult;
use Laravel\Doctor\Results\Status;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\FixableDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\FixesSharedStateDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\LinkedDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\LocalOnlyFixableDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\OptionFixableDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\PackagedNoticeDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\PassingDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\SharedStateWasFixedDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\WarningDiagnostic;
use Laravel\Prompts\Elements\Element;
use Laravel\Prompts\Prompt;
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
        ->and($command->getDefinition()->hasOption('format'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('bail'))->toBeTrue();
});

it('loads the default doctor configuration', function (): void {
    expect(config('doctor.only'))->toBe([])
        ->and(config('doctor.except'))->toBe([])
        ->and(config('doctor.environments'))->toBe([
            'local' => ['local'],
            'production' => ['production', 'staging'],
        ]);
});

it('allows Laravel to terminate the command when the application timezone is invalid', function (): void {
    config(['app.timezone' => 'Invalid/Timezone']);

    $input = new ArrayInput([
        'command' => 'doctor',
        '--only' => ['ApplicationTimezoneIsValid'],
        '--no-interaction' => true,
    ]);
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
    $kernel = $this->app->make(Kernel::class);

    $exitCode = $kernel->handle($input, $output);

    expect($output->fetch())
        ->toContain('Invalid/Timezone')
        ->toContain('valid PHP timezone.')
        ->and($exitCode)->toBe(1)
        ->and(config('app.timezone'))->toBe(date_default_timezone_get());

    $kernel->terminate($input, $exitCode);
});

it('does not repeat diagnostics that were fixed', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
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
        ->toContain('base64:')
        ->and($contents)->toContain('Re-running diagnostics after applying fixes...')
        ->and($contents)->toMatch('/Re-running diagnostics after applying fixes\.\.\..*Results.*App key is set/s')
        ->and($contents)->toContain('App key is set')
        ->and($contents)->toContain('The application key was generated.')
        ->and($contents)->not->toContain('App key is set: The application key was generated.')
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
        ->not->toContain('Fix')
        ->toContain('Re-running diagnostics after applying fixes...')
        ->toContain('Testing diagnostic fixes shared state')
        ->toContain('The shared state fix')
        ->toContain('ran.')
        ->toContain('All diagnostics passed or were fixed.')
        ->not->toContain('[pass] fix')
        ->not->toContain('The shared state is still broken.')
        ->and($exitCode)->toBe(0);
});

it('merges a failed fix into the diagnostic issue callout', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['app.key' => '']);

    $environmentPath = sys_get_temp_dir().'/laravel-doctor-key-fail-'.str_replace('.', '', uniqid('', true));

    mkdir($environmentPath, 0775, true);

    $this->app->useEnvironmentPath($environmentPath);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'ApplicationKeyIsSet',
        '--fix' => true,
    ], $output);

    $contents = $output->fetch();

    expect($contents)
        ->toContain('The application key is not configured.')
        ->toContain('Fix failed')
        ->toContain('The application key could not be generated.')
        ->toContain('could not be updated')
        ->not->toContain('Suggested fix')
        ->toContain('Doctor found failing diagnostics.')
        ->and(substr_count($contents, 'The application key could not be generated.'))->toBe(1)
        ->and($exitCode)->toBe(1);
});

it('escalates warning issue callouts to errors when their fix failed', function (): void {
    $renderer = new class(new OutputStyle(new ArrayInput([]), new NullOutput)) extends CliRenderer
    {
        public function type(DiagnosticOutcome $outcome, ?DiagnosticFixOutcome $failedFix): string
        {
            return $this->issueCalloutType($outcome, $failedFix);
        }
    };

    $outcome = new DiagnosticOutcome(
        new FixableDiagnostic,
        DiagnosticResult::warn('The diagnostic partially improved.'),
    );

    $failedFix = new DiagnosticFixOutcome(
        new FixableDiagnostic,
        new FixResult(Status::Fail, 'The fix failed.'),
    );

    expect($renderer->type($outcome, null))->toBe('warning')
        ->and($renderer->type($outcome, $failedFix))->toBe('error');
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

    $outcomeWithoutConfirmation = new DiagnosticOutcome(
        new FixableDiagnostic,
        DiagnosticResult::fail('The diagnostic failed.')->fixable(),
    );

    expect($renderer->content($outcome))->toBe(['The diagnostic failed.'])
        ->and($command->prompt($outcome))->toBe('Fix the testing diagnostic?')
        ->and($command->prompt($outcomeWithoutConfirmation))->toBe('Fix "Testing diagnostic is fixable"?');
});

it('shows a spinner while a fix is applied', function (): void {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $command = new class extends DoctorCommand
    {
        public function observer(): Closure
        {
            return $this->fixObserver();
        }
    };

    $style = new OutputStyle(new ArrayInput([]), $output);

    $command->setLaravel($this->app);
    $command->setInput(new ArrayInput([]));
    $command->setOutput($style);

    Prompt::setOutput($style);

    $outcome = new DiagnosticOutcome(new FixableDiagnostic, (new FixableDiagnostic)->check());
    $applied = false;

    $command->observer()($outcome, function () use (&$applied): DiagnosticFixOutcome {
        $applied = true;

        return new DiagnosticFixOutcome(new FixableDiagnostic, FixResult::pass('The testing diagnostic was fixed.'));
    });

    expect($applied)->toBeTrue()
        ->and($output->fetch())->toContain('Fixing "Testing diagnostic is fixable"...');
});

it('restores the prompts output before the fix spinner erases itself', function (): void {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
    $nestedOutput = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $command = new class extends DoctorCommand
    {
        public function observer(): Closure
        {
            return $this->fixObserver();
        }
    };

    $style = new OutputStyle(new ArrayInput([]), $output);

    $command->setLaravel($this->app);
    $command->setInput(new ArrayInput([]));
    $command->setOutput($style);

    Prompt::setOutput($style);

    $outcome = new DiagnosticOutcome(new FixableDiagnostic, (new FixableDiagnostic)->check());

    $command->observer()($outcome, function () use ($nestedOutput): DiagnosticFixOutcome {
        // Nested Artisan commands re-bind the prompts output like this.
        Prompt::setOutput($nestedOutput);

        return new DiagnosticFixOutcome(new FixableDiagnostic, FixResult::pass('The testing diagnostic was fixed.'));
    });

    expect($nestedOutput->fetch())->toBe('');
});

it('renders issue details under a heading distinct from the suggested fix', function (): void {
    $renderer = new class(new OutputStyle(new ArrayInput([]), new NullOutput)) extends CliRenderer
    {
        public function content(DiagnosticOutcome $outcome): array
        {
            return $this->diagnosticCalloutContent($outcome);
        }
    };

    $outcome = new DiagnosticOutcome(
        new FixableDiagnostic,
        DiagnosticResult::fail('The diagnostic failed.')
            ->withDetails('- The lock file is not up to date with the latest changes in composer.json.')
            ->suggest('Run `composer update --lock`.'),
    );

    expect($renderer->content($outcome))->toEqual([
        'The diagnostic failed.',
        Element::heading('Details'),
        '- The lock file is not up to date with the latest changes in composer.json.',
        Element::heading('Suggested fix'),
        'Run `composer update --lock`.',
    ]);
});

it('renders related links beneath the suggested fix as prompt links with shortened URLs', function (): void {
    $renderer = new class(new OutputStyle(new ArrayInput([]), new NullOutput)) extends CliRenderer
    {
        public function content(DiagnosticOutcome $outcome): array
        {
            return $this->diagnosticCalloutContent($outcome);
        }

        /**
         * @param  list<DiagnosticOutcome>  $outcomes
         */
        public function notices(array $outcomes): array
        {
            return $this->noticesCalloutContent($outcomes);
        }
    };

    $outcome = new DiagnosticOutcome(new LinkedDiagnostic, (new LinkedDiagnostic)->check());

    expect($renderer->content($outcome))->toEqual([
        'The linked diagnostic warned.',
        Element::heading('Details'),
        'Detailed link context.',
        Element::heading('Suggested fix'),
        'Follow the linked documentation.',
        Element::link(
            'https://laravel.com/docs/queues#connections-vs-queues',
            'laravel.com/docs/queues',
        ),
        Element::link('https://example.com/links', 'example.com/links'),
    ])->and($renderer->notices([$outcome]))->toEqual([
        'The linked diagnostic warned. Detailed link context. Follow the linked documentation.',
        Element::link(
            'https://laravel.com/docs/queues#connections-vs-queues',
            'laravel.com/docs/queues',
        ),
        Element::link('https://example.com/links', 'example.com/links'),
    ]);
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
        ->toContain('Results')
        ->toContain('The application key is not configured.')
        ->toContain('Suggested fix')
        ->toContain('Generate an application key with')
        ->toContain('php artisan key:generate')
        ->toContain('https://laravel.com/docs/encryption')
        ->toContain('laravel/doctor')
        ->not->toContain('docs:')
        ->not->toContain('Encryption documentation')
        ->not->toContain('Links')
        ->not->toContain('Confirmation')
        ->not->toContain('Generate an application key using `php artisan key:generate`?')
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
        ->toContain('https://laravel.com/docs/deployment#optimization')
        ->not->toContain('Suggested fix')
        ->not->toContain('docs:')
        ->not->toContain('Configuration documentation')
        ->not->toContain('Links')
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
        ->toMatch('/Cached bootstrap files detected.*optimize:clear.*https:\/\/laravel\.com\/docs\/deployment#optimization.*Queued jobs are processed asynchronously.*queue:work.*https:\/\/laravel\.com\/docs\/queues#running-the-queue-worker/s')
        ->not->toContain('Suggested fix')
        ->not->toContain('docs:')
        ->not->toContain('Configuration documentation')
        ->not->toContain('Queues documentation')
        ->not->toContain('Links')
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
        ->toContain('https://laravel.com/docs/queues#connections-vs-queues')
        ->toContain('https://example.com/links')
        ->not->toContain('docs:')
        ->not->toContain('Queues documentation')
        ->not->toContain('Related links guide')
        ->not->toContain('Links')
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
        ->and($payload['diagnostics'][0]['fixable'])->toBeFalse()
        ->and($payload['diagnostics'][0]['links'])->toBe([
            'Queues documentation' => 'https://laravel.com/docs/queues.md',
            'Related links guide' => 'https://example.com/links',
        ])
        ->and($exitCode)->toBe(0);
});

it('bails after the first failure from the command', function (): void {
    Doctor::diagnostic(WarningDiagnostic::class);
    Doctor::diagnostic(FixableDiagnostic::class);
    Doctor::diagnostic(PassingDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => [
            'WarningDiagnostic',
            'FixableDiagnostic',
            'PassingDiagnostic',
        ],
        '--bail' => true,
        '--format' => 'json',
    ], $output);

    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['diagnostics'], 'class'))->toBe([
        WarningDiagnostic::class,
        FixableDiagnostic::class,
    ])->and($exitCode)->toBe(1);
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
        ->toContain('https://laravel.com/docs/queues#connections-vs-queues')
        ->toContain('https://example.com/links')
        ->not->toContain('docs:')
        ->not->toContain('Queues documentation')
        ->not->toContain('Related links guide')
        ->and($exitCode)->toBe(0);
});

it('rejects fixes with json output', function (): void {
    $this->artisan('doctor --only=ApplicationKeyIsSet --fix --format=json')
        ->expectsOutputToContain('The --fix option may only be used with the cli or agent output formats.')
        ->doesntExpectOutputToContain('"diagnostics"')
        ->assertExitCode(1);
});

it('rejects fixes with github output', function (): void {
    $this->artisan('doctor --only=ApplicationKeyIsSet --fix --format=github')
        ->expectsOutputToContain('The --fix option may only be used with the cli or agent output formats.')
        ->doesntExpectOutputToContain('::')
        ->assertExitCode(1);
});

it('renders agent output when an agent is detected', function (): void {
    Doctor::diagnostic(LinkedDiagnostic::class);

    putenv('AI_AGENT=laravel-doctor-test');

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'LinkedDiagnostic',
    ], $output);

    $contents = trim($output->fetch());
    $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    expect($contents)->not->toContain("\n")
        ->and($payload['tool'])->toBe('doctor')
        ->and($payload['result'])->toBe('passed')
        ->and($payload['diagnostics'])->toBe(1)
        ->and($payload['warnings'])->toBe(1)
        ->and($payload['issues'])->toBe([[
            'name' => 'Testing diagnostic has links',
            'status' => 'warn',
            'summary' => 'The linked diagnostic warned.',
            'details' => 'Detailed link context.',
            'fix' => 'Follow the linked documentation.',
            'links' => [
                'Queues documentation' => 'https://laravel.com/docs/queues.md',
                'Related links guide' => 'https://example.com/links',
            ],
        ]])
        ->and($payload)->not->toHaveKey('fixes')
        ->and($exitCode)->toBe(0);
});

it('prefers an explicit format over agent detection', function (): void {
    Doctor::diagnostic(LinkedDiagnostic::class);

    putenv('AI_AGENT=laravel-doctor-test');

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'LinkedDiagnostic',
        '--format' => 'cli',
    ], $output);

    expect($output->fetch())
        ->toContain('Testing diagnostic has links')
        ->toContain('Follow the linked documentation.')
        ->and($exitCode)->toBe(0);
});

it('keeps cli output for non-interactive runs outside agents', function (): void {
    Doctor::diagnostic(LinkedDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'LinkedDiagnostic',
        '--no-interaction' => true,
    ], $output);

    expect($output->fetch())
        ->toContain('Testing diagnostic has links')
        ->toContain('Follow the linked documentation.')
        ->and($exitCode)->toBe(0);
});

it('renders minimal agent output when every diagnostic passes', function (): void {
    Doctor::diagnostic(PassingDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'PassingDiagnostic',
        '--format' => 'agent',
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toBe([
        'tool' => 'doctor',
        'result' => 'passed',
        'diagnostics' => 1,
        'failed' => 0,
        'warnings' => 0,
        'notices' => 0,
        'passed' => 1,
        'skipped' => 0,
    ])->and($exitCode)->toBe(0);
});

it('marks fixable issues in agent output', function (): void {
    Doctor::diagnostic(FixableDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'FixableDiagnostic',
        '--format' => 'agent',
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['result'])->toBe('failed')
        ->and($payload['issues'])->toBe([[
            'name' => 'Testing diagnostic is fixable',
            'status' => 'fail',
            'summary' => 'The diagnostic failed.',
            'fix' => 'Apply the testing diagnostic fix.',
            'fixable' => true,
        ]])
        ->and($payload)->not->toHaveKey('fixes')
        ->and($exitCode)->toBe(1);
});

it('does not expose or apply local-only fixes in production agent output', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    Doctor::diagnostic(LocalOnlyFixableDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'LocalOnlyFixableDiagnostic',
        '--format' => 'agent',
        '--fix' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['issues'])->toBe([[
        'name' => 'Testing diagnostic is fixable locally',
        'status' => 'fail',
        'summary' => 'The local diagnostic failed.',
        'fix' => 'Apply the local testing diagnostic fix.',
    ]])
        ->and($payload)->not->toHaveKey('fixes')
        ->and($exitCode)->toBe(1);
});

it('applies fixes and reruns for agent output when requested', function (): void {
    Doctor::diagnostic(FixesSharedStateDiagnostic::class);

    config(['doctor-testing.shared-state-fixed' => false]);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'FixesSharedStateDiagnostic',
        '--format' => 'agent',
        '--fix' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['result'])->toBe('passed')
        ->and($payload['passed'])->toBe(1)
        ->and($payload['fixes'])->toBe([[
            'name' => 'Testing diagnostic fixes shared state',
            'status' => 'pass',
            'summary' => 'The shared state fix ran.',
        ]])
        ->and($payload)->not->toHaveKey('issues')
        ->and($exitCode)->toBe(0);
});

it('applies the fix option selected interactively', function (): void {
    Doctor::diagnostic(OptionFixableDiagnostic::class);

    $this->artisan('doctor --only=OptionFixableDiagnostic')
        ->expectsChoice('Which option should fix the diagnostic?', 'a', [
            'a' => 'Option A',
            'b' => 'Option B',
            '__skip__' => 'Skip — leave unfixed',
        ])
        ->expectsOutputToContain('The option diagnostic was fixed with [a].')
        ->expectsOutputToContain('All diagnostics passed or were fixed.')
        ->assertExitCode(0);

    expect(config('doctor-testing.option-fixed'))->toBe('a');
});

it('skips the fix when the interactive choice declines it', function (): void {
    Doctor::diagnostic(OptionFixableDiagnostic::class);

    $this->artisan('doctor --only=OptionFixableDiagnostic')
        ->expectsChoice('Which option should fix the diagnostic?', '__skip__', [
            'a' => 'Option A',
            'b' => 'Option B',
            '__skip__' => 'Skip — leave unfixed',
        ])
        ->expectsOutputToContain('The option diagnostic failed.')
        ->assertExitCode(1);

    expect(config('doctor-testing.option-fixed'))->toBeNull();
});

it('keeps the unreachable cache store when its decline entry is chosen', function (): void {
    // Prompt interception requires the app to stay in the testing environment,
    // so map it to local mode instead of re-detecting the environment.
    config(['doctor.environments' => ['local' => ['testing']]]);

    $path = sys_get_temp_dir().'/laravel-doctor-decline-'.str_replace('.', '', uniqid('', true));

    mkdir($path, 0775, true);

    config(['cache' => [
        'default' => 'redis',
        'prefix' => '',
        'stores' => [
            'redis' => ['driver' => 'broken-driver'],
            'file' => ['driver' => 'file', 'path' => $path],
        ],
    ]]);

    $this->artisan('doctor --only=CacheStoreIsReachable')
        ->expectsChoice('Which cache store should the application use?', '__skip__', [
            'file' => 'File',
            '__skip__' => 'Keep Redis (repair it manually)',
        ])
        ->expectsOutputToContain('Check CACHE_STORE')
        ->expectsOutputToContain('Doctor found failing diagnostics.')
        ->assertExitCode(1);

    expect(config('cache.default'))->toBe('redis');
});

it('leaves fixes that require a choice unfixed under --fix', function (): void {
    Doctor::diagnostic(OptionFixableDiagnostic::class);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => 'OptionFixableDiagnostic',
        '--fix' => true,
        '--no-interaction' => true,
    ], $output);

    expect($output->fetch())
        ->toContain('The option diagnostic failed.')
        ->toContain('Apply one of the diagnostic fix options.')
        ->and(config('doctor-testing.option-fixed'))->toBeNull()
        ->and($exitCode)->toBe(1);
});

it('exposes fix options instead of the fixable flag in agent output', function (): void {
    Doctor::diagnostic(OptionFixableDiagnostic::class);
    Doctor::diagnostic(FixesSharedStateDiagnostic::class);

    config(['doctor-testing.shared-state-fixed' => false]);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

    $exitCode = $this->app->make(Kernel::class)->call('doctor', [
        '--only' => ['OptionFixableDiagnostic', 'FixesSharedStateDiagnostic'],
        '--format' => 'agent',
        '--fix' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['issues'])->toBe([[
        'name' => 'Testing diagnostic offers fix options',
        'status' => 'fail',
        'summary' => 'The option diagnostic failed.',
        'fix' => 'Apply one of the diagnostic fix options.',
        'options' => ['a' => 'Option A', 'b' => 'Option B'],
    ]])
        ->and($payload['fixes'])->toBe([[
            'name' => 'Testing diagnostic fixes shared state',
            'status' => 'pass',
            'summary' => 'The shared state fix ran.',
        ]])
        ->and(config('doctor-testing.option-fixed'))->toBeNull()
        ->and($exitCode)->toBe(1);
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
