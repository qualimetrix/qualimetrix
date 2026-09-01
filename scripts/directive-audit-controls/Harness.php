<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

use QmxFindingGateControls\Scratch;
use RuntimeException;
use Throwable;

/**
 * Proves the threshold audit's test suite bites.
 *
 * A detector that answers "inert" to everything passes a naive reading: there
 * are few genuinely dead directives in any tree, so "zero inert" looks the same
 * from a working detector and from a broken one. What separates them is whether
 * the suite that guards the detector goes red when the detector is broken — so
 * each breakage below is planted on its own hardlink clone, the suite is run
 * there, and the harness reports which cases noticed.
 *
 * Four conditions decide the run, and "each mutation reddens only its own case"
 * is not among them. It was, in the first edition of the plan, and measurement
 * refuted it: a breakage in the outcome comparison reddens nearly every case at
 * once, which is its purpose. What is checkable:
 *
 * 1. every probe reddens the cases it declares — a claim with no case behind it
 *    is a claim nobody checks;
 * 2. every case is reddened by at least one probe — a case nothing can break is
 *    not evidence about anything;
 * 3. no probe reddens every case — a breakage that fails the whole suite says
 *    nothing about which claim it broke;
 * 4. a mutation that no longer applies is a refusal, not a skip. `Mutation`
 *    enforces that itself, by demanding exactly one occurrence of what it
 *    rewrites; this harness only has to let the refusal through rather than
 *    reading "reddened nothing" as a result. The P2 prototype read it as a
 *    result three times.
 */
final class Harness
{
    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        $repository = \dirname(__DIR__, 2);
        $only = self::only($arguments);
        $probes = array_values(array_filter(
            Probes::all(),
            static fn(Probe $probe): bool => $only === [] || \in_array($probe->id, $only, true),
        ));

        if ($probes === []) {
            fwrite(\STDERR, "No probe matches --only.\n");

            return 3;
        }

        $outcomes = [];

        foreach ($probes as $probe) {
            $outcomes[] = self::observe($probe, $repository);
        }

        return Report::of($outcomes, $only !== [])->print();
    }

    /**
     * One probe: clone, plant, run, read.
     *
     * The clone is thrown away whatever happens, including on a refusal — a
     * scratch tree left behind is a hardlink farm pointing at the developer's
     * working tree.
     */
    private static function observe(Probe $probe, string $repository): Outcome
    {
        $scratch = Scratch::cloneOf($repository);

        try {
            $probe->mutation->apply($scratch, $repository);
            $suite = Suite::runIn($scratch->tree);

            return Outcome::of($probe, $suite->names(), $suite->red());
        } catch (Throwable $failure) {
            return Outcome::refused($probe, $failure->getMessage());
        } finally {
            $scratch->remove();
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private static function only(array $arguments): array
    {
        $only = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--only=')) {
                $only = [...$only, ...array_filter(
                    explode(',', substr($argument, 7)),
                    static fn(string $id): bool => $id !== '',
                )];

                continue;
            }

            if (str_starts_with($argument, '--')) {
                throw new RuntimeException(\sprintf('Unknown option %s.', $argument));
            }
        }

        return array_values($only);
    }
}
