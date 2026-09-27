<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Every failure class has a producer, and every raise site has a witness.
 *
 * The unit is the raise site and the caller that reached it, not the class. A
 * class raised by several checks is only as witnessed as the check a run
 * actually tripped: counting the class let three of the five `run-failed`
 * checks lose their bodies under a green self-test, and counting the site let
 * a mode drop its call into a shared check. So the identities are read off the
 * gate's source ({@see RaiseSites}), the identity of every failure a
 * {@see CheckWitnesses} run raised is taken from the report itself, and one no
 * expectation matched is a guard whose removal leaves the self-test green.
 *
 * Only what the self-test observes counts. `gate:controls` runs neither in
 * `composer check` nor in CI, so a class a control requires is a declaration
 * nobody executes on the way to a merge.
 */
final class WitnessRegistry
{
    /**
     * @param list<string> $classes
     * @param array<string, string> $sites identity => the class it raises; see {@see RaiseSites}
     * @param list<string> $observed the identities a self-test run was seen raising at
     *
     * @return list<string>
     */
    public static function problems(array $classes, array $sites, array $observed): array
    {
        $problems = [];

        $raisedAt = [];

        foreach ($sites as $site => $class) {
            $raisedAt[$class][] = $site;

            if (!\in_array($site, $observed, true)) {
                $problems[] = \sprintf(
                    'witness registry: %s raised at %s has no witness. No self-test run observed it raised there,'
                    . ' so removing it would leave the self-test green.',
                    $class,
                    $site,
                );
            }
        }

        foreach ($classes as $class) {
            if (!isset($raisedAt[$class])) {
                $problems[] = \sprintf(
                    'witness registry: %s is raised nowhere in the gate\'s source.',
                    $class,
                );
            }
        }

        return $problems;
    }
}
