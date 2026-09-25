<?php

declare(strict_types=1);

/**
 * Loads the gate's classes. There is no autoloader here on purpose — the gate
 * runs against two trees at once and must not be autoloaded from either.
 *
 * One list, in one file, because the alternative was measured: every entry
 * point that reuses these classes used to carry a hand-picked subset of these
 * requires, so a class that acquired a dependency broke every consumer that had
 * not guessed it.
 * `Process` gaining a call to `Interruption` did exactly that on 2026-09-14 —
 * `composer check` went red in the directive audit, whose subset stopped at
 * `Process.php`, with a fatal three frames inside a file it had loaded
 * correctly. `finding-gate-controls.php` carries the same scar in a comment:
 * "Measured the hard way: without these, every control crashed on a missing
 * class."
 *
 * `require_once`, so a consumer that also names a file of its own is not a
 * redeclaration error.
 */

foreach (
    [
        'CommandLine',
        'FailureClass',
        'GateError',
        'BudgetExceeded',
        'Interrupted',
        'Interruption',
        'Scratch',
        'Fs',
        'Tsv',
        'Process',
        'ProcessHandle',
        'Surfaces',
        'MetricVocabulary',
        'SubjectLevel',
        'Diff',
        'ExactDiff',
        'GateReport',
        'Options',
        'CaseDefinition',
        'Corpus',
        'RenameMaps',
        'ChannelSplit',
        'PublishedVocabulary',
        'DeclaredDelta',
        'DeclaredFieldMoves',
        'NormalizationRule',
        'Normalization',
        'NormalizationDeriver',
        'EquivalenceTuple',
        'FingerprintSubstitution',
        'Fingerprints',
        'PublishedOrder',
        'ReportPayload',
        'ChannelWitness',
        'ChannelCoverage',
        'TreeRun',
        'CaseScheduler',
        'ReferenceTree',
        'Gate',
        'SyntheticTree',
        'CheckWitnesses',
        'WitnessRegistry',
        'SelfTest',
    ] as $class
) {
    require_once __DIR__ . '/' . $class . '.php';
}
