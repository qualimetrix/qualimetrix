<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Baseline\BaselineConflictException;
use Qualimetrix\Baseline\ScopeCoverage;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
            $output->writeln(\sprintf('<error>Configuration error: %s</error>', $e->getMessage()));

            return self::FAILURE;
        } catch (BaselineConflictException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        } catch (InvalidArgumentException|RuntimeException $e) {
            // The baseline loader and the v5 reader report every envelope
            // problem as a RuntimeException, and a path that does not exist
            // arrives as an InvalidArgumentException. Both are the user's to
            // fix, so neither deserves a trace.
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }

    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;

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
     * @param list<string> $runScope
     * @param list<string> $recordedScope
     */
    protected function assertScopeCovers(
        array $runScope,
        array $recordedScope,
        bool $force,
        OutputInterface $output,
    ): bool {
        $uncovered = ScopeCoverage::uncoveredPaths($runScope, $recordedScope);

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
