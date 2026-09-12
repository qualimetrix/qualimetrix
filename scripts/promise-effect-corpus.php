<?php

declare(strict_types=1);

/**
 * Runs the product over every legitimately-authored configuration document
 * tracked in this tree (`docs/internal/plans/promise-effect/measurement/legitimate-configs.tsv`)
 * and counts how many of them the product now refuses.
 *
 * The round's default is inverted: "does not recognize a value's form" now
 * means refuse (exit 3) instead of silently accepting it. That default's price
 * is a legitimate document being refused, and benchmarks cannot measure that
 * price -- they carry no configuration. This corpus is the measurement
 * instrument: every package that introduces a new refusal re-runs it and
 * reports the count of legitimate refusals it produced. Zero is required;
 * a non-zero count means the cure over-reaches and needs either a narrower
 * check or a documented, reasoned exception line in the corpus artifact.
 *
 * Cache protocol (see promise-effect/README.md "The cache is not in the
 * trusted chain"): `--no-cache` does not hold in this tree, so it is never
 * used. Every run gets its own cache directory, named explicitly and unique
 * to the document, deleted before the run. If the product's default
 * `.qmx-cache` appears in the run's working directory afterwards, that run
 * FAILS the probe outright (the product ignored the directory it was given)
 * rather than being reported as a normal outcome.
 *
 * Usage: php scripts/promise-effect-corpus.php
 * Exit: 0 when the legitimate-refusal count is zero, 1 when it is greater
 * than zero (offending documents are named), 2 on a probe-protocol failure
 * (a cache directory was ignored, or a process could not be started).
 */

namespace Qualimetrix\PromiseEffectCorpus;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

$repositoryRoot = \dirname(__DIR__);
require $repositoryRoot . '/vendor/autoload.php';

final class Document
{
    /**
     * @param list<string> $paths analysis paths, relative to $directory
     * @param list<string> $extraArguments additional CLI arguments (rule-opts, disable-rule, etc.)
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $directory,
        public readonly ?string $config,
        public readonly array $paths,
        public readonly array $extraArguments = [],
        public readonly ?string $preset = null,
    ) {}
}

final class ProbeProtocolFailure extends RuntimeException {}

/**
 * Documents in the corpus that are, BY DESIGN, expected to exit non-zero for
 * a reason that has nothing to do with the round's cure.
 *
 * `ExitCodeResolver::hasConfigurationError()` forces exit 2 whenever a
 * finding lands on a channel a rule itself declares as a configuration
 * error (`ConfigurationValidatorInterface`) -- this bypasses `--fail-on`
 * entirely and is a pre-existing, orthogonal mechanism (a rule reporting
 * that ITS OWN input, e.g. an inline `@qmx-threshold` name or an
 * architecture layer, does not resolve). It is unrelated to
 * `ConfigurationRefusal` (exit 3), which is what this round's cure changes.
 * Four corpus documents legitimately hit this path:
 *
 *   - finding-gate/cases/annotations: seven DELIBERATELY broken inline
 *     directives (case.json's own description) -- exit 2 is the case
 *     working as designed, not a refusal.
 *   - finding-gate/cases/layers: an intentionally unassigned class,
 *     `--rule-opt=architecture.unassigned-class:mode=error`.
 *   - tests/.../NarrowControl: `UnjudgeableThresholds.php` intentionally
 *     names a threshold that resolves no channel (its own purpose, per
 *     the fixture's file name).
 *   - qmx.yaml (root): narrowing the analyzed path to src/Core for a cheap
 *     probe leaves the document's declared architecture layers without any
 *     matching class in scope, tripping `architecture.unreachable-layer`
 *     -- an artifact of this probe's own path choice, not of the document.
 *
 * @var array<string, int>
 */
const KNOWN_ANOMALIES = [
    'finding-gate/cases/annotations' => 2,
    'finding-gate/cases/layers' => 2,
    'tests/Analysis/Policy/Inline/Fixtures/NarrowControl' => 2,
    'qmx.yaml' => 2,
];

/**
 * @return list<Document>
 */
function buildCorpus(string $repositoryRoot): array
{
    $documents = [];

    // 18 finding-gate corpus cases: each case.json names its own paths,
    // config file and extra CLI arguments. Read dynamically so this corpus
    // never drifts from what the gate itself runs.
    $caseDirectories = glob($repositoryRoot . '/finding-gate/cases/*', \GLOB_ONLYDIR);
    $caseDirectories = $caseDirectories === false ? [] : $caseDirectories;
    sort($caseDirectories);

    foreach ($caseDirectories as $caseDirectory) {
        $caseFile = $caseDirectory . '/case.json';

        if (!is_file($caseFile)) {
            continue;
        }

        $case = json_decode((string) file_get_contents($caseFile), true, flags: \JSON_THROW_ON_ERROR);

        $documents[] = new Document(
            identifier: 'finding-gate/cases/' . basename($caseDirectory),
            directory: $caseDirectory,
            config: $case['config'],
            paths: $case['paths'],
            extraArguments: $case['args'] ?? [],
        );
    }

    // 6 unit-test fixtures under tests/, each a self-contained directory
    // holding its own qmx.yaml plus the PHP it configures.
    $documents[] = new Document(
        'tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/cbo',
        $repositoryRoot . '/tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/cbo',
        'qmx.yaml',
        ['.'],
    );
    $documents[] = new Document(
        'tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/cleanup',
        $repositoryRoot . '/tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/cleanup',
        'qmx.yaml',
        ['.'],
    );
    $documents[] = new Document(
        'tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/dogfood',
        $repositoryRoot . '/tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/dogfood',
        'qmx.yaml',
        ['src'],
    );
    $documents[] = new Document(
        'tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/duplication-repair',
        $repositoryRoot . '/tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/duplication-repair',
        'qmx.yaml',
        ['.'],
    );
    $documents[] = new Document(
        'tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/duplication',
        $repositoryRoot . '/tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/duplication',
        'qmx.yaml',
        ['.'],
    );
    $documents[] = new Document(
        'tests/Analysis/Policy/Inline/Fixtures/NarrowControl',
        $repositoryRoot . '/tests/Analysis/Policy/Inline/Fixtures/NarrowControl',
        'qmx.yaml',
        ['.'],
    );

    // 3 input-doors stand fixtures, each a self-contained tree with its own
    // qmx.yaml declaring `paths: [src]`; the CLI is given the same path
    // explicitly rather than relying on the document's own declaration, so
    // this probe does not depend on that key staying present.
    $documents[] = new Document(
        'input-doors/fixtures/main',
        $repositoryRoot . '/input-doors/fixtures/main',
        'qmx.yaml',
        ['src'],
    );
    $documents[] = new Document(
        'input-doors/fixtures/layers',
        $repositoryRoot . '/input-doors/fixtures/layers',
        'qmx.yaml',
        ['src'],
    );
    $documents[] = new Document(
        'input-doors/fixtures/git',
        $repositoryRoot . '/input-doors/fixtures/git',
        'qmx.yaml',
        ['src'],
    );

    // Root qmx.yaml: this repository's own dogfooding configuration. Analyzed
    // against src/Core rather than the whole tree -- cheap, but the document
    // is applied for real, not against a synthetic stand-in.
    $documents[] = new Document(
        'qmx.yaml',
        $repositoryRoot,
        'qmx.yaml',
        ['src/Core'],
    );

    // 3 built-in presets. Run against promise-effect/fixtures/probe, the only
    // tracked fixture tree that carries no qmx.yaml of its own, so the
    // outcome is attributable to the preset document alone.
    foreach (['ci', 'legacy', 'strict'] as $preset) {
        $documents[] = new Document(
            'preset:' . $preset,
            $repositoryRoot . '/promise-effect/fixtures/probe',
            null,
            ['src'],
            preset: $preset,
        );
    }

    return $documents;
}

/**
 * @param list<string> $command
 *
 * @return array{exit: int, stdout: string, stderr: string}
 */
function runProcess(array $command, string $workingDirectory): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $handle = proc_open($command, $descriptors, $pipes, $workingDirectory);

    if (!\is_resource($handle)) {
        throw new ProbeProtocolFailure(\sprintf('Cannot start %s in %s.', implode(' ', $command), $workingDirectory));
    }

    $stdoutRaw = stream_get_contents($pipes[1]);
    $stderrRaw = stream_get_contents($pipes[2]);
    $stdout = $stdoutRaw !== false ? $stdoutRaw : '';
    $stderr = $stderrRaw !== false ? $stderrRaw : '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($handle), 'stdout' => $stdout, 'stderr' => $stderr];
}

function removeRecursively(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (!is_dir($path) || is_link($path)) {
        unlink($path);

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($path);
}

/**
 * @return array{outcome: string, exit: int, note: string}
 */
function probe(Document $document, string $scratchRoot, string $phpBinary, string $qmxBin): array
{
    $slug = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $document->identifier) ?? $document->identifier;
    $cacheDirectory = $scratchRoot . '/cache/' . $slug;
    $defaultCacheDirectory = $document->directory . '/.qmx-cache';

    // Step 1 & 2 of the cache protocol: unique explicit directory, deleted
    // before the run; the default directory is also cleared first so a
    // leftover from a previous, unrelated run cannot be mistaken for this
    // run having ignored --cache-dir.
    removeRecursively($cacheDirectory);
    removeRecursively($defaultCacheDirectory);

    $command = [$phpBinary, $qmxBin, 'check', ...$document->paths];

    if ($document->config !== null) {
        $command[] = '-c';
        $command[] = $document->config;
    }

    if ($document->preset !== null) {
        $command[] = '--preset=' . $document->preset;
    }

    $command[] = '--fail-on=none';
    $command[] = '--workers=0';
    $command[] = '--cache-dir=' . $cacheDirectory;
    $command[] = '--no-ansi';

    foreach ($document->extraArguments as $extraArgument) {
        $command[] = $extraArgument;
    }

    $result = runProcess($command, $document->directory);

    // Step 4: the default cache directory appearing means the product
    // ignored the directory it was explicitly given. That is a defect in the
    // probe's own trust chain, not a legitimate-configuration outcome.
    if (is_dir($defaultCacheDirectory)) {
        removeRecursively($cacheDirectory);
        throw new ProbeProtocolFailure(\sprintf(
            'Probe protocol failure for %s: the default %s appeared despite an explicit --cache-dir. '
            . 'The product ignored the cache directory it was given; this run cannot be trusted.',
            $document->identifier,
            $defaultCacheDirectory,
        ));
    }

    removeRecursively($cacheDirectory);

    $exit = $result['exit'];
    $framed = str_contains($result['stdout'] . $result['stderr'], 'Configuration error:');

    if ($exit === 3) {
        $head = trim(substr($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'], 0, 300));

        return [
            'outcome' => $framed ? 'REFUSED (framed)' : 'REFUSED (unframed)',
            'exit' => $exit,
            'note' => $head,
        ];
    }

    if ($exit === 0) {
        return ['outcome' => 'ACCEPTED', 'exit' => $exit, 'note' => ''];
    }

    // --fail-on=none suppresses the finding-driven 1/2 exit codes, so any
    // other non-zero exit here is an anomaly outside this probe's contract
    // (e.g. a crash), not a legitimate refusal -- but it is not silently
    // folded into ACCEPTED either.
    $head = trim(substr($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'], 0, 300));

    return ['outcome' => 'ANOMALY', 'exit' => $exit, 'note' => $head];
}

function main(): int
{
    global $repositoryRoot;

    $phpBinary = \PHP_BINARY;
    $qmxBin = $repositoryRoot . '/bin/qmx';
    $scratchRoot = sys_get_temp_dir() . '/qmx-promise-effect-corpus-' . getmypid();
    removeRecursively($scratchRoot);
    mkdir($scratchRoot . '/cache', 0777, true);

    $documents = buildCorpus($repositoryRoot);

    $legitimateRefusals = [];
    $anomalies = [];

    foreach ($documents as $document) {
        try {
            $result = probe($document, $scratchRoot, $phpBinary, $qmxBin);
        } catch (ProbeProtocolFailure $failure) {
            fwrite(\STDERR, $failure->getMessage() . "\n");
            removeRecursively($scratchRoot);

            return 2;
        }

        $known = (KNOWN_ANOMALIES[$document->identifier] ?? null) === $result['exit'];
        $label = $result['outcome'] === 'ANOMALY' && $known ? 'ANOMALY (known, unrelated to this round -- see KNOWN_ANOMALIES)' : $result['outcome'];

        printf("%-60s exit=%d  %s\n", $document->identifier, $result['exit'], $label);

        if (str_starts_with($result['outcome'], 'REFUSED')) {
            $legitimateRefusals[] = $document->identifier . ' :: ' . $result['note'];
        } elseif ($result['outcome'] === 'ANOMALY' && !$known) {
            $anomalies[] = $document->identifier . ' :: exit=' . $result['exit'] . ' :: ' . $result['note'];
        }
    }

    removeRecursively($scratchRoot);

    echo "\n";
    printf("Documents probed: %d\n", \count($documents));
    printf("Legitimate refusals: %d\n", \count($legitimateRefusals));

    if ($anomalies !== []) {
        echo "\nUnexpected anomalies (non-zero exit other than 3, not in KNOWN_ANOMALIES):\n";

        foreach ($anomalies as $anomaly) {
            echo '  - ' . $anomaly . "\n";
        }
    }

    if ($legitimateRefusals === []) {
        return 0;
    }

    echo "\nDocuments the product refused (exit 3) though the corpus declares them legitimate:\n";

    foreach ($legitimateRefusals as $refusal) {
        echo '  - ' . $refusal . "\n";
    }

    return 1;
}

exit(main());
