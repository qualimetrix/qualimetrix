<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Analysis\Policy\Baseline\BaselineConflictException;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Pipeline\IncompleteAnalysisException;
use Qualimetrix\Core\FileTarget\PreparedTarget;
use Qualimetrix\Core\FileTarget\ResolvedTarget;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\StagedSignalGuard;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * What the five baseline commands do identically: turn the failures they all
 * share into a message and an exit code, and — for the two that write —
 * apply the scope guard.
 *
 * Every one of them reads a file that may be missing or unparseable, runs an
 * analysis whose configuration may not load, and names paths that may not
 * exist. Left to each command those become five slightly different
 * spellings of the same three sentences, and the one that forgets a `catch`
 * answers a bad path with a stack trace.
 *
 * @qmx-ignore health.cohesion -- the final execute() / abstract doExecute()
 * split is a template-method seam: this base class carries the shared
 * ladder and none of a subcommand's own state, so it measures as low
 * cohesion by construction, not as a defect. `@qmx-threshold` cannot retune
 * this instead: `health.cohesion` is a computed metric with no per-symbol
 * override support.
 */
abstract class BaselineCommand extends Command
{
    /** Analysis/tool failure, distinct from policy and input/configuration outcomes. */
    protected const int EXIT_ANALYSIS_INCOMPLETE = 4;

    /**
     * Setter rather than a constructor argument: five concrete commands
     * extend this class, each with its own constructor and DI registration,
     * and a constructor parameter here would mean editing all five instead
     * of the one shared ladder.
     */
    private RefusalPresenter $refusalPresenter;

    public function setRefusalPresenter(RefusalPresenter $refusalPresenter): void
    {
        $this->refusalPresenter = $refusalPresenter;
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->refusalFormat($input);

        try {
            $exitCode = $this->doExecute($input, $output);

            // Only `baseline:rename-channels` ever answers `json` here (the other
            // four declare no `--format` and this stays `null` for them), which
            // is exactly the guard that keeps its machine-readable branch a
            // document a script can still parse.
            if ($format !== 'json' && !\in_array($exitCode, [128 + \SIGINT, 128 + \SIGTERM], true)) {
                $output->writeln(\sprintf('<comment>%s</comment>', ProductIdentity::pointerText()));
            }

            return $exitCode;
        } catch (RefusalInterface $refusal) {
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (IncompleteAnalysisException $e) {
            return $this->fail($output, $e->getMessage(), $e, self::EXIT_ANALYSIS_INCOMPLETE);
        } catch (BaselineConflictException $e) {
            return $this->fail($output, $e->getMessage(), $e);
        } catch (InvalidArgumentException $e) {
            // The named secondary signal for code 3: an `InvalidArgumentException`
            // that never became a carrier. No trace even under -v — a refusal
            // is the user's to fix, not ours to explain with a stack.
            return $this->refusalPresenter->fallbackRefusal($output, $format, $e);
        } catch (RuntimeException $e) {
            return $this->refusalPresenter->unhandled($output, $format, $e);
        } catch (Throwable $e) {
            return $this->refusalPresenter->unhandled($output, $format, $e);
        }
    }

    /**
     * The machine format this concrete command answers a refusal in, or
     * `null` when it has none. Read here — once, before `doExecute()` runs —
     * rather than each command reading its own `--format` option, because
     * only one of the five (`baseline:rename-channels`) declares that option
     * at all; the other four would fail on `getOption('format')` before ever
     * reaching their own logic.
     */
    protected function refusalFormat(InputInterface $input): ?string
    {
        return null;
    }

    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;

    /** @param callable(PreparedTarget, StagedSignalGuard): int $action */
    protected function withPreparedTarget(ResolvedTarget $target, callable $action): int
    {
        $guard = StagedSignalGuard::start($target->spelling);
        $prepared = null;
        try {
            $prepared = PreparedTarget::prepare($target);
            $result = $action($prepared, $guard);
            $signal = $guard->interruptedSignal();

            return $signal === null ? $result : 128 + $signal;
        } catch (Throwable $failure) {
            $signal = $guard->interruptedSignal();
            if ($signal !== null) {
                return 128 + $signal;
            }

            throw $failure;
        } finally {
            $guard->beginCleanup();
            try {
                $prepared?->discard();
            } finally {
                $guard->close();
            }
        }
    }

    /**
     * Appends the documentation address to a command's own `--help` text, so
     * each of the five commands states only its own explanation and this line
     * is written once.
     */
    protected static function withDocsPointer(string $help): string
    {
        return $help . "\n\n" . \sprintf('Docs: %s', ProductIdentity::llmsTxtUrl());
    }

    /**
     * Reports a failure, with the trace when the user asked for verbosity.
     *
     * Swallowing the trace unconditionally is what left `-vvv` with nothing
     * more to say than a bare run: every one of these exceptions can be
     * raised from somewhere the message does not name, and the classification
     * "the user's to fix" is a guess that is wrong exactly when a trace is
     * worth most. {@see CheckCommand::execute()} makes the same trade for the
     * same reason, and this keeps the two commands answering `-v` alike.
     */
    private function fail(OutputInterface $output, string $message, Throwable $e, int $exitCode = self::FAILURE): int
    {
        $output->writeln(\sprintf('<error>%s</error>', $message));

        if ($output->isVerbose()) {
            $output->writeln('');
            $output->writeln('<comment>Stack trace:</comment>');
            $output->writeln($e->getTraceAsString());
        }

        return $exitCode;
    }

    /**
     * The precondition both writing commands share (ADR 0017): a run narrower
     * than the file's recorded scope makes every identity outside it look
     * absent, so `cleanup` would offer the rest of the file for removal and
     * `update` would leave it silently untouched. A wider run measures more
     * than the file remembers, which is harmless — so only the narrowing
     * direction is refused, and `--force` overrides it.
     *
     * Returns `true` when the command may proceed.
     *
     * @param list<string> $recordedScope
     */
    protected function assertScopeCovers(
        RunScope $runScope,
        array $recordedScope,
        bool $force,
        OutputInterface $output,
    ): bool {
        $uncovered = $runScope->uncoveredPaths($recordedScope);

        if ($uncovered === []) {
            return true;
        }

        if ($force) {
            $output->writeln(\sprintf(
                '<comment>Proceeding under --force: this run does not cover %s, recorded in the baseline.</comment>',
                implode(', ', $uncovered),
            ));

            return true;
        }

        $output->writeln(\sprintf(
            '<error>This run does not cover %s, which the baseline records as its scope. '
            . 'Re-run over at least the recorded scope, or pass --force to write anyway.</error>',
            implode(', ', $uncovered),
        ));

        return false;
    }

    /**
     * The preamble `baseline:cleanup` and `baseline:update` share (ADR 0017):
     * preflight grammar, then measure before loading semantic entries — a `computed.*` / `health.*` declaration only
     * exists once the run has resolved configuration, so loading the file
     * first would leave every such entry inert, and each command would
     * answer differently than the `check` applying the very same entry —
     * then refuse when the run's scope does not cover what the file records.
     * Document grammar needs no declaration, and an invalid file should not
     * cost a whole analysis.
     *
     * Returns `null` when the caller must answer with `self::FAILURE`; the
     * scope guard has already written its own message to `$output`.
     */
    protected function measureAgainstBaseline(
        BaselineRunInterface $baselineRun,
        BaselineLoader $loader,
        InputInterface $input,
        OutputInterface $output,
        BaselineDocument $document,
    ): ?LoadedBaselineRun {
        $force = $input->getOption('force') === true;

        $context = $baselineRun->measure($input, $output);
        $baseline = $loader->load($document);

        if (!$this->assertScopeCovers($context->scope, $baseline->scope, $force, $output)) {
            return null;
        }

        return new LoadedBaselineRun($context, $baseline);
    }
}
