<?php

declare(strict_types=1);

namespace QmxTautologyControls;

use QmxFindingGateControls\Shell;
use ReflectionClass;
use RuntimeException;

/**
 * The cheap half of the bench: two claims about the *set* of controls that no
 * single planted breakage can make, because each is about every control at
 * once.
 *
 * 1. For every repaired case, removing its declaration from every control that
 *    carries it makes that case — and only that case — read as "guarded by
 *    nothing". Without this, a repaired case could be listed in
 *    {@see Controls::repaired()} and quietly claimed by nobody, and the run
 *    would still print a full table.
 * 2. A declared case name the run never carried is a **stale declaration**, a
 *    refusal of its own rather than a case that "stayed green". The two are
 *    fixed differently — one follows a rename, the other means a repair lost
 *    its guard — and conflating them is how a bench keeps printing a table
 *    after it stopped measuring. It is planted on the positive control on
 *    purpose: that control's {@see Outcome::asDeclared()} reads `red === []`
 *    and never looks at what stayed green, so only the staleness check can
 *    catch it there.
 *
 * Neither claim needs the minute and a half a full clone-and-plant pass costs:
 * both are arithmetic over the declarations against a fixed population, so the
 * population is measured once, in the working tree, with nothing planted.
 */

require __DIR__ . '/finding-gate-controls/Shell.php';
require __DIR__ . '/finding-gate-controls/Scratch.php';
require __DIR__ . '/finding-gate-controls/Mutation.php';

foreach (['Control', 'Controls', 'Suite', 'Outcome', 'Report'] as $part) {
    require __DIR__ . '/tautology-controls/' . $part . '.php';
}

/**
 * Every case one unmutated run of the population carries, measured in the
 * working tree — there is no breakage to isolate here, so there is no clone.
 *
 * @return list<string>
 */
function population(string $repository): array
{
    $log = tempnam(sys_get_temp_dir(), 'qmx-tautology-coverage-') . '.xml';

    try {
        $result = Shell::run([
            'vendor/bin/phpunit',
            '--no-coverage',
            '--do-not-cache-result',
            '--log-junit',
            $log,
            ...Suite::FILES,
        ], $repository);

        if (!is_file($log)) {
            throw new RuntimeException(\sprintf(
                "PHPUnit wrote no JUnit log, so the population is unknown.\nstdout: %s\nstderr: %s",
                $result['stdout'],
                $result['stderr'],
            ));
        }

        $document = @simplexml_load_string(Shell::read($log));

        if ($document === false) {
            throw new RuntimeException('The JUnit log is not readable XML.');
        }

        $names = [];

        foreach ($document->xpath('//testcase') ?? [] as $case) {
            $name = (string) $case['name'];

            if ($name !== '') {
                $names[] = (string) $case['classname'] . '::' . $name;
            }
        }

        if ($names === []) {
            throw new RuntimeException('The run carried no cases at all.');
        }

        sort($names);

        return $names;
    } finally {
        @unlink($log);
    }
}

/**
 * A clone of $control with $case removed from `reddens` alone — the tail stays,
 * because coverage is built from what a control claims as its own.
 */
function withoutFromReddens(Control $control, string $case): Control
{
    if (!\in_array($case, $control->reddens, true)) {
        return $control;
    }

    return rebuilt($control, array_values(array_diff($control->reddens, [$case])));
}

/** @param list<string> $reddens */
function rebuilt(Control $control, array $reddens): Control
{
    $reflection = new ReflectionClass(Control::class);
    $clone = $reflection->newInstanceWithoutConstructor();

    foreach (['id', 'row', 'claim', 'mutation', 'reddens', 'alsoReddens', 'fragments'] as $name) {
        $property = $reflection->getProperty($name);
        $property->setValue($clone, $name === 'reddens' ? $reddens : $property->getValue($control));
    }

    return $clone;
}

/**
 * @param list<Control> $controls
 * @param list<string> $population
 *
 * @return list<Outcome>
 */
function asDeclaredOutcomes(array $controls, array $population): array
{
    return array_map(
        // A declared name outside the population can never have gone red in a
        // real run; intersecting is what makes a stale declaration land where
        // a real clone would put it instead of being credited by construction.
        static fn(Control $control): Outcome => Outcome::of(
            $control,
            $population,
            array_values(array_intersect($control->declared(), $population)),
        ),
        $controls,
    );
}

/**
 * @param list<Outcome> $outcomes
 * @param list<string> $population
 *
 * @return array{output: string, exit: int}
 */
function printed(array $outcomes, array $population): array
{
    ob_start();
    $exit = Report::of($outcomes, $population, Controls::repaired())->print();
    $output = ob_get_clean();

    if ($output === false) {
        fwrite(\STDERR, "No output buffer was active to capture the report.\n");

        exit(1);
    }

    return ['output' => $output, 'exit' => $exit];
}

/** @return list<string> */
function unguardedNamesIn(string $output): array
{
    preg_match_all('/^  guarded by nothing: (.+)$/m', $output, $matches);

    return $matches[1];
}

$repository = \dirname(__DIR__);
$controls = Controls::all();
$population = population($repository);

$missingFromPopulation = array_values(array_diff(Controls::repaired(), $population));

if ($missingFromPopulation !== []) {
    fwrite(\STDERR, \sprintf(
        "%d repaired case(s) are not in the population at all, so the arithmetic below would be about nothing:\n  %s\n",
        \count($missingFromPopulation),
        implode("\n  ", $missingFromPopulation),
    ));

    exit(1);
}

$baseline = printed(asDeclaredOutcomes($controls, $population), $population);

if ($baseline['exit'] !== 0) {
    fwrite(\STDERR, \sprintf(
        "The declarations do not agree with the population before the checks even start:\n%s\n",
        $baseline['output'],
    ));

    exit(1);
}

$failures = [];

foreach (Controls::repaired() as $case) {
    $mutated = array_map(static fn(Control $control): Control => withoutFromReddens($control, $case), $controls);
    $result = printed(asDeclaredOutcomes($mutated, $population), $population);
    $unguarded = unguardedNamesIn($result['output']);

    if ($result['exit'] === 0 || $unguarded !== [$case]) {
        $failures[] = \sprintf(
            'removing every reddens declaration of "%s" produced unguarded=[%s] (exit %d), expected exactly ["%s"] and a non-zero exit',
            $case,
            implode(', ', $unguarded),
            $result['exit'],
            $case,
        );
    }
}

if ($failures !== []) {
    fwrite(\STDERR, implode("\n", $failures) . "\n");
    fwrite(\STDERR, \sprintf("%d of %d repaired cases failed the removal control.\n", \count($failures), \count(Controls::repaired())));

    exit(1);
}

printf(
    "removal control: %d repaired case(s), each one's declaration removed in turn — every removal left exactly its own case guarded by nothing.\n",
    \count(Controls::repaired()),
);

$staleName = 'Qualimetrix.Tests.Nowhere.NoSuchTest::itWasRenamedOrDeletedLongAgo';
$withStale = array_map(
    static fn(Control $control): Control => $control->isPositive()
        ? rebuilt($control, [...$control->reddens, $staleName])
        : $control,
    $controls,
);

$result = printed(asDeclaredOutcomes($withStale, $population), $population);

if ($result['exit'] === 0) {
    fwrite(\STDERR, "Staleness control: a run declaring a case that never ran exited 0. It must fail.\n");

    exit(1);
}

foreach ([
    'stale declaration: positive names "' . $staleName . '"',
    'declared a case that never ran: ' . $staleName,
] as $expected) {
    if (!str_contains($result['output'], $expected)) {
        fwrite(\STDERR, \sprintf(
            "Staleness control: expected a line containing %s, got:\n%s\n",
            var_export($expected, true),
            $result['output'],
        ));

        exit(1);
    }
}

if (str_contains($result['output'], 'stayed green: ' . $staleName)) {
    fwrite(\STDERR, "Staleness control: the stale name was also printed as an ordinary \"stayed green\" case.\n");

    exit(1);
}

print("staleness control: a declared case absent from the population exits non-zero and prints a distinct \"stale declaration\" line, not \"stayed green\".\n");

exit(0);
