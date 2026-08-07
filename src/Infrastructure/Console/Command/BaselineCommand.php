<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Baseline\BaselineConflictException;
use Qualimetrix\Baseline\RunScope;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
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
    /**
     * Exit code for malformed input — a value the command cannot act on at
     * all, as opposed to a refusal to act on input it understood.
     */
    protected const int EXIT_INVALID_INPUT = self::INVALID;

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute($input, $output);
        } catch (ConfigLoadException $e) {
            return $this->fail($output, \sprintf('Configuration error: %s', $e->getMessage()), $e);
        } catch (BaselineConflictException $e) {
            return $this->fail($output, $e->getMessage(), $e);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // The baseline loader and the v5 reader report every envelope
            // problem as a RuntimeException, and a path that does not exist
            // arrives as an InvalidArgumentException. Both are usually the
            // user's to fix — hence the bare message by default — but
            // "usually" is why the trace is still available under -v.
            return $this->fail($output, $e->getMessage(), $e);
        } catch (Throwable $e) {
            // Anything else is a bug in this tool rather than in the user's
            // input, and is labelled as such so the two are not confused.
            return $this->fail($output, \sprintf('Unexpected error: %s', $e->getMessage()), $e);
        }
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
    private function fail(OutputInterface $output, string $message, Throwable $e): int
    {
        $output->writeln(\sprintf('<error>%s</error>', $message));

        if ($output->isVerbose()) {
            $output->writeln('');
            $output->writeln('<comment>Stack trace:</comment>');
            $output->writeln($e->getTraceAsString());
        }

        return self::FAILURE;
    }

    /**
     * The precondition both writing commands share (§5.7): a run narrower
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
