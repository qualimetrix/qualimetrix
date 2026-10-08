<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The gate's two registries of itself: the loader names every class, and every failure class is witnessed.
 */
final class SelfTestRegistries extends SelfTestGroup
{
    /**
     * The shared loader and the forms' wiring files together name exactly the
     * classes on disk.
     *
     * The loader exists because a hand-picked subset of requires broke every
     * consumer that had not guessed a new dependency. A list that drifts from
     * the directory brings that back one class at a time, and the symptom is
     * again a fatal three frames inside a file that did load.
     */
    public function loaderNamesEveryClass(): void
    {
        $files = glob(__DIR__ . '/*.php');
        $onDisk = [];

        foreach ($files === false ? [] : $files as $file) {
            $name = basename($file, '.php');

            // Lower-case files are the loader itself and its kin, not classes.
            if ($name !== '' && ucfirst($name) === $name) {
                $onDisk[] = $name;
            }
        }

        sort($onDisk);
        $listed = Wiring::gate()->list('classes');

        foreach (explode("\n", Fs::read(__DIR__ . '/classes.php')) as $line) {
            if (preg_match("~^\\s*'([A-Z]\\w+)',~", $line, $matched) === 1) {
                $listed[] = $matched[1];
            }
        }

        sort($listed);
        $this->same($onDisk, $listed, 'the shared loader names every class file in the directory, and no other');
    }

    /**
     * Every non-narrowed raise site and caller is seen in a whole run;
     * run-failed is witnessed by class, side and scope. Every mode is seen
     * deciding what it writes. Retired native fingerprint classes retain
     * helper source sites without claiming whole-run witness coverage.
     */
    public function witnessedFailureClasses(): void
    {
        $sites = RaiseSites::of(__DIR__, RaiseSites::DECLARED_NAMES);
        $witnesses = CheckWitnesses::observe($sites);

        $problems = [
            ...$sites->problems,
            ...$witnesses['failures'],
            ...WitnessRegistry::problems(
                FailureClass::ALL,
                array_map(static fn(array $site): string => $site['class'], $sites->sites),
                $witnesses['observed'],
                $witnesses['scoped'],
                array_keys(FailureClass::NATIVE_WITNESS_RETIREMENTS),
            ),
        ];

        foreach ($problems as $problem) {
            $this->failures[] = $problem;
        }
    }
}
