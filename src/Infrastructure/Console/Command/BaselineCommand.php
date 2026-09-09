<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Baseline\BaselineConflictException;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Pipeline\IncompleteAnalysisException;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
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
            return $this->doExecute($input, $output);
        } catch (ConfigurationRefusal $refusal) {
            // First clause: the carrier is a RuntimeException, and the split
            // pair below would otherwise catch it and answer with code 1
            // instead of 3.
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (IncompleteAnalysisException $e) {
            return $this->fail($output, $e->getMessage(), $e, self::EXIT_ANALYSIS_INCOMPLETE);
        } catch (BaselineConflictException $e) {
            return $this->fail($output, $e->getMessage(), $e);
        } catch (InvalidArgumentException $e) {
            // The named secondary signal for code 3 (`00-overview.md` rule 1,
            // `01-refusal-exit-ladder.md` §2.6): an `InvalidArgumentException`
            // that never became a carrier. No trace even under -v — a refusal
            // is the user's to fix, not ours to explain with a stack.
            return $this->refusalPresenter->fallbackRefusal($output, $format, $e);
        } catch (RuntimeException $e) {
            // The baseline loader and the v5 reader used to report every
            // envelope problem this way; both are now carriers (03/P5), so
            // what still reaches here is either a genuine defect or a type
            // this round has not normalized yet. Either way it is not a
            // proven refusal, so it keeps the trace-on-`-v` treatment rather
            // than the presenter's code 3.
            return $this->fail($output, $e->getMessage(), $e);
        } catch (Throwable $e) {
            // Anything else is a bug in this tool rather than in the user's
            // input, and is labelled as such so the two are not confused.
            return $this->fail($output, \sprintf('Unexpected error: %s', $e->getMessage()), $e);
        }
    }

    /**
     * The machine format this concrete command answers a refusal in, or
     * `null` when it has none. Read here — once, before `doExecute()` runs —
     * rather than each command reading its own `--format` option, because
     * only one of the five (`baseline:rename-channels`) declares that option
     * at all; the other four would fail on `getOption('format')` before ever
     * reaching their own logic (`01-refusal-packages.md`, P01-4).
     */
    protected function refusalFormat(InputInterface $input): ?string
    {
        return null;
    }

    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;

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
}
