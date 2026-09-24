<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Version;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Exception\LogicException as ConsoleLogicException;
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
 * {@see self::doRun()} is the outermost exit-code ladder. It wraps the
 * *entire* body, not just
 * `parent::doRun()`, because the `--working-dir` checks below throw *above*
 * the parent call — a ladder around only the parent call would let `-d
 * <file>` escape into Symfony's own `Application::run()`, which takes its
 * exit code from the caught exception's `getCode()` (0 for
 * `InvalidArgumentException`, giving 1 instead of the promised refusal code 3).
 * It does not cover the `configureIO()` window inside `run()`: that stays on
 * Symfony's own `catchExceptions` handling; no accepted input can raise a
 * {@see ConfigurationRefusal} in that window.
 *
 * @qmx-threshold cohesion.lcom 6 -- this is the composition root: wiring
 * together the otherwise-unrelated errorStream, refusalPresenter and
 * exit-code ladder is its job, not a sign that unrelated responsibilities
 * accreted onto one class.
 */
final class Application extends BaseApplication
{
    public const string NAME = 'Qualimetrix';

    public function __construct(
        private readonly ErrorStream $errorStream,
        private readonly RefusalPresenter $refusalPresenter,
    ) {
        parent::__construct(self::NAME, Version::get());

        self::appendPointerToListCommandHelp($this);
    }

    /**
     * Bare `--help`, with no target command, never reaches
     * {@see self::getHelp()} — see that method's docblock for the measured
     * seam. The only place left to carry the pointer on that path is the
     * `list` command's own `Help:` section, since `--help` with no command
     * renders that command's description ({@see \Symfony\Component\Console\Command\ListCommand},
     * a framework command this project does not own the file of).
     * `Command::setHelp()` is public API, not a vendor patch: `list` is
     * registered eagerly here (`Application::get()` runs the base class's
     * lazy `init()` on first call, which is safe to force this early — it
     * only populates `$this->commands` from `getDefaultCommands()` and does
     * not depend on the command loader set later in production wiring).
     * `getHelp()`'s own template uses `%command.name%`/`%command.full_name%`
     * placeholders processed by {@see \Symfony\Component\Console\Command\Command::getProcessedHelp()};
     * appending plain text with no `%` in it cannot collide with that
     * substitution.
     */
    private static function appendPointerToListCommandHelp(self $app): void
    {
        $listCommand = $app->get('list');

        $listCommand->setHelp(
            $listCommand->getHelp() . "\n\n" . \sprintf('<comment>%s</comment>', ProductIdentity::pointerText()),
        );
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
     * The stock long version plus the documentation pointer.
     *
     * {@see \Symfony\Component\Console\Descriptor\TextDescriptor::describeApplication()}
     * renders this exact return value as the header of bare `qmx` and `list`
     * — both run {@see \Symfony\Component\Console\Command\ListCommand}, which
     * describes the {@see \Symfony\Component\Console\Application}. Measured:
     * `--help` with no target command does not reach this override — Symfony
     * rewrites it to the `help` command describing the `list` *command*
     * object instead ({@see \Symfony\Component\Console\Application::doRun()},
     * the `!$name` branch), which is `describeCommand()`, not
     * `describeApplication()`. That path carries the pointer separately, in
     * `list`'s own `Help:` section — see
     * {@see self::appendPointerToListCommandHelp()}. `--version` calls
     * {@see self::getLongVersion()} directly and never reaches this override
     * either, so it stays the unix-convention "name version" line that
     * scripts parse.
     */
    public function getHelp(): string
    {
        return $this->getLongVersion() . "\n\n" . \sprintf('<comment>%s</comment>', ProductIdentity::pointerText());
    }

    /**
     * The outermost exit-code ladder assigns the process exit code itself and ignores
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
     * {@see ConsoleLogicException} is caught ahead of, and excluded from,
     * `ConsoleExceptionInterface`: it is thrown only on a malformed command
     * *declaration* (an empty command name, a duplicate option name, a
     * default value on a `VALUE_NONE` option, a hint closure that returned
     * something other than an array) — every throw site is in
     * `symfony/console` itself (about fifty, spread across `Application`,
     * `Command`, `InputDefinition`, `InputOption`, `InputArgument`,
     * `Helper/ProgressBar`, `Helper/ProgressIndicator`, `Question` and
     * `Command/LockableTrait`; `grep -rn "new LogicException(" vendor/symfony/console`
     * finds the current set), reachable only by a bug in this project's own
     * command wiring, never by anything a user typed. That makes it a
     * product defect, not a refusal, so it gets exit code 1 like any other
     * internal error.
     *
     * `ConsoleExceptionInterface` and the bare `InvalidArgumentException`
     * clause are named secondary signals for exit code 3: a caught console-argument
     * error (unknown command, unknown option) or an
     * `InvalidArgumentException` with no {@see ConfigurationRefusal} behind
     * it — most reachably one thrown from a command's `configure()` or
     * constructor, before any command-level ladder is on the stack.
     *
     * Once caught here, Symfony's own `run()` never sees the throwable and
     * therefore never calls {@see self::renderThrowable()} — this ladder
     * prints instead, through the presenter, in the format the command line
     * asked for ({@see self::requestedFormat()}), so a JSON consumer receives
     * the same envelope whether the refusal came from inside a command or
     * from here.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $format = self::requestedFormat($input);

        try {
            self::applyWorkingDirOption($input);

            return parent::doRun($input, $output);
        } catch (ConfigurationRefusal $refusal) {
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (ConsoleLogicException $e) {
            return $this->refusalPresenter->internalError($output, $format, $e);
        } catch (ConsoleExceptionInterface $e) {
            return $this->refusalPresenter->fallbackRefusal($output, $format, $e);
        } catch (InvalidArgumentException $e) {
            return $this->refusalPresenter->fallbackRefusal($output, $format, $e);
        } catch (Throwable $e) {
            return $this->refusalPresenter->internalError($output, $format, $e);
        }
    }

    /**
     * `--format`, read off the raw tokens: the refusals this ladder catches
     * are mostly binding failures, so no bound value exists yet.
     *
     * The long form only. `-f` is not `--format` on every command —
     * `hook:install` spells `--force` that way — and which command owns the
     * token is exactly what an unknown command or option leaves undecided.
     */
    private static function requestedFormat(InputInterface $input): ?string
    {
        $value = $input->getParameterOption('--format', null);

        return \is_string($value) ? $value : null;
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
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--working-dir',
                \sprintf('Invalid working directory: %s', $workingDir),
            );
        }

        if (!chdir($resolved)) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--working-dir',
                \sprintf('Failed to change working directory to: %s', $resolved),
            );
        }
    }

    /**
     * Demotes Symfony's `--silent` (`VERBOSITY_SILENT`, 8) to `-q`
     * (`VERBOSITY_QUIET`, 16) after the base class applies it.
     *
     * `Output::write()` drops a message when the verbosity it was tagged
     * with compares greater than the output's own verbosity ({@see
     * \Symfony\Component\Console\Output\Output::write()}: `$verbosity >
     * $this->getVerbosity()`). `VERBOSITY_SILENT` (8) is lower than every
     * bit `write()` recognises for a tagged call — `VERBOSITY_QUIET` (16) is
     * the lowest of them — so once the output's own verbosity is silent, any
     * call, whatever verbosity it asked for, compares greater and is
     * dropped: there is no verbosity value a message can carry that survives
     * it. That includes {@see RefusalPresenter}'s `VERBOSITY_QUIET` writes,
     * the run-ending writes that must survive `-q`. Symfony's own docs read
     * `--silent` as "no output
     * at all", which is a legitimate request for the report — just not for
     * the message that has to explain why there is no report. Treating it
     * as `-q` keeps that promise and still drops everything `-q` already
     * drops.
     *
     * This is a documented change to `--silent`'s external contract: see
     * `CHANGELOG.md` (Unreleased, the `-q` entry) and
     * `docs/adr/0051-refusal-is-not-routed-by-command.md` §3.
     */
    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        parent::configureIO($input, $output);

        if ($output->getVerbosity() === OutputInterface::VERBOSITY_SILENT) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
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
