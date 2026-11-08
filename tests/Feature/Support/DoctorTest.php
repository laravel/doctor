<?php

use Illuminate\Console\Command;
use Laravel\Doctor\Contracts\Fixable;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Laravel\Doctor\DiagnosticSource;
use Laravel\Doctor\Doctor;
use Laravel\Doctor\EnvironmentMode;
use Laravel\Doctor\Results\DiagnosticFixOutcome;
use Laravel\Doctor\Results\DiagnosticOutcome;
use Laravel\Doctor\Results\DiagnosticReport;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\FixResult;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\DatabaseDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\DefaultMetadataDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\FixableDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\FixesSharedStateDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\LocalOnlyFixableDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\NoticeDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\OptionFixableDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\PackagedDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\PassingDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\SharedStateWasFixedDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\ThrowingDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\ThrowingFixableDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\ThrowingFixDiagnostic;
use Laravel\Doctor\Tests\Fixtures\Diagnostics\WarningDiagnostic;

it('registers diagnostics', function (): void {
    $doctor = new Doctor($this->app);

    $doctor->diagnostic(PassingDiagnostic::class);

    expect($doctor->registered())->toBe([PassingDiagnostic::class])
        ->and($doctor->hasDiagnostics())->toBeTrue();
});

it('rejects classes that are not diagnostics', function (): void {
    (new Doctor($this->app))->diagnostic(stdClass::class);
})->throws(InvalidArgumentException::class);

it('resolves diagnostic sources from the class file', function (): void {
    expect(DiagnosticSource::resolve(PassingDiagnostic::class)->package)->toBe('laravel/doctor')
        ->and(DiagnosticSource::resolve(Command::class)->package)->toBe('laravel/framework')
        ->and(DiagnosticSource::resolve(PassingDiagnostic::class)->relativeFile)->toBe('tests/Fixtures/Diagnostics/PassingDiagnostic.php')
        ->and(DiagnosticSource::resolve(PassingDiagnostic::class)->application)->toBeTrue()
        ->and(DiagnosticSource::resolve(ApplicationKeyIsSet::class)->application)->toBeFalse()
        ->and(DiagnosticSource::resolve(Command::class)->application)->toBeFalse()
        ->and(DiagnosticSource::for(new PassingDiagnostic)->label())->toBe('laravel/doctor')
        ->and((new PassingDiagnostic)->package())->toBe('laravel/doctor')
        ->and(DiagnosticSource::for(new PassingDiagnostic)->application)->toBeTrue()
        ->and(DiagnosticSource::for(new PackagedDiagnostic)->label())->toBe('vendor/package')
        ->and(DiagnosticSource::for(new PackagedDiagnostic)->application)->toBeFalse();
});

it('runs registered diagnostics', function (): void {
    $doctor = (new Doctor($this->app))->diagnostics([
        PassingDiagnostic::class,
        WarningDiagnostic::class,
    ]);

    $report = $doctor->only('testing')->run();

    expect($report->diagnostics())->toHaveCount(2)
        ->and($report->hasWarnings())->toBeTrue()
        ->and($report->hasFailures())->toBeFalse()
        ->and($report->diagnostics()[1]->result->remediation)->toBe('Re-run this diagnostic after addressing the warning.');
});

it('runs diagnostics in first-seen group order', function (): void {
    $doctor = (new Doctor($this->app))->diagnostics([
        PassingDiagnostic::class,
        DatabaseDiagnostic::class,
        WarningDiagnostic::class,
    ]);

    $groups = $doctor->selectedByGroup();
    $expected = [
        PassingDiagnostic::class,
        WarningDiagnostic::class,
        DatabaseDiagnostic::class,
    ];

    expect(array_keys($groups))->toBe(['testing', 'database'])
        ->and(array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic::class,
            [...$groups['testing'], ...$groups['database']],
        ))->toBe($expected)
        ->and(array_map(
            static fn (DiagnosticOutcome $outcome): string => $outcome->diagnostic::class,
            $doctor->run()->diagnostics(),
        ))->toBe($expected);
});

it('does not treat notices as warnings', function (): void {
    $doctor = (new Doctor($this->app))->diagnostic(NoticeDiagnostic::class);

    $report = $doctor->run();

    expect($report->diagnostics()[0]->result->status->value)->toBe('notice')
        ->and($report->hasWarnings())->toBeFalse()
        ->and($report->hasFailures())->toBeFalse();
});

it('defaults diagnostic metadata from the class name', function (): void {
    $diagnostic = new DefaultMetadataDiagnostic;

    expect($diagnostic->name)->toBe('Default metadata diagnostic')
        ->and($diagnostic->group)->toBe('default-metadata-diagnostic');
});

it('runs diagnostics in registration order', function (): void {
    $doctor = (new Doctor($this->app))->diagnostics([
        PassingDiagnostic::class,
        PackagedDiagnostic::class,
    ]);

    $report = $doctor->run();

    expect($report->diagnostics())->toHaveCount(2)
        ->and($report->diagnostics()[0]->diagnostic::class)->toBe(PassingDiagnostic::class)
        ->and($report->diagnostics()[1]->diagnostic::class)->toBe(PackagedDiagnostic::class);
});

it('converts diagnostic exceptions into error results', function (): void {
    $doctor = (new Doctor($this->app))->diagnostic(ThrowingDiagnostic::class);

    $report = $doctor->run();
    $outcome = $report->diagnostics()[0];

    expect($outcome->diagnostic::class)->toBe(ThrowingDiagnostic::class)
        ->and($outcome->result->status->value)->toBe('error')
        ->and($outcome->result->summary)->toBe('The diagnostic exploded.')
        ->and($report->hasFailures())->toBeTrue();
});

it('runs a fix supplied by the diagnostic', function (): void {
    $doctor = (new Doctor($this->app))->diagnostic(FixableDiagnostic::class);

    $outcome = $doctor->run()->diagnostics()[0];

    expect($outcome->diagnostic)->toBeInstanceOf(Fixable::class)
        ->and($outcome->fixable())->toBeTrue()
        ->and($outcome->result->confirmation)->toBe('Fix the testing diagnostic?')
        ->and($outcome->result->remediation)->toBe('Apply the testing diagnostic fix.');

    $fix = $doctor->fix($outcome);

    expect($fix->result->status->value)->toBe('pass')
        ->and($fix->result->summary)->toBe('The diagnostic was fixed.');
});

it('determines whether a result is fixable in an environment mode', function (): void {
    expect(DiagnosticResult::fail('Unmarked')->fixableIn(EnvironmentMode::Local))->toBeFalse()
        ->and(DiagnosticResult::fail('Unscoped')->fixable()->fixableIn(EnvironmentMode::Local))->toBeTrue()
        ->and(DiagnosticResult::fail('Unscoped')->fixable()->fixableIn(EnvironmentMode::Production))->toBeTrue()
        ->and(DiagnosticResult::fail('Local')->fixable(EnvironmentMode::Local)->fixableIn(EnvironmentMode::Local))->toBeTrue()
        ->and(DiagnosticResult::fail('Local')->fixable(EnvironmentMode::Local)->fixableIn(EnvironmentMode::Production))->toBeFalse();
});

it('resolves local-only fixability against the current environment mode', function (): void {
    $outcome = new DiagnosticOutcome(
        new LocalOnlyFixableDiagnostic,
        (new LocalOnlyFixableDiagnostic)->check(),
    );

    $this->app->detectEnvironment(fn (): string => 'local');

    expect($outcome->fixable())->toBeTrue();

    $this->app->detectEnvironment(fn (): string => 'production');

    expect($outcome->fixable())->toBeFalse();

    $this->app->detectEnvironment(fn (): string => 'preview');

    expect($outcome->fixable())->toBeFalse();
});

it('skips local-only fixes outside local mode', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $offered = false;
    $report = (new Doctor($this->app))
        ->diagnostic(LocalOnlyFixableDiagnostic::class)
        ->fixUsing(function (DiagnosticOutcome $outcome) use (&$offered): bool {
            $offered = true;

            return true;
        })
        ->run();

    expect($report->fixes())->toBe([])
        ->and($report->hasFailures())->toBeTrue()
        ->and($offered)->toBeFalse();
});

it('applies local-only fixes in local mode', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');

    $report = (new Doctor($this->app))
        ->diagnostic(LocalOnlyFixableDiagnostic::class)
        ->fixUsing(fn (DiagnosticOutcome $outcome): bool => true)
        ->run();

    expect($report->fixes())->toHaveCount(1)
        ->and($report->fixes()[0]->result->summary)->toBe('The local diagnostic was fixed.');
});

it('rejects direct fixes for outcomes blocked in the current environment', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    (new Doctor($this->app))->fix(new DiagnosticOutcome(
        new LocalOnlyFixableDiagnostic,
        (new LocalOnlyFixableDiagnostic)->check(),
    ));
})->throws(LogicException::class, 'is not fixable');

it('keeps unscoped outcomes fixable in production mode', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $outcome = (new Doctor($this->app))
        ->diagnostic(FixableDiagnostic::class)
        ->run()
        ->diagnostics()[0];

    expect($outcome->fixable())->toBeTrue();
});

it('applies a fix with the option chosen by the callback', function (): void {
    $doctor = (new Doctor($this->app))->diagnostic(OptionFixableDiagnostic::class);

    $report = $doctor
        ->fixUsing(fn (DiagnosticOutcome $outcome): string => 'a')
        ->run();

    expect($report->fixes())->toHaveCount(1)
        ->and($report->fixes()[0]->result->summary)->toBe('The option diagnostic was fixed with [a].')
        ->and(config('doctor-testing.option-fixed'))->toBe('a')
        ->and($report->hasFailures())->toBeFalse();
});

it('skips option fixes declined by the callback', function (): void {
    $doctor = (new Doctor($this->app))->diagnostic(OptionFixableDiagnostic::class);

    $report = $doctor
        ->fixUsing(fn (DiagnosticOutcome $outcome): bool => false)
        ->run();

    expect($report->fixes())->toBe([])
        ->and($report->hasFailures())->toBeTrue()
        ->and(config('doctor-testing.option-fixed'))->toBeNull();
});

it('rejects plain approval for outcomes that require a fix option', function (): void {
    (new Doctor($this->app))
        ->diagnostic(OptionFixableDiagnostic::class)
        ->fixUsing(fn (DiagnosticOutcome $outcome): bool => true)
        ->run();
})->throws(LogicException::class, 'requires a fix option');

it('rejects undeclared fix option values', function (): void {
    (new Doctor($this->app))->fix(new DiagnosticOutcome(
        new OptionFixableDiagnostic,
        (new OptionFixableDiagnostic)->check(),
    ), 'nope');
})->throws(LogicException::class, 'is not declared');

it('rejects fix options for outcomes that do not declare them', function (): void {
    (new Doctor($this->app))->fix(new DiagnosticOutcome(
        new FixableDiagnostic,
        (new FixableDiagnostic)->check(),
    ), 'a');
})->throws(LogicException::class, 'does not accept a fix option');

it('determines whether a fix requires an option', function (): void {
    $plain = new DiagnosticOutcome(new FixableDiagnostic, (new FixableDiagnostic)->check());
    $options = new DiagnosticOutcome(new OptionFixableDiagnostic, (new OptionFixableDiagnostic)->check());
    $scoped = new DiagnosticOutcome(
        new OptionFixableDiagnostic,
        DiagnosticResult::fail('The option diagnostic failed.')
            ->fixable(EnvironmentMode::Local)
            ->fixOptions(['a' => 'Option A']),
    );

    expect($plain->fixRequiresOption())->toBeFalse()
        ->and($options->fixRequiresOption())->toBeTrue();

    $this->app->detectEnvironment(fn (): string => 'production');

    expect($scoped->fixRequiresOption())->toBeFalse();
});

it('turns fix exceptions into helpful error results', function (): void {
    $fix = (new Doctor($this->app))->fix(new DiagnosticOutcome(
        new ThrowingFixDiagnostic,
        DiagnosticResult::fail('The diagnostic failed.')->fixable(),
    ));

    expect($fix->result->status->value)->toBe('error')
        ->and($fix->result->summary)->toBe('Failed to fix Testing diagnostic fix throws: permission denied');
});

it('rejects fixes for diagnostics that are not fixable', function (): void {
    (new Doctor($this->app))->fix(new DiagnosticOutcome(
        new PassingDiagnostic,
        DiagnosticResult::fail('The diagnostic failed.'),
    ));
})->throws(LogicException::class);

it('rejects fixes for outcomes that are not explicitly fixable', function (): void {
    (new Doctor($this->app))->fix(new DiagnosticOutcome(
        new FixableDiagnostic,
        DiagnosticResult::fail('The diagnostic failed.'),
    ));
})->throws(LogicException::class, 'is not fixable');

it('rejects fixes for error outcomes even when marked fixable', function (): void {
    (new Doctor($this->app))->fix(new DiagnosticOutcome(
        new FixableDiagnostic,
        DiagnosticResult::error('The diagnostic errored.')->fixable(),
    ));
})->throws(LogicException::class, 'is not fixable');

it('does not offer an automatic fix after a fixable diagnostic throws', function (): void {
    $doctor = (new Doctor($this->app))->diagnostic(ThrowingFixableDiagnostic::class);
    $offered = false;

    $report = $doctor
        ->fixUsing(function (DiagnosticOutcome $outcome) use (&$offered): bool {
            $offered = true;

            return true;
        })
        ->run();

    expect($report->diagnostics()[0]->result->status->value)->toBe('error')
        ->and($report->diagnostics()[0]->fixable())->toBeFalse()
        ->and($report->fixes())->toBe([])
        ->and($offered)->toBeFalse();
});

it('applies configured selectors to programmatic runs', function (): void {
    config(['doctor.only' => ['database']]);

    $doctor = (new Doctor($this->app))->diagnostics([
        PassingDiagnostic::class,
        DatabaseDiagnostic::class,
    ]);

    $report = $doctor->run();

    expect($report->diagnostics())->toHaveCount(1)
        ->and($report->diagnostics()[0]->diagnostic::class)->toBe(DatabaseDiagnostic::class);
});

it('constrains runs with fluent only and except selectors', function (): void {
    $doctor = (new Doctor($this->app))->diagnostics([
        PassingDiagnostic::class,
        DatabaseDiagnostic::class,
        PackagedDiagnostic::class,
    ]);

    $report = $doctor
        ->only('testing')
        ->except('vendor/package')
        ->run();

    expect($report->diagnostics())->toHaveCount(1)
        ->and($report->diagnostics()[0]->diagnostic::class)->toBe(PassingDiagnostic::class);
});

it('intersects repeated fluent only constraints', function (): void {
    config(['doctor.only' => ['testing']]);

    $doctor = (new Doctor($this->app))->diagnostics([
        PassingDiagnostic::class,
        DatabaseDiagnostic::class,
        PackagedDiagnostic::class,
    ]);

    $report = $doctor
        ->only(PassingDiagnostic::class, DatabaseDiagnostic::class)
        ->run();

    expect($report->diagnostics())->toHaveCount(1)
        ->and($report->diagnostics()[0]->diagnostic::class)->toBe(PassingDiagnostic::class);
});

it('bails after the first failing diagnostic', function (): void {
    $doctor = (new Doctor($this->app))->diagnostics([
        WarningDiagnostic::class,
        FixableDiagnostic::class,
        PassingDiagnostic::class,
        DatabaseDiagnostic::class,
    ]);

    $report = $doctor->bail()->run();

    expect(array_map(
        static fn (DiagnosticOutcome $outcome): string => $outcome->diagnostic::class,
        $report->diagnostics(),
    ))->toBe([
        WarningDiagnostic::class,
        FixableDiagnostic::class,
    ]);
});

it('treats diagnostic errors as failures when bailing', function (): void {
    $doctor = (new Doctor($this->app))->diagnostics([
        ThrowingDiagnostic::class,
        PassingDiagnostic::class,
    ]);

    $report = $doctor->bail()->run();

    expect($report->diagnostics())->toHaveCount(1)
        ->and($report->diagnostics()[0]->diagnostic::class)->toBe(ThrowingDiagnostic::class)
        ->and($report->diagnostics()[0]->result->status->value)->toBe('error');
});

it('applies approved fixes and re-runs the diagnostics', function (): void {
    config(['doctor-testing.shared-state-fixed' => false]);

    $doctor = (new Doctor($this->app))->diagnostics([
        FixesSharedStateDiagnostic::class,
        SharedStateWasFixedDiagnostic::class,
    ]);

    $rerunning = false;

    $report = $doctor
        ->fixUsing(fn (DiagnosticOutcome $outcome): bool => true)
        ->beforeRerun(function () use (&$rerunning): void {
            $rerunning = true;
        })
        ->run();

    expect($report->fixes())->toHaveCount(1)
        ->and($report->fixes()[0]->result->summary)->toBe('The shared state fix ran.')
        ->and($report->hasFailures())->toBeFalse()
        ->and($rerunning)->toBeTrue();
});

it('does not re-run diagnostics when fixes are declined', function (): void {
    config(['doctor-testing.shared-state-fixed' => false]);

    $doctor = (new Doctor($this->app))->diagnostic(FixesSharedStateDiagnostic::class);

    $rerunning = false;

    $report = $doctor
        ->fixUsing(fn (DiagnosticOutcome $outcome): bool => false)
        ->beforeRerun(function () use (&$rerunning): void {
            $rerunning = true;
        })
        ->run();

    expect($report->fixes())->toBe([])
        ->and($report->hasFailures())->toBeTrue()
        ->and($rerunning)->toBeFalse();
});

it('owns diagnostic execution while allowing progress to be observed', function (): void {
    $doctor = (new Doctor($this->app))->diagnostics([
        PassingDiagnostic::class,
        DatabaseDiagnostic::class,
        ThrowingDiagnostic::class,
    ]);

    $observed = [];

    $report = $doctor
        ->observeUsing(function (string $group, Closure $next) use (&$observed): void {
            $observed[] = 'group:'.$group;

            $next(
                before: function (Diagnostic $diagnostic) use (&$observed): void {
                    $observed[] = 'checking:'.class_basename($diagnostic);
                },
                after: function (DiagnosticOutcome $outcome) use (&$observed): void {
                    $observed[] = 'result:'.$outcome->result->status->value;
                },
            );
        })
        ->run();

    expect($report->diagnostics())->toHaveCount(3)
        ->and($report->diagnostics()[1]->result->status->value)->toBe('error')
        ->and($observed)->toBe([
            'group:testing',
            'checking:PassingDiagnostic',
            'result:pass',
            'checking:ThrowingDiagnostic',
            'result:error',
            'group:database',
            'checking:DatabaseDiagnostic',
            'result:pass',
        ]);
});

it('requires progress observers to continue runner-owned execution', function (): void {
    (new Doctor($this->app))
        ->diagnostic(PassingDiagnostic::class)
        ->observeUsing(function (string $group, Closure $next): void {
            // Intentionally do not continue the runner-owned execution.
        })
        ->run();
})->throws(LogicException::class, 'must invoke its continuation');

it('allows applied fixes to be observed', function (): void {
    $observed = [];

    $report = (new Doctor($this->app))
        ->diagnostic(FixableDiagnostic::class)
        ->fixUsing(fn (DiagnosticOutcome $outcome): bool => true)
        ->observeFixesUsing(function (DiagnosticOutcome $outcome, Closure $apply) use (&$observed): void {
            $observed[] = 'fixing:'.class_basename($outcome->diagnostic);

            $observed[] = 'fixed:'.$apply()->result->status->value;
        })
        ->run();

    expect($report->fixes())->toHaveCount(1)
        ->and($observed)->toBe([
            'fixing:FixableDiagnostic',
            'fixed:pass',
        ]);
});

it('requires fix observers to apply the fix', function (): void {
    (new Doctor($this->app))
        ->diagnostic(FixableDiagnostic::class)
        ->fixUsing(fn (DiagnosticOutcome $outcome): bool => true)
        ->observeFixesUsing(function (DiagnosticOutcome $outcome, Closure $apply): void {
            // Intentionally do not apply the fix.
        })
        ->run();
})->throws(LogicException::class, 'must invoke its continuation');

it('appends fixes to an existing diagnostic report', function (): void {
    $diagnostic = new PassingDiagnostic;
    $first = new DiagnosticFixOutcome($diagnostic, FixResult::pass('The first fix passed.'));
    $second = new DiagnosticFixOutcome($diagnostic, FixResult::pass('The second fix passed.'));

    $report = (new DiagnosticReport)->withFixes([$first])->withFixes([$second]);

    expect($report->fixes())->toBe([$first, $second]);
});
