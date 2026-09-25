<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The gate's two registries of itself: the loader names every class, and every failure class is witnessed.
 */
final class SelfTestRegistries extends SelfTestGroup
{
    /**
     * The shared loader names exactly the classes on disk.
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
        $listed = [];

        foreach (explode("\n", Fs::read(__DIR__ . '/classes.php')) as $line) {
            if (preg_match("~^\\s*'([A-Z]\\w+)',~", $line, $matched) === 1) {
                $listed[] = $matched[1];
            }
        }

        sort($listed);
        $this->same($onDisk, $listed, 'the shared loader names every class file in the directory, and no other');
    }

    /**
     * Every failure class is seen raised by a whole run, required by a control,
     * or pending with the package that introduces its producer.
     */
    public function witnessedFailureClasses(): void
    {
        $witnesses = CheckWitnesses::observe();

        $problems = [
            ...$witnesses['failures'],
            ...WitnessRegistry::problems(
                FailureClass::ALL,
                $witnesses['observed'],
                WitnessRegistry::requiredByControls(),
                WitnessRegistry::PENDING,
            ),
        ];

        foreach ($problems as $problem) {
            $this->failures[] = $problem;
        }
    }
}
