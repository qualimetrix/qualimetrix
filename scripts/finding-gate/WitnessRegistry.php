<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Every failure class has a source producer and an observed whole-run witness.
 *
 * For classes with exact coverage, the unit is the raise site and its caller.
 * Those identities come from the gate's source ({@see RaiseSites}); a witness
 * counts one only when its actual report failure matches that identity.
 *
 * RUN_FAILED has a narrower class/side/scope witness claim: native publication
 * routing can make defensive helper sites unreachable in a whole run. Scoped
 * observations do not count as exact site or caller coverage. All other
 * classes retain the exact-site claim. Scheduled controls are not per-PR merge
 * evidence, while both gate self-tests remain part of `check:gate`.
 */
final class WitnessRegistry
{
    /** @var list<string> */
    private const NARROWED_CLASSES = [FailureClass::RUN_FAILED];

    /**
     * @param list<string> $classes
     * @param array<string, string> $sites identity => the class it raises; see {@see RaiseSites}
     * @param list<string> $observed the identities a self-test run was seen raising at
     * @param list<string>|null $scoped classes observed by class/side/scope; null keeps exact-site registry arithmetic
     *
     * @return list<string>
     */
    public static function problems(array $classes, array $sites, array $observed, ?array $scoped = null): array
    {
        $problems = [];

        $raisedAt = [];

        foreach ($sites as $site => $class) {
            $raisedAt[$class][] = $site;

            if ($scoped !== null && \in_array($class, self::NARROWED_CLASSES, true)) {
                continue;
            }

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
            if ($scoped !== null && \in_array($class, self::NARROWED_CLASSES, true) && !\in_array($class, $scoped, true)) {
                $problems[] = \sprintf('witness registry: %s has no observed class/side/scope witness.', $class);
            }
        }

        foreach ($scoped ?? [] as $class) {
            if (!\in_array($class, self::NARROWED_CLASSES, true)) {
                $problems[] = \sprintf('witness registry: %s is observed without an exact site but has no narrowed registry claim.', $class);
            }
        }

        return $problems;
    }
}
