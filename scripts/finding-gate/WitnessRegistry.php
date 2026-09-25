<?php

declare(strict_types=1);

namespace QmxFindingGate;

use QmxFindingGateControls\Controls;

/**
 * Every failure class has a witness, or says which package will give it one.
 *
 * A witness is either observed — an expectation of {@see CheckWitnesses}
 * matched a failure a whole run raised — or required by a `gate:controls`
 * control. A class with neither is a guard whose removal leaves both suites
 * green. Counting what a group of cases declares instead of what it raised
 * would hide exactly that, so observations come from the reports themselves.
 *
 * A class whose producer a later package introduces may stand here as
 * `pending: S01b/P<n>` with a reason. A pending class that is witnessed is a
 * stale row and fails like a stale map row does.
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
     * The classes some control of `gate:controls` requires, read from the controls themselves.
     *
     * @return list<string>
     */
    public static function requiredByControls(): array
    {
        require_once \dirname(__DIR__) . '/finding-gate-controls/classes.php';

        $required = [];

        foreach (Controls::all() as $control) {
            foreach ($control->required as $expectation) {
                $required[] = $expectation->failureClass;
            }
        }

        return array_values(array_unique($required));
    }

    /**
     * @param list<string> $classes
     * @param list<string> $observed
     * @param list<string> $required
     * @param array<string, array{0: string, 1: string}> $pending
     *
     * @return list<string>
     */
    public static function problems(array $classes, array $observed, array $required, array $pending): array
    {
        $problems = [];

        foreach (array_keys($pending) as $class) {
            if (!\in_array($class, $classes, true)) {
                $problems[] = \sprintf('witness registry: the pending row names "%s", which is not a failure class.', $class);
            }
        }

        foreach ($classes as $class) {
            $witnessed = \in_array($class, $observed, true) || \in_array($class, $required, true);
            $row = $pending[$class] ?? null;

            if ($row === null) {
                if (!$witnessed) {
                    $problems[] = \sprintf(
                        'witness registry: %s has no witness. No self-test run observed it and no gate:controls'
                        . ' control requires it, so removing its check would leave both green.',
                        $class,
                    );
                }

                continue;
            }

            if ($witnessed) {
                $problems[] = \sprintf(
                    'witness registry: %s is witnessed and still stands as "%s". Remove the pending row.',
                    $class,
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
