<?php

namespace Laravel\Doctor;

use Closure;
use Illuminate\Support\Traits\Conditionable;
use Laravel\Doctor\Results\DiagnosticFixOutcome;
use Laravel\Doctor\Results\DiagnosticOutcome;
use Laravel\Doctor\Results\DiagnosticReport;
use LogicException;

/**
 * @phpstan-type BeforeDiagnostic Closure(Diagnostic): void
 * @phpstan-type AfterDiagnostic Closure(DiagnosticOutcome): void
 * @phpstan-type GroupContinuation Closure(BeforeDiagnostic|null $before, AfterDiagnostic|null $after): void
 * @phpstan-type FixContinuation Closure(): DiagnosticFixOutcome
 */
class PendingRun
{
    use Conditionable;

    /**
     * The callback used to observe each diagnostic group.
     *
     * @var (Closure(string, GroupContinuation): void)|null
     */
    protected ?Closure $observer = null;

    /**
     * The callback that determines whether a failing diagnostic should be fixed.
     *
     * @var (Closure(DiagnosticOutcome): (bool|string))|null
     */
    protected ?Closure $fix = null;

    /**
     * The callback used to observe each applied fix.
     *
     * @var (Closure(DiagnosticOutcome, FixContinuation): void)|null
     */
    protected ?Closure $fixObserver = null;

    /**
     * The callback invoked when applied fixes require the diagnostics to be re-run.
     *
     * @var (Closure(): void)|null
     */
    protected ?Closure $beforeRerun = null;

    /**
     * Whether the run should stop after the first failing diagnostic.
     */
    protected bool $bail = false;

    /**
     * Create a new pending diagnostic run.
     */
    public function __construct(
        protected Doctor $doctor,
        protected DiagnosticSelection $selection,
    ) {
        //
    }

    /**
     * Constrain the run to diagnostics matching the given selectors.
     *
     * Selectors may reference a diagnostic class, class basename, group, or
     * package name. Repeated calls intersect with previous constraints.
     */
    public function only(string ...$selectors): self
    {
        $this->selection = $this->selection->constrain(only: $selectors);

        return $this;
    }

    /**
     * Exclude diagnostics matching the given selectors from the run.
     */
    public function except(string ...$selectors): self
    {
        $this->selection = $this->selection->constrain(except: $selectors);

        return $this;
    }

    /**
     * Stop the run after the first failing diagnostic.
     */
    public function bail(bool $bail = true): self
    {
        $this->bail = $bail;

        return $this;
    }

    /**
     * Observe each diagnostic group through the given callback.
     *
     * The callback receives the group name and a controlled continuation that
     * runs the group. The continuation accepts an optional "before" callback
     * invoked before each diagnostic and an optional "after" callback invoked
     * with each outcome.
     *
     * @param  Closure(string, GroupContinuation): void  $callback
     */
    public function observeUsing(Closure $callback): self
    {
        $this->observer = $callback;

        return $this;
    }

    /**
     * Apply fixes approved by the given callback.
     *
     * The callback may return false to skip the fix, true to apply a plain
     * fix, or one of the result's fix option values to apply the fix with
     * that choice.
     *
     * @param  Closure(DiagnosticOutcome): (bool|string)  $callback
     */
    public function fixUsing(Closure $callback): self
    {
        $this->fix = $callback;

        return $this;
    }

    /**
     * Observe each applied fix through the given callback.
     *
     * The callback receives the failing outcome and a continuation that
     * applies the fix and returns its outcome. It must invoke the continuation.
     *
     * @param  Closure(DiagnosticOutcome, FixContinuation): void  $callback
     */
    public function observeFixesUsing(Closure $callback): self
    {
        $this->fixObserver = $callback;

        return $this;
    }

    /**
     * Invoke the given callback when applied fixes require the diagnostics to be re-run.
     */
    public function beforeRerun(Closure $callback): self
    {
        $this->beforeRerun = $callback;

        return $this;
    }

    /**
     * Run the diagnostics.
     */
    public function run(): DiagnosticReport
    {
        $report = $this->check();

        if ($this->fix === null) {
            return $report;
        }

        $fixes = $this->fixes($report, $this->fix);

        if ($fixes === []) {
            return $report;
        }

        if ($this->beforeRerun !== null) {
            ($this->beforeRerun)();
        }

        return $this->check()->withFixes($fixes);
    }

    /**
     * Check the selected diagnostics and gather their outcomes into a report.
     */
    protected function check(): DiagnosticReport
    {
        $outcomes = [];

        foreach ($this->doctor->selectedByGroup($this->selection) as $group => $diagnostics) {
            $groupOutcomes = $this->checkGroup($group, $diagnostics);
            $outcomes = [...$outcomes, ...$groupOutcomes];

            if ($this->bail && $this->hasFailure($groupOutcomes)) {
                break;
            }
        }

        return new DiagnosticReport($outcomes);
    }

    /**
     * Check a diagnostic group while allowing its progress to be observed.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<DiagnosticOutcome>
     */
    protected function checkGroup(string $group, array $diagnostics): array
    {
        if ($this->observer === null) {
            return $this->checkDiagnostics($diagnostics);
        }

        $outcomes = null;

        ($this->observer)($group, function (?Closure $before = null, ?Closure $after = null) use (&$outcomes, $diagnostics): void {
            $outcomes = $this->checkDiagnostics($diagnostics, $before, $after);
        });

        return $outcomes ?? throw new LogicException('A diagnostic group observer must invoke its continuation.');
    }

    /**
     * Check the given diagnostics.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @param  (Closure(Diagnostic): void)|null  $before
     * @param  (Closure(DiagnosticOutcome): void)|null  $after
     * @return list<DiagnosticOutcome>
     */
    protected function checkDiagnostics(array $diagnostics, ?Closure $before = null, ?Closure $after = null): array
    {
        $outcomes = [];

        foreach ($diagnostics as $diagnostic) {
            if ($before !== null) {
                $before($diagnostic);
            }

            $outcome = $this->doctor->check($diagnostic);
            $outcomes[] = $outcome;

            if ($after !== null) {
                $after($outcome);
            }

            if ($this->bail && $outcome->result->status->failed()) {
                break;
            }
        }

        return $outcomes;
    }

    /**
     * Determine whether the outcomes contain a failure.
     *
     * @param  list<DiagnosticOutcome>  $outcomes
     */
    protected function hasFailure(array $outcomes): bool
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->result->status->failed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply a diagnostic fix while allowing it to be observed.
     */
    protected function applyFix(DiagnosticOutcome $outcome, ?string $option): DiagnosticFixOutcome
    {
        if ($this->fixObserver === null) {
            return $this->doctor->fix($outcome, $option);
        }

        $fix = null;

        ($this->fixObserver)($outcome, function () use (&$fix, $outcome, $option): DiagnosticFixOutcome {
            return $fix = $this->doctor->fix($outcome, $option);
        });

        return $fix ?? throw new LogicException('A fix observer must invoke its continuation.');
    }

    /**
     * Apply the approved fixes for the report's failing diagnostics.
     *
     * @param  Closure(DiagnosticOutcome): (bool|string)  $fix
     * @return list<DiagnosticFixOutcome>
     */
    protected function fixes(DiagnosticReport $report, Closure $fix): array
    {
        $fixes = [];

        foreach ($report->diagnostics() as $outcome) {
            if (! $outcome->fixable()) {
                continue;
            }

            $decision = $fix($outcome);

            if ($decision === false) {
                continue;
            }

            $fixes[] = $this->applyFix($outcome, is_string($decision) ? $decision : null);
        }

        return $fixes;
    }
}
