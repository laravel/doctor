<?php

namespace Laravel\Doctor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Doctor\Console\DiagnosticMakeCommand;
use Laravel\Doctor\Console\DoctorCommand;
use Laravel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Laravel\Doctor\Diagnostics\ApplicationTimezoneIsValid;
use Laravel\Doctor\Diagnostics\ApplicationTrustedHostIsConfigured;
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
use Laravel\Doctor\Support\ComposerJson;

class DoctorServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/doctor.php', 'doctor');

        $this->app->singleton(ComposerJson::class);

        $this->app->singleton(Doctor::class, function (Application $app): Doctor {
            return (new Doctor($app))->diagnostics([
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
                ApplicationTrustedHostIsConfigured::class,
                ComposerAuditPasses::class,
            ]);
        });
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DiagnosticMakeCommand::class,
                DoctorCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/doctor.php' => config_path('doctor.php'),
            ], 'doctor-config');
        }
    }
}
