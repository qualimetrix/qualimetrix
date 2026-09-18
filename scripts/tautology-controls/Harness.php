<?php

declare(strict_types=1);

namespace QmxTautologyControls;

use QmxFindingGateControls\Scratch;
use QmxFindingGateControls\Shell;
use RuntimeException;
use Throwable;

/**
 * Plants one breakage at a time and reads which cases noticed.
 *
 * Every control gets its own clone of the working tree, so the rollback is a
 * tree that never carried the breakage rather than a `git checkout --` of the
 * file: checkout restores the last *commit*, not the state before the edit,
 * and in this repository that has already produced a green run over a tree
 * nobody meant to test. The clone hardlinks the tree's content and **copies**
 * `vendor/` rather than symlinking it, because a symlinked `vendor/` makes
 * Composer resolve PSR-4 into the source tree and every comparison vacuous
 * while looking green — five consecutive false greens, measured.
 *
 * Runs are sequential. The whole population is fourteen files and two and a
 * half seconds, so the clone dominates and a pool would buy little at the cost
 * of interleaved output.
 */
final class Harness
{
    public static function main(): int
    {
        $repository = \dirname(__DIR__, 2);

        Shell::superviseFor(true, static function (string $reason): void {
            fwrite(\STDERR, \sprintf("Interrupted: %s.\n", $reason));
        });

        $controls = Controls::all();
        $positive = array_values(array_filter($controls, static fn(Control $c): bool => $c->isPositive()));

        if (\count($positive) !== 1) {
            fwrite(\STDERR, \sprintf("Expected exactly one positive control, found %d.\n", \count($positive)));

            return 2;
        }

        $outcomes = [];
        $universe = [];

        foreach ($controls as $control) {
            $outcome = self::run($control, $repository);
            $outcomes[] = $outcome;

            if ($control->isPositive()) {
                if (!$outcome->asDeclared()) {
                    fwrite(\STDERR, \sprintf(
                        "The unmutated clone is not green (%s), so nothing below would prove anything:\n  %s\n",
                        $outcome->refusal ?? 'red cases',
                        implode("\n  ", $outcome->red),
                    ));

                    return 2;
                }

                $universe = $outcome->cases;
            }
        }

        return Report::of($outcomes, $universe, Controls::repaired())->print();
    }

    private static function run(Control $control, string $repository): Outcome
    {
        $scratch = null;

        try {
            $scratch = Scratch::contentOf($repository);
            $control->mutation->apply($scratch, $repository);

            $suite = Suite::runIn($scratch);

            return Outcome::of($control, $suite->names(), $suite->red());
        } catch (RuntimeException $error) {
            return Outcome::refused($control, $error->getMessage());
        } catch (Throwable $error) {
            return Outcome::refused($control, $error::class . ': ' . $error->getMessage());
        } finally {
            $scratch?->remove();
        }
    }
}
