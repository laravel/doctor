<?php

namespace Laravel\Doctor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Laravel\Doctor\Doctor diagnostic(class-string<\Laravel\Doctor\Diagnostic> $diagnostic)
 * @method static \Laravel\Doctor\Doctor diagnostics(iterable<class-string<\Laravel\Doctor\Diagnostic>> $diagnostics)
 * @method static \Laravel\Doctor\Results\DiagnosticReport run()
 * @method static \Laravel\Doctor\PendingRun only(string ...$selectors)
 * @method static \Laravel\Doctor\PendingRun except(string ...$selectors)
 * @method static \Laravel\Doctor\PendingRun bail(bool $bail = true)
 * @method static \Laravel\Doctor\PendingRun observeUsing(\Closure $callback)
 * @method static \Laravel\Doctor\PendingRun fixUsing(\Closure $callback)
 * @method static \Laravel\Doctor\PendingRun observeFixesUsing(\Closure $callback)
 * @method static \Laravel\Doctor\PendingRun beforeRerun(\Closure $callback)
 *
 * @see \Laravel\Doctor\Doctor
 */
class Doctor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Laravel\Doctor\Doctor::class;
    }
}
