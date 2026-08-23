<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The finding-equivalence gate.
 *
 * Proves that a step of docs/internal/plans/rule-vocabulary/PLAN.md changed
 * nothing observable except what a declared map says it changed. See
 * finding-gate/README.md for the corpus layout and the surface list.
 *
 * Deliberately outside `src/`: it is not product code, it must run against two
 * trees at once, and it must keep working while the product's own vocabulary is
 * being renamed under it.
 */

foreach (
    [
        'CommandLine',
        'FailureClass',
        'GateError',
        'Fs',
        'Tsv',
        'Process',
        'Surfaces',
        'Diff',
        'GateReport',
        'Options',
        'CaseDefinition',
        'Corpus',
        'RenameMaps',
        'NormalizationRule',
        'Normalization',
        'NormalizationDeriver',
        'EquivalenceTuple',
        'Fingerprints',
        'ChannelWitness',
        'TreeRun',
        'ReferenceTree',
        'Gate',
        'SelfTest',
    ] as $class
) {
    require __DIR__ . '/finding-gate/' . $class . '.php';
}

exit(main(CommandLine::arguments()));

/** @param list<string> $argv */
function main(array $argv): int
{
    try {
        $options = Options::parse($argv, \dirname(__DIR__));

        return match ($options->mode) {
            Options::MODE_SELF_TEST => selfTest($options),
            Options::MODE_DERIVE_TUPLE => deriveTuple($options),
            Options::MODE_DERIVE_NORMALIZATION => deriveNormalization($options),
            default => compare($options),
        };
    } catch (GateError $error) {
        fwrite(\STDERR, 'finding-gate: ' . $error->getMessage() . "\n");

        return 3;
    }
}

function compare(Options $options): int
{
    $report = new GateReport();
    $gate = new Gate($options, $report);

    // A worktree left behind on a crash would be picked up as a stale checkout
    // by the next run, so cleanup is registered before anything can fail.
    register_shutdown_function($gate->cleanUp(...));

    $gate->compare();

    echo $report->render();

    if ($options->reportPath !== null) {
        $report->writeJson($options->reportPath);
    }

    return $report->exitCode();
}

function deriveTuple(Options $options): int
{
    $path = $options->candidateRoot . '/finding-gate/equivalence-tuple.tsv';
    Fs::write($path, EquivalenceTuple::derive($options->candidateRoot)->render());
    echo 'Derived ' . $path . " from the publishing code.\n";

    return 0;
}

function deriveNormalization(Options $options): int
{
    $path = $options->candidateRoot . '/finding-gate/normalization.tsv';
    Fs::write($path, (new Gate($options, new GateReport()))->deriveNormalization());
    echo 'Measured ' . $path . " from repeated runs of the candidate tree.\n";

    return 0;
}

function selfTest(Options $options): int
{
    $failures = (new SelfTest($options->candidateRoot))->run();

    foreach ($failures as $failure) {
        echo '  FAIL  ', $failure, "\n";
    }

    echo $failures === [] ? "  self-test green\n" : \sprintf("  self-test RED (%d)\n", \count($failures));

    return $failures === [] ? 0 : 1;
}
