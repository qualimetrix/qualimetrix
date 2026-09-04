<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

use QmxFindingGateControls\Shell;
use ReflectionClass;

/**
 * Proves two claims about `Report::unguarded()` and `Report::print()` that no
 * single planted breakage can prove by itself, because both are claims about
 * *every* case rather than about one:
 *
 * 1. for every case any probe declares in `reddens`, removing that
 *    declaration from every probe that carries it makes that case — and only
 *    that case — read as "guarded by nothing";
 * 2. a declared case name absent from the run's own case universe never turns
 *    red, so it is a distinct "stale declaration" refusal rather than an
 *    ordinary case that "stayed green" — even when the probe carrying it is
 *    the positive one, which `asDeclared()` never fails on `missing` alone.
 *
 * Neither claim needs the ~8 minutes a full clone-and-run pass over every
 * probe in {@see Probes::all()} costs. Both are arithmetic over
 * `Probe::declared()` against a fixed case universe, which is what
 * `Report::unguarded()` and the private `staleDeclarations()` it calls
 * compute — the arithmetic is exercised directly, through the same public
 * `Report::print()` the harness itself calls, over synthetic `Outcome`s built
 * from that arithmetic rather than from a clone's JUnit log.
 *
 * The universe those `Outcome`s are checked against is not the union of what
 * probes declare — a case missing from every declaration would then be
 * missing from the universe too, and the exhaustive check below would never
 * see it. It is the case list a single unmutated run of {@see Suite::FILES}
 * actually produced, exactly as `Harness` builds it per clone, just without
 * planting a breakage first.
 *
 * `Probe`'s properties are `readonly`, so the one way to test "with this
 * probe's declaration of case X removed" is to build a modified clone through
 * reflection rather than through the constructor, which is private and does
 * not expose a way to shrink `reddens` after the fact.
 */
require __DIR__ . '/finding-gate-controls/Shell.php';
require __DIR__ . '/finding-gate-controls/Mutation.php';
require __DIR__ . '/directive-audit-controls/Probe.php';
require __DIR__ . '/directive-audit-controls/Probes.php';
require __DIR__ . '/directive-audit-controls/Suite.php';
require __DIR__ . '/directive-audit-controls/Outcome.php';
require __DIR__ . '/directive-audit-controls/Report.php';

/**
 * Runs {@see Suite::FILES} once, unmutated, directly in the working tree —
 * there is no breakage to isolate in a scratch clone here — and returns every
 * case name that run actually executed.
 *
 * @return list<string>
 */
function realCaseUniverse(string $repository): array
{
    $log = tempnam(sys_get_temp_dir(), 'qmx-coverage-control-') . '.xml';

    try {
        $result = Shell::run([
            'vendor/bin/phpunit',
            '--no-coverage',
            '--do-not-cache-result',
            '--log-junit',
            $log,
            ...Suite::FILES,
        ], $repository);

        return Suite::of($result, $log)->names();
    } finally {
        @unlink($log);
    }
}

/**
 * @param list<Probe> $probes
 * @param list<string> $universe
 *
 * @return list<Outcome>
 */
function asDeclaredOutcomes(array $probes, array $universe): array
{
    return array_map(
        static function (Probe $probe) use ($universe): Outcome {
            // A name outside the universe can never have turned red in a real
            // run — intersecting is what makes a stale declaration land in
            // `missing`, the way it would on a real clone, instead of being
            // credited as red by construction.
            $red = array_values(array_intersect($probe->declared(), $universe));

            return Outcome::of($probe, $universe, $red);
        },
        $probes,
    );
}

/** @return array{output: string, exit: int} */
function printed(Report $report): array
{
    ob_start();
    $exit = $report->print();
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

/**
 * A clone of $probe with $case removed from `reddens` alone — `alsoReddens`
 * stays untouched, because the claim under test is specifically about
 * coverage built from `reddens`.
 */
function withoutFromReddens(Probe $probe, string $case): Probe
{
    if (!\in_array($case, $probe->reddens, true)) {
        return $probe;
    }

    $reflection = new ReflectionClass(Probe::class);
    $clone = $reflection->newInstanceWithoutConstructor();

    foreach (['id', 'claim', 'mutation', 'reddens', 'blanket', 'alsoReddens'] as $name) {
        $property = $reflection->getProperty($name);
        $value = $property->getValue($probe);

        if ($name === 'reddens') {
            $value = array_values(array_diff($value, [$case]));
        }

        $property->setValue($clone, $value);
    }

    return $clone;
}

function withExtraReddens(Probe $probe, string $case): Probe
{
    $reflection = new ReflectionClass(Probe::class);
    $clone = $reflection->newInstanceWithoutConstructor();

    foreach (['id', 'claim', 'mutation', 'reddens', 'blanket', 'alsoReddens'] as $name) {
        $property = $reflection->getProperty($name);
        $value = $property->getValue($probe);

        if ($name === 'reddens') {
            $value = [...$value, $case];
        }

        $property->setValue($clone, $value);
    }

    return $clone;
}

$repository = \dirname(__DIR__);
$probes = Probes::all();
$universe = realCaseUniverse($repository);

$baseline = printed(Report::of(asDeclaredOutcomes($probes, $universe), false));

if ($baseline['exit'] !== 0) {
    fwrite(\STDERR, \sprintf(
        "Baseline is not clean before the exhaustive check even starts, so a positive result below would prove nothing:\n%s\n",
        $baseline['output'],
    ));

    exit(1);
}

// Claim 1: removing any one case's declaration(s) from `reddens` makes that
// case, and only that case, read as guarded by nothing. Checked over the
// whole universe rather than one sample case, per the plan's own objection to
// a single planted breakage: "if any one case has its declaration removed" is
// universal, and one probe does not establish a universal.
$failures = [];

foreach ($universe as $case) {
    $mutated = array_map(static fn(Probe $probe): Probe => withoutFromReddens($probe, $case), $probes);
    $result = printed(Report::of(asDeclaredOutcomes($mutated, $universe), false));
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
    fwrite(\STDERR, \sprintf("%d of %d cases failed the removal control.\n", \count($failures), \count($universe)));

    exit(1);
}

printf(
    "removal control: %d cases, each one's declaration removed in turn — every removal reddened exactly its own case and no other.\n",
    \count($universe),
);

// Claim 2: a declared name absent from the universe never turns red, so it
// lands in `missing` exactly as a real rename or removal would, and is
// printed and gated as a distinct refusal rather than folded into either
// "stayed green" or an ordinary "not as declared" failure. Planted on the
// positive probe specifically: `asDeclared()` returns `red === []` for it and
// never inspects `missing`, so on this probe alone a stale declaration cannot
// fail the run through the ordinary channel every other probe would fail
// through — only the staleness check can catch it here, which is what makes
// this construction, and not a planted breakage on any other probe, able to
// tell the current `Report` apart from the one before X7 that had no
// staleness check at all.
$staleName = 'Qualimetrix.Tests.Nowhere.NoSuchTest::itWasRenamedOrDeletedLongAgo';
$positive = array_values(array_filter($probes, static fn(Probe $probe): bool => $probe->isPositive()));

if (\count($positive) !== 1) {
    fwrite(\STDERR, \sprintf("Expected exactly one positive probe, found %d.\n", \count($positive)));

    exit(1);
}

$withStaleDeclaration = array_map(
    static fn(Probe $probe): Probe => $probe->isPositive() ? withExtraReddens($probe, $staleName) : $probe,
    $probes,
);

$result = printed(Report::of(asDeclaredOutcomes($withStaleDeclaration, $universe), false));

$staleLine = \sprintf('stale declaration: positive names "%s"', $staleName);
$neverRanLine = 'declared a case that never ran: ' . $staleName;

if ($result['exit'] === 0) {
    fwrite(\STDERR, "Staleness control: a run declaring a nonexistent case on the positive probe exited 0. It must fail.\n");

    exit(1);
}

if (!str_contains($result['output'], $staleLine)) {
    fwrite(\STDERR, \sprintf(
        "Staleness control: expected a line containing %s, got:\n%s\n",
        var_export($staleLine, true),
        $result['output'],
    ));

    exit(1);
}

if (!str_contains($result['output'], $neverRanLine)) {
    fwrite(\STDERR, \sprintf(
        "Staleness control: expected the per-outcome line %s, got:\n%s\n",
        var_export($neverRanLine, true),
        $result['output'],
    ));

    exit(1);
}

if (str_contains($result['output'], 'stayed green: ' . $staleName)) {
    fwrite(\STDERR, "Staleness control: the stale name was also printed as an ordinary \"stayed green\" case.\n");

    exit(1);
}

print("staleness control: a declared case absent from the universe exits non-zero and prints a distinct \"stale declaration\" line, not \"stayed green\".\n");

exit(0);
