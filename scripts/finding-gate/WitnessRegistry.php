<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Every place the gate raises a failure class has a witness, or the class says
 * which package will give it a producer.
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
 *
 * A class whose producer a later package introduces may stand here as
 * `pending: S01b/P<n>` with a reason. A pending class that is raised anywhere
 * is a stale row and fails like a stale map row does.
 */
final class WitnessRegistry
{
    /** @var array<string, array{0: string, 1: string}> class => [marker, reason] */
    public const array PENDING = [
        FailureClass::CORPUS_INVALID => [
            'pending: S01b/P5',
            'Declared and raised nowhere: a corpus defect is a GateError with exit 3 today. S01b/P5 gives case'
            . ' inputs their refusal, and this class its producer.',
        ],
    ];

    private const string MARKER = '~^pending: S01b/P[2-8]$~';

    /**
     * @param list<string> $classes
     * @param array<string, string> $sites identity => the class it raises; see {@see RaiseSites}
     * @param list<string> $observed the identities a self-test run was seen raising at
     * @param array<string, array{0: string, 1: string}> $pending
     *
     * @return list<string>
     */
    public static function problems(array $classes, array $sites, array $observed, array $pending): array
    {
        $problems = [];

        foreach (array_keys($pending) as $class) {
            if (!\in_array($class, $classes, true)) {
                $problems[] = \sprintf('witness registry: the pending row names "%s", which is not a failure class.', $class);
            }
        }

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
            $row = $pending[$class] ?? null;

            if ($row === null) {
                if (!isset($raisedAt[$class])) {
                    $problems[] = \sprintf(
                        'witness registry: %s is raised nowhere in the gate\'s source and stands in no pending row.',
                        $class,
                    );
                }

                continue;
            }

            if (isset($raisedAt[$class])) {
                $problems[] = \sprintf(
                    'witness registry: %s is raised at %s and still stands as "%s". Remove the pending row.',
                    $class,
                    implode(', ', $raisedAt[$class]),
                    $row[0],
                );

                continue;
            }

            if (preg_match(self::MARKER, $row[0]) !== 1 || trim($row[1]) === '') {
                $problems[] = \sprintf(
                    'witness registry: %s is pending as "%s" with reason "%s". A pending row names the S01b package'
                    . ' that introduces the producer ("pending: S01b/P<n>") and says why it waits.',
                    $class,
                    $row[0],
                    $row[1],
                );
            }
        }

        return $problems;
    }
}
