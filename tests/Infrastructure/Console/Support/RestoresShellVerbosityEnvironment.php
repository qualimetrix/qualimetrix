<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Support;

/**
 * `Symfony\Component\Console\Application::configureIO()` sets the
 * `SHELL_VERBOSITY` environment variable — through `putenv()` and both
 * `$_ENV`/`$_SERVER` — as a side effect of computing the requested
 * verbosity. That is a real process environment variable, not state scoped
 * to the `Application` instance or even to the current PHP process's own
 * output: `Symfony\Component\Console\Application::run()` is the *only*
 * place that snapshots the prior value and restores it, in a `finally`
 * block wrapped around `configureIO()` + `doRun()` (see
 * `vendor/symfony/console/Application.php`). A test that reaches
 * `configureIO()` directly — through `ReflectionMethod`, to prove that one
 * method's contract without running a whole command — never goes through
 * `run()`, so whatever it sets survives the test. From there it leaks into
 * every subprocess the rest of the suite spawns via `proc_open()` /
 * `shell_exec()`: those inherit the parent's environment, and their own
 * `Application::configureIO()` reads the same variable. A leaked
 * `SHELL_VERBOSITY=-2` (`--silent`) is the sharp case — the child's output
 * becomes `VERBOSITY_SILENT`, which nothing can write to at any verbosity
 * (see `Application::configureIO()`'s own docblock), so the child prints
 * zero bytes to both stdout and stderr while still exiting normally.
 *
 * Mixing this trait into a test class and calling
 * {@see self::snapshotShellVerbosityEnvironment()} in `setUp()` /
 * {@see self::restoreShellVerbosityEnvironment()} in `tearDown()` makes that
 * leak impossible regardless of which test method (present or future)
 * touches `configureIO()` outside `run()` — the same shape of protection
 * this class already gives the process working directory.
 */
trait RestoresShellVerbosityEnvironment
{
    private const string SHELL_VERBOSITY_KEY = 'SHELL_VERBOSITY';

    private mixed $shellVerbosityEnvBefore = null;

    private mixed $shellVerbosityServerBefore = null;

    private string|false $shellVerbosityGetenvBefore = false;

    private function snapshotShellVerbosityEnvironment(): void
    {
        $this->shellVerbosityEnvBefore = $_ENV[self::SHELL_VERBOSITY_KEY] ?? null;
        $this->shellVerbosityServerBefore = $_SERVER[self::SHELL_VERBOSITY_KEY] ?? null;
        $this->shellVerbosityGetenvBefore = getenv(self::SHELL_VERBOSITY_KEY);
    }

    private function restoreShellVerbosityEnvironment(): void
    {
        if ($this->shellVerbosityEnvBefore === null) {
            unset($_ENV[self::SHELL_VERBOSITY_KEY]);
        } else {
            $_ENV[self::SHELL_VERBOSITY_KEY] = $this->shellVerbosityEnvBefore;
        }

        if ($this->shellVerbosityServerBefore === null) {
            unset($_SERVER[self::SHELL_VERBOSITY_KEY]);
        } else {
            $_SERVER[self::SHELL_VERBOSITY_KEY] = $this->shellVerbosityServerBefore;
        }

        if ($this->shellVerbosityGetenvBefore === false) {
            putenv(self::SHELL_VERBOSITY_KEY);
        } else {
            putenv(self::SHELL_VERBOSITY_KEY . '=' . $this->shellVerbosityGetenvBefore);
        }
    }
}
