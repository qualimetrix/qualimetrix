#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates the P0 test-topology evidence for the modular-architecture migration.
 *
 * The inventory is intentionally based on the committable worktree (tracked
 * plus non-ignored untracked files) and PHPUnit's own discovery output. It
 * fails closed when a test disappears, a discovered class cannot be mapped to
 * a file, or two artifacts converge on one target without an explicit
 * migration disposition.
 */

const OUTPUT_DIRECTORY = 'docs/internal/generated/modular-architecture';

$arguments = $_SERVER['argv'] ?? [];
$check = in_array('--check', $arguments, true);
$outputDirectoryArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--output-directory='),
));
$classificationProbeArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--classification-probe='),
));
$discoveryProbeArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--discovery-probe='),
));
if (count($outputDirectoryArguments) > 1) {
    fail('Only one --output-directory path may be provided.');
}
if (count($classificationProbeArguments) > 1) {
    fail('Only one classification probe path may be provided.');
}
if (count($discoveryProbeArguments) > 1) {
    fail('Only one discovery probe path may be provided.');
}
$unknownArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => $argument !== '--check'
        && !str_starts_with($argument, '--output-directory=')
        && !str_starts_with($argument, '--classification-probe=')
        && !str_starts_with($argument, '--discovery-probe='),
));
if ($unknownArguments !== []) {
    fail('Unknown argument: ' . implode(', ', $unknownArguments));
}

/** @var array<string, string> */
const ORPHAN_CANDIDATE_PREFIXES = [
    'tests/Fixture/DataClassEntity.php' => 'No live test references this fixture class.',
    'tests/Fixtures/Aggregation/' => 'No live test reads or analyses this fixture directory.',
    'tests/Fixtures/CircularDeps/' => 'Tests use equivalent FQNs but do not read these files.',
    'tests/Fixtures/CouplingProject/' => 'No live test reads or analyses this fixture directory.',
    'tests/Fixtures/Inheritance/' => 'No live test reads or analyses this fixture directory.',
];

/** @var array<string, string> */
const EXPLICIT_PATH_DISPOSITIONS = [
    'tests/Infrastructure/Logging/LoggerFactoryTest.php' => 'P8: consolidate overlapping LoggerFactory coverage before moving to Infrastructure/Unit.',
    'tests/Unit/Infrastructure/Logging/LoggerFactoryTest.php' => 'P8: consolidate overlapping LoggerFactory coverage before moving to Infrastructure/Unit.',
];

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fail('Cannot resolve the project root.');
}

if ($classificationProbeArguments !== []) {
    $path = substr($classificationProbeArguments[0], strlen('--classification-probe='));
    [$owner, $closurePackage] = classifyOwner($path);
    $currentSuite = currentSuite($path);
    $targetSuite = $currentSuite === 'Infrastructure' ? 'Unit' : $currentSuite;
    fwrite(STDOUT, implode("\t", [
        $owner,
        $closurePackage,
        $currentSuite,
        targetPath($path, 'phpunit-test-class', $owner, $targetSuite),
    ]) . "\n");
    exit(0);
}

$worktreePaths = commandLines(
    ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--', 'tests', 'scripts/tests', 'src/Reporting/Template/tests', 'src/Reporting/Template/package.json', 'src/Reporting/Template/vite.config.js'],
    $projectRoot,
);
$worktreePaths = array_values(array_filter(
    $worktreePaths,
    static fn(string $path): bool => is_file($projectRoot . '/' . $path),
));
sort($worktreePaths, SORT_STRING);

$worktreeTestPaths = array_values(array_filter(
    $worktreePaths,
    static fn(string $path): bool => str_ends_with($path, 'Test.php'),
));
$phpunitDiscovery = runCommand(
    ['vendor/bin/phpunit', '--list-tests', '--no-coverage', ...$worktreeTestPaths],
    $projectRoot,
);
$parsedPhpunitDiscovery = parsePhpunitDiscovery($phpunitDiscovery);
$canonicalPhpunitDiscovery = canonicalPhpunitDiscovery($parsedPhpunitDiscovery);
if ($discoveryProbeArguments !== []) {
    $probePath = substr($discoveryProbeArguments[0], strlen('--discovery-probe='));
    $probe = file_get_contents($probePath);
    if ($probe === false) {
        fail('Cannot read PHPUnit discovery probe: ' . $probePath);
    }
    $parsedProbe = parsePhpunitDiscovery($probe);
    if ($parsedProbe['exact_ids'] !== $parsedPhpunitDiscovery['exact_ids']) {
        fail('PHPUnit discovery probe does not match the live exact test IDs.');
    }
    fwrite(STDOUT, canonicalPhpunitDiscovery($parsedProbe));
    exit(0);
}
// Verify that the repository's configured suite topology remains readable.
// The generated suite evidence below is derived from committable worktree
// files, excluding ignored local files and dependencies.
runCommand(['vendor/bin/phpunit', '--list-suites', '--no-coverage'], $projectRoot);
$discoveredCaseCounts = discoveredCaseCounts($parsedPhpunitDiscovery['exact_ids']);

$rows = [];
foreach ($worktreePaths as $path) {
    $absolutePath = $projectRoot . '/' . $path;
    $classes = str_ends_with($path, '.php') ? declaredTypes($absolutePath) : [];
    $discoveredClasses = [];
    $discoveredCases = 0;

    foreach ($classes as $class) {
        if (!isset($discoveredCaseCounts[$class])) {
            continue;
        }

        $discoveredClasses[] = $class;
        $discoveredCases += $discoveredCaseCounts[$class];
    }

    [$owner, $closurePackage] = classifyOwner($path);
    $kind = classifyKind($path, $discoveredClasses);
    $currentSuite = currentSuite($path);
    $targetSuite = $kind === 'phpunit-test-class'
        ? ($currentSuite === 'Infrastructure' ? 'Unit' : $currentSuite)
        : 'none';
    $disposition = dispositionFor($path, $kind);

    if (isset(EXPLICIT_PATH_DISPOSITIONS[$path]) || orphanCandidateReason($path) !== null || $kind === 'placeholder') {
        $closurePackage = 'P8';
    }

    $rows[] = [
        'current_path' => $path,
        'kind' => $kind,
        'classes' => implode(',', $classes),
        'discovered_classes' => implode(',', $discoveredClasses),
        'discovered_test_cases' => (string) $discoveredCases,
        'current_suite' => $currentSuite,
        'target_suite' => $targetSuite,
        'subject_owner' => $owner,
        'target_path' => targetPath($path, $kind, $owner, $targetSuite),
        'closure_package' => $closurePackage,
        'disposition' => $disposition,
    ];
}

validateInventory($rows, $discoveredCaseCounts);
$fixtureDirectoryRows = fixtureDirectoryRows($rows);

$outputDirectory = $outputDirectoryArguments === []
    ? $projectRoot . '/' . OUTPUT_DIRECTORY
    : substr($outputDirectoryArguments[0], strlen('--output-directory='));
if (!is_dir($outputDirectory) && !$check && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
    fail('Cannot create output directory: ' . OUTPUT_DIRECTORY);
}

emitGenerated($outputDirectory . '/test-ownership.tsv', tsvContents($rows), $check);
emitGenerated($outputDirectory . '/test-fixture-directories.tsv', tsvContents($fixtureDirectoryRows), $check);
emitGenerated($outputDirectory . '/test-phpunit-discovery.txt', $canonicalPhpunitDiscovery, $check);
emitGenerated($outputDirectory . '/test-phpunit-suites.txt', worktreeSuiteOutput($rows, $phpunitDiscovery), $check);

$summary = inventorySummary($rows, $fixtureDirectoryRows, $discoveredCaseCounts);
fwrite(
    STDOUT,
    sprintf(
        "%s %d artifacts, %d fixture directories, %d PHPUnit classes, and %d expanded cases.\n",
        $check ? 'Checked' : 'Generated',
        count($rows),
        count($fixtureDirectoryRows),
        $summary['discovered_unique_classes'],
        $summary['discovered_test_cases'],
    ),
);

/**
 * @param list<string> $command
 *
 * @return list<string>
 */
function commandLines(array $command, string $workingDirectory): array
{
    return array_values(array_filter(
        explode("\n", trim(runCommand($command, $workingDirectory))),
        static fn(string $line): bool => $line !== '',
    ));
}

/**
 * @param list<string> $command
 */
function runCommand(array $command, string $workingDirectory): string
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        fail('Cannot start command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    [$stdout, $stderr] = drainProcessPipes($pipes[1], $pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail(sprintf(
            "Command failed with exit %d: %s\n%s",
            $exitCode,
            implode(' ', $command),
            trim($stderr),
        ));
    }

    return $stdout;
}

/** @param resource $stdoutPipe
 * @param resource $stderrPipe
 *
 * @return array{string, string}
 */
function drainProcessPipes($stdoutPipe, $stderrPipe): array
{
    stream_set_blocking($stdoutPipe, false);
    stream_set_blocking($stderrPipe, false);
    $streams = [(int) $stdoutPipe => ['stream' => $stdoutPipe, 'index' => 0], (int) $stderrPipe => ['stream' => $stderrPipe, 'index' => 1]];
    $output = ['', ''];
    while ($streams !== []) {
        $read = array_column($streams, 'stream');
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, null) === false) {
            fail('Cannot read command output streams.');
        }
        foreach ($read as $stream) {
            $key = (int) $stream;
            $chunk = stream_get_contents($stream);
            if ($chunk === false) {
                fail('Cannot read command output stream.');
            }
            $output[$streams[$key]['index']] .= $chunk;
            if (feof($stream)) {
                fclose($stream);
                unset($streams[$key]);
            }
        }
    }

    return [$output[0], $output[1]];
}

/**
 * @param list<string> $exactIds
 *
 * @return array<string, int>
 */
function discoveredCaseCounts(array $exactIds): array
{
    $counts = [];
    foreach ($exactIds as $exactId) {
        $class = explode('::', $exactId, 2)[0];
        $counts[$class] = ($counts[$class] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);

    return $counts;
}

/**
 * @return list<string>
 */
function declaredTypes(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fail('Cannot read PHP file: ' . $path);
    }

    $tokens = token_get_all($contents);
    $namespace = '';
    $types = [];
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; ++$index) {
        $token = $tokens[$index];
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $namespace = '';
            for (++$index; $index < $tokenCount; ++$index) {
                $part = $tokens[$index];
                if ($part === ';' || $part === '{') {
                    break;
                }
                if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $namespace .= $part[1];
                }
            }

            continue;
        }

        if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }

        $next = nextSignificantToken($tokens, $index + 1);
        if (!is_array($next) || $next[0] !== T_STRING) {
            continue;
        }

        $types[] = ($namespace !== '' ? $namespace . '\\' : '') . $next[1];
    }

    sort($types, SORT_STRING);

    return array_values(array_unique($types));
}

/**
 * @param list<array{int, string, int}|string> $tokens
 *
 * @return array{int, string, int}|string|null
 */
function nextSignificantToken(array $tokens, int $start): array|string|null
{
    for ($index = $start, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $token;
    }

    return null;
}

/**
 * @return array{string, string}
 */
function classifyOwner(string $path): array
{
    if (str_starts_with($path, 'scripts/tests/')) {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    if (str_starts_with($path, 'src/Reporting/Template/tests/') || in_array($path, ['src/Reporting/Template/package.json', 'src/Reporting/Template/vite.config.js'], true)) {
        return ['Reporting/HtmlTemplate', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Architecture/')) {
        if (str_contains($path, 'CircularDependency')) {
            return ['Analysis/Evidence/CircularDependency', 'P4'];
        }
        if (str_contains($path, 'InlineSuppression') || str_contains($path, '/Fixtures/IgnoreSample/')) {
            return ['Analysis/Policy/Inline', 'P6'];
        }

        return ['Analysis/Policy/Architecture', 'P4'];
    }
    if ($path === 'tests/Support/Console/StubBaselineRun.php' || $path === 'tests/Support/Console/TempDirectory.php' || str_starts_with($path, 'tests/Support/Time/')) {
        return ['Analysis/Policy/Baseline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Support/Dependency/')) {
        return ['Analysis/Evidence/CircularDependency', 'P4'];
    }
    if (str_starts_with($path, 'tests/Support/Logger/')) {
        return ['TestSupport/Logging', 'P8'];
    }
    if (str_starts_with($path, 'tests/Support/Pipeline/')) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Support/Violation/')) {
        return ['Analysis/Finding', 'P6'];
    }
    if (str_starts_with($path, 'tests/Fixture/')) {
        if (preg_match('/(DataClass|ReadonlyDto|SmallClass)/', $path) === 1) {
            return ['Analysis/Evidence/Design', 'P7'];
        }

        return ['Analysis/Configuration', 'P3'];
    }
    if (str_starts_with($path, 'tests/Fixtures/BaselineV10/')) {
        return ['Analysis/Policy/Baseline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Fixtures/Channels/')) {
        return ['Analysis/Finding', 'P6'];
    }
    if (str_starts_with($path, 'tests/Fixtures/CircularDeps/')) {
        return ['Analysis/Evidence/CircularDependency', 'P4'];
    }
    if (str_starts_with($path, 'tests/Fixtures/CouplingProject/')) {
        return ['Analysis/Evidence/Coupling', 'P7'];
    }
    if (str_starts_with($path, 'tests/Fixtures/GoldenMetrics/') || str_starts_with($path, 'tests/Fixtures/Aggregation/')) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Fixtures/Inheritance/')) {
        return ['Analysis/Evidence/Design', 'P7'];
    }
    if (str_starts_with($path, 'tests/Fixtures/Schema/')) {
        return ['Reporting/Sarif', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Fixtures/Ast/')) {
        return ['Infrastructure/Ast', 'permanent'];
    }
    if ($path === 'tests/Fixtures/AnonymousClassContext.php') {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    if (
        str_starts_with($path, 'tests/Analysis/Evidence/Duplication/Unit/')
        || str_starts_with($path, 'tests/Unit/Analysis/Duplication/')
        || str_starts_with($path, 'tests/Unit/Core/Duplication/')
        || str_starts_with($path, 'tests/Unit/Rules/Duplication/')
    ) {
        return ['Analysis/Evidence/Duplication', 'P1'];
    }
    $p2DependencyModelTests = [
        'tests/Unit/Core/Dependency/DependencyTest.php',
        'tests/Unit/Core/Dependency/EmptyDependencyGraphTest.php',
        'tests/Unit/Analysis/Collection/Dependency/DependencyGraphTest.php',
        'tests/Unit/Analysis/Collection/Dependency/DependencyGraphBuilderTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/DependencyTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/EmptyDependencyGraphTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/DependencyGraphTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/DependencyGraphBuilderTest.php',
    ];
    if (in_array($path, $p2DependencyModelTests, true)) {
        return ['Analysis/Evidence/DependencyModel', 'P2'];
    }

    $p2GraphProjectionTests = [
        'tests/Unit/Analysis/Collection/Dependency/Export/DotExporterTest.php',
        'tests/Unit/Analysis/Collection/Dependency/Export/JsonGraphExporterTest.php',
        'tests/Reporting/GraphProjection/Unit/DotExporterTest.php',
        'tests/Reporting/GraphProjection/Unit/JsonGraphExporterTest.php',
        'tests/Reporting/GraphProjection/Unit/DependencyGraphProjectorTest.php',
    ];
    if (in_array($path, $p2GraphProjectionTests, true)) {
        return ['Reporting/GraphProjection', 'P2'];
    }

    if (in_array($path, [
        'tests/Functional/Console/Command/GraphExportCommandTest.php',
        'tests/Infrastructure/Console/Functional/GraphExportCommandTest.php',
    ], true)) {
        return ['Infrastructure/Console', 'P2'];
    }

    if (preg_match('#^tests/Unit/Analysis/Collection/Dependency/(CircularDependencyDetector|Cycle)#', $path) === 1) {
        return ['Analysis/Evidence/CircularDependency', 'P4'];
    }
    if (in_array($path, [
        'tests/Unit/Analysis/Collection/Dependency/DependencyResolverTest.php',
        'tests/Unit/Analysis/Collection/Dependency/DependencyVisitorTest.php',
        'tests/Unit/Analysis/Collection/Dependency/TypeDependencyHelperTest.php',
    ], true)) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Unit/Analysis/Repository/') || str_starts_with($path, 'tests/Unit/Core/Metric/')) {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Analysis/') || str_starts_with($path, 'tests/Integration/Analysis/') || str_starts_with($path, 'tests/Integration/Pipeline/')) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Unit/Baseline/Suppression/') || str_contains($path, 'ThresholdAnnotationParser') || str_contains($path, 'ThresholdValidatorWiring')) {
        return ['Analysis/Policy/Inline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Baseline/') || str_starts_with($path, 'tests/Integration/Baseline') || str_starts_with($path, 'tests/Functional/Console/Command/Baseline')) {
        return ['Analysis/Policy/Baseline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/ComputedMetric/') || str_contains($path, 'ComputedMetric') || str_contains($path, 'ComputedMetrics') || str_contains($path, 'HealthFormula')) {
        return ['Analysis/Evidence/ComputedMetrics', 'P5'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/Coupling/')) {
        return ['Analysis/Evidence/Coupling', 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/Suppression/') || str_starts_with($path, 'tests/Unit/Core/Rule/Override/') || str_contains($path, 'AnalysisContextThreshold') || str_contains($path, 'ThresholdOverride')) {
        return ['Analysis/Policy/Inline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/Rule/') || str_starts_with($path, 'tests/Unit/Core/Violation/') || str_starts_with($path, 'tests/Integration/Violation/') || str_contains($path, 'ChannelDeclaration')) {
        return ['Analysis/Finding', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Configuration/') || str_starts_with($path, 'tests/Integration/Configuration/')) {
        return ['Analysis/Configuration', 'P3'];
    }
    if (str_starts_with($path, 'tests/Unit/Metrics/ComputedMetric/') || str_starts_with($path, 'tests/Unit/Rules/ComputedMetric/')) {
        return ['Analysis/Evidence/ComputedMetrics', 'P5'];
    }
    if (preg_match('#^tests/Unit/(Metrics|Rules)/(CodeSmell|Complexity|Coupling|Design|Halstead|Maintainability|Security|Size)/#', $path, $matches) === 1) {
        $subject = match ($matches[2]) {
            'Halstead' => 'Maintainability',
            default => $matches[2],
        };

        return ['Analysis/Evidence/' . $subject, 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Metrics/Structure/') || str_starts_with($path, 'tests/Unit/Rules/Structure/') || str_contains($path, 'Wmc')) {
        $subject = match (true) {
            preg_match('/(Lcom|TccLcc)/', $path) === 1 => 'Cohesion',
            preg_match('/(Inheritance|Dit|Noc)/', $path) === 1 => 'Design',
            preg_match('/MethodCount/', $path) === 1 => 'Size',
            preg_match('/(UnusedPrivate|TraitUsageResolver)/', $path) === 1 => 'CodeSmell',
            preg_match('/Rfc/', $path) === 1 => 'Coupling',
            preg_match('/Wmc/', $path) === 1 => 'Complexity',
            default => fail('Unclassified Structure test: ' . $path),
        };

        return ['Analysis/Evidence/' . $subject, 'P7'];
    }
    if (str_starts_with($path, 'tests/Integration/Metrics/')) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Unit/Metrics/')) {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Rules/CodeSmell/') || str_starts_with($path, 'tests/Integration/Rules/')) {
        return ['Analysis/Evidence/CodeSmell', 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Rules/Support/') || str_starts_with($path, 'tests/Unit/Rules/AbstractRule') || str_contains($path, 'ThresholdValidatorAssignment')) {
        return ['Analysis/Finding', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Reporting/Health/')) {
        return ['Analysis/Evidence/ComputedMetrics/Health', 'P5'];
    }
    if (str_starts_with($path, 'tests/Unit/Reporting/Impact/')) {
        return ['Reporting/Impact', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Unit/Reporting/Filter/') || str_contains($path, 'CoverageProjection') || str_contains($path, 'JsonShapePreservation')) {
        return ['Reporting/FindingProjection', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Reporting/') || str_starts_with($path, 'tests/Functional/Reporting/')) {
        return ['Reporting', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Unit/Infrastructure/') || str_starts_with($path, 'tests/Infrastructure/') || str_starts_with($path, 'tests/Integration/Infrastructure/') || str_starts_with($path, 'tests/Integration/DependencyInjection/') || str_starts_with($path, 'tests/Integration/Profiler/')) {
        if (str_contains($path, 'ViolationFilter')) {
            return ['Infrastructure', 'P6'];
        }
        if (str_contains($path, 'Rule') || str_contains($path, 'CompilerPass')) {
            return ['Infrastructure', 'P7'];
        }

        return ['Infrastructure', 'permanent'];
    }
    if (str_contains($path, 'LayerAssignment')) {
        return ['Infrastructure/Console', 'P4'];
    }
    if (str_starts_with($path, 'tests/Functional/Console/Command/Hook')) {
        return ['Infrastructure/GitHook', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Functional/Console/')) {
        return ['Infrastructure/Console', 'P3'];
    }
    if (str_starts_with($path, 'tests/Integration/Architecture/')) {
        return ['Analysis/Policy/Architecture', 'P0'];
    }
    if (str_starts_with($path, 'tests/Integration/Documentation/')) {
        return ['System/DocumentationConsistency', 'P8'];
    }
    if (str_starts_with($path, 'tests/Integration/Scripts/')) {
        return ['Analysis/Evidence/ComputedMetrics', 'P5'];
    }
    if (str_starts_with($path, 'tests/Unit/PhpStan/')) {
        return ['TestSupport/ArchitectureStaticAnalysis', 'P8'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/')) {
        return ['Core', 'permanent'];
    }
    if (str_ends_with($path, '.gitkeep')) {
        return ['legacy-placeholder', 'P8'];
    }

    return fail('Unclassified test artifact: ' . $path);
}

/**
 * @param list<string> $discoveredClasses
 */
function classifyKind(string $path, array $discoveredClasses): string
{
    if ($discoveredClasses !== []) {
        return 'phpunit-test-class';
    }
    if (str_contains($path, '/Fixtures/') || str_contains($path, '/Fixture/') || str_contains($path, '/data/') || str_contains($path, '/fixtures/') || preg_match('/Fixture(s)?\.php$/', $path) === 1) {
        return 'fixture';
    }
    if (str_contains($path, '/Support/') || (str_ends_with($path, '.php') && !str_ends_with($path, 'Test.php'))) {
        return 'support';
    }
    if (str_ends_with($path, 'package.json') || str_ends_with($path, 'vite.config.js')) {
        return 'non-php-test-config';
    }
    if (preg_match('/\.(py|js)$/', $path) === 1) {
        return 'non-php-test-process';
    }
    if (str_ends_with($path, '.gitkeep')) {
        return 'placeholder';
    }

    return fail('Unclassified test artifact kind: ' . $path);
}

function currentSuite(string $path): string
{
    return match (true) {
        str_starts_with($path, 'tests/Architecture/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/Duplication/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/DependencyModel/Unit/'),
        str_starts_with($path, 'tests/Reporting/GraphProjection/Unit/'),
        str_starts_with($path, 'tests/Unit/') => 'Unit',
        str_starts_with($path, 'tests/Architecture/Integration/'), str_starts_with($path, 'tests/Integration/') => 'Integration',
        str_starts_with($path, 'tests/Functional/'), str_starts_with($path, 'tests/Infrastructure/Console/Functional/') => 'Functional',
        str_starts_with($path, 'tests/Infrastructure/') => 'Infrastructure',
        default => 'none',
    };
}

function dispositionFor(string $path, string $kind): string
{
    if (isset(EXPLICIT_PATH_DISPOSITIONS[$path])) {
        return EXPLICIT_PATH_DISPOSITIONS[$path];
    }

    $orphanReason = orphanCandidateReason($path);
    if ($orphanReason !== null) {
        return 'P8: retain in place until consumer proof; delete only if the orphan candidate is confirmed. ' . $orphanReason;
    }
    if ($kind === 'placeholder') {
        return 'P8: remove the empty legacy placeholder after its target topology exists.';
    }

    return 'Move atomically with the named owner and closure package.';
}

function orphanCandidateReason(string $path): ?string
{
    foreach (ORPHAN_CANDIDATE_PREFIXES as $prefix => $reason) {
        if ($path === $prefix || str_starts_with($path, $prefix)) {
            return $reason;
        }
    }

    return null;
}

function targetPath(string $path, string $kind, string $owner, string $targetSuite): string
{
    if ($kind === 'placeholder') {
        return 'DELETE';
    }

    $targetRoot = 'tests/' . $owner;
    if ($kind === 'phpunit-test-class') {
        return $targetRoot . '/' . $targetSuite . '/' . basename($path);
    }
    if ($kind === 'fixture') {
        return $targetRoot . '/Fixtures/' . fixtureTail($path);
    }
    if ($kind === 'support') {
        return $targetRoot . '/Support/' . basename($path);
    }

    return $targetRoot . '/Tests/' . basename($path);
}

function fixtureTail(string $path): string
{
    foreach (['tests/Architecture/Fixtures/', 'tests/Fixtures/', 'tests/Fixture/', 'scripts/tests/fixtures/', '/data/'] as $marker) {
        $position = strpos($path, $marker);
        if ($position !== false) {
            return substr($path, $position + strlen($marker));
        }
    }

    return basename($path);
}

/**
 * @param list<array<string, string>> $rows
 * @param array<string, int> $discoveredCaseCounts
 */
function validateInventory(array $rows, array $discoveredCaseCounts): void
{
    $mappedDiscoveredClasses = [];
    $targetPaths = [];

    foreach ($rows as $row) {
        foreach (array_filter(explode(',', $row['discovered_classes']), static fn(string $class): bool => $class !== '') as $class) {
            $mappedDiscoveredClasses[$class][] = $row['current_path'];
        }
        if ($row['target_path'] !== 'DELETE') {
            $targetPaths[$row['target_path']][] = $row['current_path'];
        }
        if (str_ends_with($row['current_path'], 'Test.php') && $row['discovered_classes'] === '') {
            fail('PHPUnit did not discover worktree test file: ' . $row['current_path']);
        }
    }

    $unmatched = array_diff_key($discoveredCaseCounts, $mappedDiscoveredClasses);
    if ($unmatched !== []) {
        fail('Discovered PHPUnit classes without worktree files: ' . implode(', ', array_keys($unmatched)));
    }

    foreach ($mappedDiscoveredClasses as $class => $paths) {
        if (count(array_unique($paths)) > 1) {
            fail(sprintf('Duplicate discovered PHPUnit class %s in: %s', $class, implode(', ', $paths)));
        }
    }

    foreach ($targetPaths as $target => $paths) {
        $uniquePaths = array_values(array_unique($paths));
        if (count($uniquePaths) < 2) {
            continue;
        }
        foreach ($uniquePaths as $path) {
            if (!isset(EXPLICIT_PATH_DISPOSITIONS[$path])) {
                fail(sprintf('Target collision without disposition at %s: %s', $target, implode(', ', $uniquePaths)));
            }
        }
    }

    $expectedOrphanCandidates = 28;
    $orphanCandidates = array_filter($rows, static fn(array $row): bool => orphanCandidateReason($row['current_path']) !== null);
    if (count($orphanCandidates) !== $expectedOrphanCandidates) {
        fail(sprintf('Expected %d explicit orphan candidates, found %d.', $expectedOrphanCandidates, count($orphanCandidates)));
    }
}

/**
 * @param list<array<string, string>> $rows
 *
 * @return list<array<string, string>>
 */
function fixtureDirectoryRows(array $rows): array
{
    $directories = [];
    foreach ($rows as $row) {
        if ($row['kind'] !== 'fixture') {
            continue;
        }

        $directory = dirname($row['current_path']);
        while ($directory !== '.' && (str_contains($directory, '/Fixtures') || str_contains($directory, '/Fixture') || str_contains($directory, '/data'))) {
            $directories[$directory]['owners'][$row['subject_owner']] = true;
            $directories[$directory]['packages'][$row['closure_package']] = true;
            $directories[$directory]['orphan'] = ($directories[$directory]['orphan'] ?? true)
                && orphanCandidateReason($row['current_path']) !== null;
            $directory = dirname($directory);
        }
    }
    ksort($directories, SORT_STRING);

    $result = [];
    foreach ($directories as $directory => $data) {
        $owners = array_keys($data['owners']);
        $packages = array_keys($data['packages']);
        sort($owners, SORT_STRING);
        sort($packages, SORT_STRING);
        $result[] = [
            'current_directory' => $directory,
            'subject_owners' => implode(',', $owners),
            'closure_packages' => implode(',', $packages),
            'disposition' => $data['orphan']
                ? 'P8: retain until consumer proof; delete only if confirmed orphan.'
                : (count($owners) > 1 ? 'Split by file owner and closure package.' : 'Move atomically with the owning subject.'),
        ];
    }

    return $result;
}

/**
 * @param list<array<string, string>> $rows
 */
function tsvContents(array $rows): string
{
    if ($rows === []) {
        fail('Refusing to render an empty TSV.');
    }

    $handle = fopen('php://temp', 'w+b');
    if ($handle === false) {
        fail('Cannot create temporary TSV stream.');
    }

    fputcsv($handle, array_keys($rows[0]), "\t", '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, "\t", '"', '');
    }
    rewind($handle);
    $contents = stream_get_contents($handle);
    fclose($handle);
    if ($contents === false) {
        fail('Cannot read temporary TSV stream.');
    }

    return $contents;
}

function emitGenerated(string $path, string $contents, bool $check): void
{
    if ($check) {
        $current = is_file($path) ? file_get_contents($path) : false;
        if ($current !== $contents) {
            fail('Generated artifact is stale: ' . $path);
        }
        return;
    }

    writeFileAtomically($path, $contents);
}

function writeFileAtomically(string $path, string $contents): void
{
    $temporaryPath = $path . '.tmp.' . getmypid();
    if (file_put_contents($temporaryPath, $contents) === false) {
        fail('Cannot write temporary file: ' . $temporaryPath);
    }
    if (!rename($temporaryPath, $path)) {
        fail('Cannot replace file: ' . $path);
    }
}

/** @return array{version_line: string, exact_ids: list<string>} */
function parsePhpunitDiscovery(string $output): array
{
    $normalized = str_replace("\r\n", "\n", $output);
    if (!str_ends_with($normalized, "\n")) {
        fail('PHPUnit discovery output must end with a terminal LF.');
    }
    $lines = explode("\n", substr($normalized, 0, -1));
    $versionLine = array_shift($lines);
    if ($versionLine === null || !str_starts_with($versionLine, 'PHPUnit ')) {
        fail('Cannot read the PHPUnit version line from discovery output.');
    }
    if (array_shift($lines) !== '' || array_shift($lines) !== 'Available tests:') {
        fail('Cannot read the PHPUnit discovery heading.');
    }

    if ($lines === []) {
        fail('PHPUnit discovery output contains no test IDs.');
    }
    $exactIds = [];
    foreach ($lines as $line) {
        if (preg_match('/^ - ([A-Za-z_][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)(?:#[0-9]+|".*")?$/', $line, $matches) !== 1) {
            fail('Unexpected PHPUnit discovery output line: ' . $line);
        }
        $exactId = substr($line, 3);
        if (isset($exactIds[$exactId])) {
            fail('Duplicate PHPUnit exact test ID: ' . $exactId);
        }
        $exactIds[$exactId] = true;
    }
    $exactIds = array_keys($exactIds);
    sort($exactIds, \SORT_STRING);

    return ['version_line' => $versionLine, 'exact_ids' => $exactIds];
}

/** @param array{version_line: string, exact_ids: list<string>} $discovery */
function canonicalPhpunitDiscovery(array $discovery): string
{
    $testLines = array_map(static fn(string $exactId): string => ' - ' . $exactId, $discovery['exact_ids']);

    return implode("\n", [$discovery['version_line'], '', 'Available tests:', ...$testLines]) . "\n";
}

/**
 * @param list<array<string, string>> $rows
 */
function worktreeSuiteOutput(array $rows, string $phpunitDiscovery): string
{
    $versionLine = strtok($phpunitDiscovery, "\n");
    if ($versionLine === false || !str_starts_with($versionLine, 'PHPUnit ')) {
        fail('Cannot read the PHPUnit version line from discovery output.');
    }

    $suites = [];
    foreach ($rows as $row) {
        if ($row['kind'] !== 'phpunit-test-class') {
            continue;
        }

        $suite = $row['current_suite'];
        $suites[$suite]['files'] = ($suites[$suite]['files'] ?? 0) + 1;
        $suites[$suite]['cases'] = ($suites[$suite]['cases'] ?? 0) + (int) $row['discovered_test_cases'];
    }
    ksort($suites, SORT_STRING);

    $lines = [
        $versionLine,
        '',
        'Worktree test suites (derived from PHPUnit discovery):',
    ];
    foreach ($suites as $suite => $counts) {
        $lines[] = sprintf(' - %s (%d files, %d tests)', $suite, $counts['files'], $counts['cases']);
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param list<array<string, string>> $rows
 * @param list<array<string, string>> $fixtureDirectories
 * @param array<string, int> $discoveredCaseCounts
 *
 * @return array<string, mixed>
 */
function inventorySummary(array $rows, array $fixtureDirectories, array $discoveredCaseCounts): array
{
    $kindCounts = array_count_values(array_column($rows, 'kind'));
    $suiteFileCounts = array_count_values(array_column(
        array_filter($rows, static fn(array $row): bool => $row['kind'] === 'phpunit-test-class'),
        'current_suite',
    ));
    $packageCounts = array_count_values(array_column($rows, 'closure_package'));
    ksort($kindCounts, SORT_STRING);
    ksort($suiteFileCounts, SORT_STRING);
    ksort($packageCounts, SORT_STRING);

    $targetPaths = [];
    foreach ($rows as $row) {
        if ($row['target_path'] !== 'DELETE') {
            $targetPaths[$row['target_path']][] = $row['current_path'];
        }
    }
    $targetCollisions = array_filter(
        $targetPaths,
        static fn(array $paths): bool => count(array_unique($paths)) > 1,
    );
    $orphanCandidates = array_filter(
        $rows,
        static fn(array $row): bool => orphanCandidateReason($row['current_path']) !== null,
    );

    return [
        'worktree_artifacts' => count($rows),
        'fixture_directories' => count($fixtureDirectories),
        'discovered_unique_classes' => count($discoveredCaseCounts),
        'discovered_test_cases' => array_sum($discoveredCaseCounts),
        'undiscovered_test_files' => 0,
        'duplicate_discovered_classes' => 0,
        'explicit_target_collisions' => count($targetCollisions),
        'explicit_orphan_candidates' => count($orphanCandidates),
        'kind_counts' => $kindCounts,
        'suite_file_counts' => $suiteFileCounts,
        'closure_package_counts' => $packageCounts,
    ];
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
