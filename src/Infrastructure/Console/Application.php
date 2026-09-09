<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Core\Version;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Qualimetrix CLI application.
 *
 * Supports `--working-dir` / `-d` global option to change the effective
 * working directory before any command runs (same pattern as Composer).
 *
 * {@see self::doRun()} is the round's outermost exit-code ladder
 * (`01-refusal-exit-ladder.md` §2.4): it wraps the *entire* body, not just
 * `parent::doRun()`, because the `--working-dir` checks below throw *above*
 * the parent call — a ladder around only the parent call would let `-d
 * <file>` escape into Symfony's own `Application::run()`, which takes its
 * exit code from the caught exception's `getCode()` (0 for
 * `InvalidArgumentException`, giving 1 instead of the 3 this round promises).
 * It does not cover the `configureIO()` window inside `run()`: that stays on
 * Symfony's own `catchExceptions` handling, unreached by any input this round
 * has a carrier for (`01-refusal-exit-ladder.md` §2.4).
 */
final class Application extends BaseApplication
{
    public const string NAME = 'Qualimetrix';

    public function __construct(
        private readonly ErrorStream $errorStream,
        private readonly RefusalPresenter $refusalPresenter,
    ) {
        parent::__construct(self::NAME, Version::get());
    }

    /**
     * Renders an uncaught throwable through the run's error-stream owner.
     *
     * The base class resolves `getErrorOutput()` itself and writes there
     * directly, which is the one diagnostic guaranteed to arrive while a
     * progress frame is still on screen — nothing called the reporter's
     * `finish()` on this path. Going through the owner erases the frame first
     * and then writes the trace where it will not be erased in turn.
     *
     * The output handed in here is already the resolved error stream, not the
     * console output, so the owner is asked for the writer it is *already*
     * bound to; rebinding on it would resolve to no error channel at all.
     */
    public function renderThrowable(Throwable $e, OutputInterface $output): void
    {
        $this->errorStream->stopProgress();

        parent::renderThrowable($e, $this->errorStream->boundWriter($output));
    }

    /**
     * The round's outermost exit-code ladder (`01-refusal-exit-ladder.md`
     * §2.4). It assigns the process exit code itself and ignores
     * `getCode()` on whatever it catches — Symfony's own convention would
     * turn a `JsonException(..., JSON_ERROR_SYNTAX)` (code 4) into exit code
     * 4, indistinguishable from `directives`' documented "run incomplete"
     * outcome.
     *
     * Clause order is significant, not stylistic: {@see ConfigurationRefusal}
     * extends {@see \RuntimeException} and {@see InvalidArgumentException}
     * would otherwise catch the general-purpose console exceptions first, so
     * the more specific clauses come first.
     *
     * `ConsoleExceptionInterface` and the bare `InvalidArgumentException`
     * clause are named secondary signals for exit code 3
     * (`01-refusal-exit-ladder.md` §2.5/§2.6): a caught console-argument
     * error (unknown command, unknown option) or an
     * `InvalidArgumentException` with no {@see ConfigurationRefusal} behind
     * it — most reachably one thrown from a command's `configure()` or
     * constructor, before any command-level ladder is on the stack.
     *
     * Once caught here, Symfony's own `run()` never sees the throwable and
     * therefore never calls {@see self::renderThrowable()} — this ladder
     * prints instead, through the presenter, with `format: null` because the
     * output format is not known at this level (`01-refusal-exit-ladder.md`
     * §3).
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            self::applyWorkingDirOption($input);

            return parent::doRun($input, $output);
        } catch (ConfigurationRefusal $refusal) {
            return $this->refusalPresenter->refusal($output, null, $refusal);
        } catch (ConsoleExceptionInterface $e) {
            return $this->refusalPresenter->fallbackRefusal($output, null, $e);
        } catch (InvalidArgumentException $e) {
            return $this->refusalPresenter->fallbackRefusal($output, null, $e);
        } catch (Throwable $e) {
            return $this->refusalPresenter->internalError($output, null, $e);
        }
    }

    /**
     * Applies `--working-dir` / `-d` before the wrapped command runs, per the
     * class docblock. Split out of {@see self::doRun()} so the exit-code
     * ladder there stays the only thing that method does.
     *
     * @throws ConfigurationRefusal
     */
    private static function applyWorkingDirOption(InputInterface $input): void
    {
        $workingDir = $input->getParameterOption(['--working-dir', '-d']);

        if (!\is_string($workingDir) || $workingDir === '') {
            return;
        }

        $resolved = realpath($workingDir);

        // is_readable() is checked before chdir(), not after a failed
        // chdir(): chdir() on an unreadable directory emits a PHP
        // Warning that would land on stdout and stderr both,
        // contradicting the DoD's "no PHP Warning, 0 bytes of stdout".
        if ($resolved === false || !is_dir($resolved) || !is_readable($resolved)) {
            throw ConfigurationRefusal::aboutInput(
                ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--working-dir'),
                \sprintf('Invalid working directory: %s', $workingDir),
            );
        }

        if (!chdir($resolved)) {
            throw ConfigurationRefusal::aboutInput(
                ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--working-dir'),
                \sprintf('Failed to change working directory to: %s', $resolved),
            );
        }
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        $definition->addOption(new InputOption(
            'working-dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'Use the given directory as working directory',
        ));

        return $definition;
    }
}
