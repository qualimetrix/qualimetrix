<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

const EXPECTED_REPORTING_DECLARATIONS = 59;
const EXPECTED_PHASE_PARTICIPANTS = 24;

const EXPECTED_REPORTING_CLASSIFICATION_COUNTS = [
    'evidence computation' => 10,
    'output projection' => 45,
    'policy application' => 3,
    'run orchestration' => 1,
];

const EXPECTED_EXTENSION_COUNTS = [
    'rule' => 41,
    'regular_collector' => 21,
    'derived_collector' => 2,
    'global_collector' => 6,
    'formatter' => 11,
    'configuration_stage' => 5,
    'lifecycle_hook' => 1,
];

/** @return never */
function fail(string $message): void
{
    fwrite(STDERR, "Production inventory generation failed: {$message}\n");
    exit(1);
}

/**
 * @return list<array{
 *     path: string,
 *     fqcn: string,
 *     namespace: string,
 *     kind: string,
 *     abstract: bool,
 *     readonly: bool,
 *     extends: ?string,
 *     implements: list<string>,
 *     properties: list<array{name: string, static: bool, readonly: bool}>,
 *     methods: list<string>,
 *     dependencies: list<string>,
 * }>
 */
function declarations(string $root): array
{
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $finder = new NodeFinder();
    $rows = [];
    $seen = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $code = file_get_contents($path);
        if ($code === false) {
            fail('cannot read ' . $path);
        }

        $ast = $parser->parse($code);
        if ($ast === null) {
            fail('parser returned no AST for ' . $path);
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $declaration) {
            if ($declaration instanceof Node\Stmt\Class_ && $declaration->isAnonymous()) {
                continue;
            }

            $fqcn = $declaration->namespacedName?->toString() ?? '';
            if ($fqcn === '') {
                fail('unnamed declaration in ' . relativePath($root, $path));
            }
            if (isset($seen[$fqcn])) {
                fail("duplicate declaration {$fqcn} in {$seen[$fqcn]} and " . relativePath($root, $path));
            }
            $seen[$fqcn] = relativePath($root, $path);

            $kind = match (true) {
                $declaration instanceof Node\Stmt\Interface_ => 'interface',
                $declaration instanceof Node\Stmt\Trait_ => 'trait',
                $declaration instanceof Node\Stmt\Enum_ => 'enum',
                default => 'class',
            };

            $implements = [];
            if ($declaration instanceof Node\Stmt\Class_) {
                foreach ($declaration->implements as $name) {
                    $implements[] = $name->toString();
                }
            } elseif ($declaration instanceof Node\Stmt\Interface_) {
                foreach ($declaration->extends as $name) {
                    $implements[] = $name->toString();
                }
            }
            sort($implements, SORT_STRING);

            $properties = [];
            foreach ($declaration->getProperties() as $property) {
                foreach ($property->props as $item) {
                    $properties[$item->name->toString()] = [
                        'name' => $item->name->toString(),
                        'static' => $property->isStatic(),
                        'readonly' => $property->isReadonly()
                            || ($declaration instanceof Node\Stmt\Class_ && $declaration->isReadonly()),
                    ];
                }
            }

            $methods = [];
            foreach ($declaration->getMethods() as $method) {
                $methods[] = $method->name->toString();
                foreach ($method->params as $parameter) {
                    if (
                        !$parameter->isPromoted()
                        || !$parameter->var instanceof Node\Expr\Variable
                        || !is_string($parameter->var->name)
                    ) {
                        continue;
                    }
                    $name = $parameter->var->name;
                    $properties[$name] = [
                        'name' => $name,
                        'static' => false,
                        'readonly' => ($parameter->flags & Node\Stmt\Class_::MODIFIER_READONLY) !== 0
                            || ($declaration instanceof Node\Stmt\Class_ && $declaration->isReadonly()),
                    ];
                }
            }
            sort($methods, SORT_STRING);
            ksort($properties, SORT_STRING);

            $dependencies = [];
            foreach ($finder->findInstanceOf($declaration, Node\Name::class) as $name) {
                $resolved = $name->getAttribute('resolvedName');
                $dependency = $resolved instanceof Node\Name ? $resolved->toString() : $name->toString();
                if ($dependency !== $fqcn && str_starts_with($dependency, 'Qualimetrix\\')) {
                    $dependencies[$dependency] = true;
                }
            }
            $dependencies = array_keys($dependencies);
            sort($dependencies, SORT_STRING);

            $rows[] = [
                'path' => relativePath($root, $path),
                'fqcn' => $fqcn,
                'namespace' => substr($fqcn, 0, (int) strrpos($fqcn, '\\')),
                'kind' => $kind,
                'abstract' => $declaration instanceof Node\Stmt\Class_ && $declaration->isAbstract(),
                'readonly' => $declaration instanceof Node\Stmt\Class_ && $declaration->isReadonly(),
                'extends' => $declaration instanceof Node\Stmt\Class_ && $declaration->extends !== null
                    ? $declaration->extends->toString()
                    : null,
                'implements' => $implements,
                'properties' => array_values($properties),
                'methods' => $methods,
                'dependencies' => $dependencies,
            ];
        }
    }

    usort($rows, static fn(array $left, array $right): int => $left['fqcn'] <=> $right['fqcn']);

    return $rows;
}

function relativePath(string $root, string $path): string
{
    return substr($path, strlen($root) + 1);
}

function shortName(string $fqcn): string
{
    return substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
}

/**
 * @param array<string, array{fqcn: string, extends: ?string, implements: list<string>}> $byName
 * @param array<string, true> $seen
 */
function isA(string $class, string $target, array $byName, array $seen = []): bool
{
    if ($class === $target) {
        return true;
    }
    if (isset($seen[$class]) || !isset($byName[$class])) {
        return false;
    }
    $seen[$class] = true;
    $row = $byName[$class];

    foreach (array_filter(
        array_merge([$row['extends']], $row['implements']),
        static fn(?string $parent): bool => $parent !== null && $parent !== '',
    ) as $parent) {
        if (isA($parent, $target, $byName, $seen)) {
            return true;
        }
    }

    return false;
}

/** @return array{classification: string, target_owner: string} */
function reportingDisposition(string $fqcn): array
{
    $name = shortName($fqcn);

    if (in_array($name, ['RemediationTimeRegistry', 'ImpactCalculator', 'RankedIssue'], true)) {
        return ['classification' => 'policy application', 'target_owner' => 'Analysis.Evidence.Prioritization'];
    }
    if ($name === 'SummaryEnricher') {
        return ['classification' => 'run orchestration', 'target_owner' => 'Reporting'];
    }
    if (in_array($name, [
        'DebtCalculator',
        'DebtSummary',
        'ClassRankIndex',
        'ClassRankResolver',
        'ContributorRanker',
        'DecompositionItem',
        'HealthContributor',
        'HealthScore',
        'NamespaceDrillDown',
        'WorstOffender',
    ], true)) {
        return [
            'classification' => 'evidence computation',
            'target_owner' => str_contains($fqcn, '\\Health\\')
                ? 'Analysis.Evidence.ComputedMetrics.Health'
                : 'Analysis.Evidence.Prioritization',
        ];
    }

    return ['classification' => 'output projection', 'target_owner' => 'Reporting'];
}

/** @param list<string> $methods */
function lifecycleMethods(array $methods): string
{
    $selected = array_values(array_filter(
        $methods,
        static fn(string $method): bool => preg_match('/^(reset|clear|set|bind|prepare|execute|detect|collect|configure|build|add|register)/i', $method) === 1,
    ));
    sort($selected, SORT_STRING);

    return implode(',', $selected);
}

/** @param array{fqcn: string, path: string, proposed_owner: string, properties: list<array{name: string, static: bool, readonly: bool}>} $row */
function stateScope(array $row): string
{
    foreach ($row['properties'] as $property) {
        if ($property['static'] && !$property['readonly']) {
            return str_ends_with($row['fqcn'], '\\WorkerBootstrap') ? 'worker-process static' : 'process-wide static';
        }
    }
    if (str_contains($row['fqcn'], 'Holder')) {
        return 'runtime holder';
    }
    if (str_contains($row['fqcn'], 'Collector') || str_contains($row['fqcn'], 'Visitor')) {
        return 'per-file or derivation state';
    }
    if (str_contains($row['fqcn'], 'Registry') || str_contains($row['fqcn'], 'Pipeline')) {
        return 'runtime or composition registry';
    }
    if (str_starts_with($row['path'], 'src/Analysis/Repository/')) {
        return 'analysis-run repository';
    }
    if (str_starts_with($row['path'], 'src/Infrastructure/')) {
        return 'adapter session or cache';
    }
    if (str_starts_with($row['path'], 'src/Reporting/')) {
        return 'report projection or builder state';
    }

    return 'request scratch or mutable internal value';
}

/**
 * @return list<array{phase: string, participant: string, inputs: string, outputs: string, state_owner: string, dependency: string, source: string}>
 */
function phaseParticipants(): array
{
    return [
        ['phase' => 'configuration', 'participant' => '5 ConfigurationStageInterface implementations', 'inputs' => 'ConfigurationContext', 'outputs' => '?ConfigurationLayer', 'state_owner' => 'Analysis.Configuration', 'dependency' => 'priority 0,10,15,20,30; sequential merge', 'source' => 'src/Configuration/Pipeline/Stage'],
        ['phase' => 'runtime setup', 'participant' => 'ArchitectureLifecycleHook', 'inputs' => 'ResolvedConfiguration', 'outputs' => 'bound ArchitectureProcessor state', 'state_owner' => 'Analysis.Policy.Architecture', 'dependency' => 'reset then bind before architecture prepare', 'source' => 'src/Architecture/Processing/ArchitectureLifecycleHook.php'],
        ['phase' => 'discovery', 'participant' => 'FileDiscoveryInterface implementation', 'inputs' => 'AbsolutePath|list<AbsolutePath>', 'outputs' => 'iterable<AbsolutePath,SplFileInfo>', 'state_owner' => 'Analysis.Run', 'dependency' => 'first run phase; generated filter follows', 'source' => 'src/Analysis/Discovery/FileDiscoveryInterface.php'],
        ['phase' => 'collection', 'participant' => 'CollectionOrchestratorInterface', 'inputs' => 'list<SplFileInfo>, MetricRepositoryInterface, AbsolutePath', 'outputs' => 'CollectionPhaseOutput', 'state_owner' => 'Analysis.Run', 'dependency' => 'after discovery', 'source' => 'src/Analysis/Collection/CollectionOrchestratorInterface.php'],
        ['phase' => 'per-file measurement', 'participant' => '21 MetricCollectorInterface implementations', 'inputs' => 'SplFileInfo, Node[]', 'outputs' => 'MetricBag and typed projections', 'state_owner' => 'owning evidence capabilities', 'dependency' => 'same AST traversal; reset per file', 'source' => 'src/Core/Metric/MetricCollectorInterface.php'],
        ['phase' => 'per-file derivation', 'participant' => 'TypeCoveragePercentCollector', 'inputs' => 'MetricBag', 'outputs' => 'MetricBag(typeCoveragePct)', 'state_owner' => 'Analysis.Evidence.Design', 'dependency' => 'requires collector id type-coverage', 'source' => 'src/Metrics/Design/TypeCoveragePercentCollector.php'],
        ['phase' => 'per-file derivation', 'participant' => 'MaintainabilityIndexCollector', 'inputs' => 'MetricBag', 'outputs' => 'MetricBag(maintainabilityIndex)', 'state_owner' => 'Analysis.Evidence.Maintainability', 'dependency' => 'requires halstead, cyclomatic-complexity, method-statement-count', 'source' => 'src/Metrics/Maintainability/MaintainabilityIndexCollector.php'],
        ['phase' => 'dependency graph', 'participant' => 'DependencyGraphBuilder', 'inputs' => 'list<Dependency>, list<LogicalClassPath>', 'outputs' => 'DependencyGraph', 'state_owner' => 'Analysis.Evidence.DependencyModel', 'dependency' => 'consumes raw collection dependencies', 'source' => 'src/Analysis/Collection/Dependency/DependencyGraphBuilder.php'],
        ['phase' => 'architecture preparation', 'participant' => 'ArchitectureProcessor', 'inputs' => 'DependencyGraphInterface, ClassSet', 'outputs' => 'retained prepared ArchitectureConfiguration', 'state_owner' => 'Analysis.Policy.Architecture', 'dependency' => 'lifecycle bind first; LayerViolationRule consumes retained state', 'source' => 'src/Architecture/Processing/ArchitectureProcessor.php'],
        ['phase' => 'aggregation', 'participant' => '4 AggregationPhaseInterface implementations', 'inputs' => 'MetricRepositoryInterface, list<MetricDefinition>', 'outputs' => 'repository enrichment and NamespaceTree', 'state_owner' => 'Analysis.Run and Measurement', 'dependency' => 'callable -> class -> namespace tree -> project', 'source' => 'src/Analysis/Aggregator'],
        ['phase' => 'global derivation', 'participant' => 'CouplingCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'CA, CE, CBO, instability', 'state_owner' => 'Analysis.Evidence.Coupling', 'dependency' => 'no global predecessor', 'source' => 'src/Metrics/Coupling/CouplingCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'AbstractnessCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'abstractness', 'state_owner' => 'Analysis.Evidence.Coupling', 'dependency' => 'requires aggregated size type counts', 'source' => 'src/Metrics/Coupling/AbstractnessCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'ClassRankCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'classRank', 'state_owner' => 'Analysis.Evidence.Coupling', 'dependency' => 'requires CA and CE', 'source' => 'src/Metrics/Coupling/ClassRankCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'DistanceCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'distance', 'state_owner' => 'Analysis.Evidence.Coupling', 'dependency' => 'requires instability and abstractness', 'source' => 'src/Metrics/Coupling/DistanceCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'DitGlobalCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'DIT', 'state_owner' => 'Analysis.Evidence.Design', 'dependency' => 'no global predecessor; overwrites per-file DIT', 'source' => 'src/Metrics/Structure/DitGlobalCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'NocCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'NOC', 'state_owner' => 'Analysis.Evidence.Design', 'dependency' => 'no global predecessor', 'source' => 'src/Metrics/Structure/NocCollector.php'],
        ['phase' => 'global reaggregation', 'participant' => 'MetricAggregator(global definitions)', 'inputs' => 'MetricRepositoryInterface, NamespaceTree', 'outputs' => 'namespace/project aggregates', 'state_owner' => 'Analysis.Run and Measurement', 'dependency' => 'after all global collectors', 'source' => 'src/Analysis/Pipeline/MetricEnricher.php'],
        ['phase' => 'computed derivation', 'participant' => 'ComputedMetricEvaluator', 'inputs' => 'MetricRepositoryInterface, list<ComputedMetricDefinition>', 'outputs' => 'configured computed metrics', 'state_owner' => 'Analysis.Evidence.ComputedMetrics', 'dependency' => 'definition DAG; static definition holder; skipped without files/definitions', 'source' => 'src/Metrics/ComputedMetric/ComputedMetricEvaluator.php'],
        ['phase' => 'graph inspection', 'participant' => 'CircularDependencyDetector', 'inputs' => 'DependencyGraphInterface', 'outputs' => 'list<Cycle>', 'state_owner' => 'Analysis.Evidence.CircularDependency', 'dependency' => 'rule-selection gated; result consumed by CircularDependencyRule', 'source' => 'src/Analysis/Collection/Dependency/CircularDependencyDetector.php'],
        ['phase' => 'file-set inspection', 'participant' => 'DuplicationInspectionInterface', 'inputs' => 'list<SplFileInfo>', 'outputs' => 'replaced run-scoped DuplicationResultProvider state', 'state_owner' => 'Analysis.Evidence.Duplication', 'dependency' => 'rule-selection gated; provider consumed by CodeDuplicationRule', 'source' => 'src/Analysis/Evidence/Duplication/Contract/DuplicationInspectionInterface.php'],
        ['phase' => 'rule execution', 'participant' => '41 RuleInterface implementations', 'inputs' => 'AnalysisContext', 'outputs' => 'list<Violation> and last RuleExclusionStats', 'state_owner' => 'Analysis.Finding and feature rules', 'dependency' => 'producer selection then per-rule exclusions and channel selection', 'source' => 'src/Analysis/RuleExecution/RuleExecutor.php'],
        ['phase' => 'report policy pipeline', 'participant' => 'ViolationFilterPipeline stages', 'inputs' => 'list<Violation> plus suppression/config/baseline/git scope', 'outputs' => 'ViolationFilterResult', 'state_owner' => 'Inline, Baseline, Reporting', 'dependency' => 'suppression -> path -> namespace -> baseline -> git', 'source' => 'src/Infrastructure/Console/ViolationFilterPipeline.php'],
        ['phase' => 'report enrichment', 'participant' => 'SummaryEnricher', 'inputs' => 'Report', 'outputs' => 'health/debt/impact summary', 'state_owner' => 'mixed ComputedMetrics/Prioritization/Reporting seam', 'dependency' => 'cross-capability orchestration before formatters', 'source' => 'src/Reporting/Health/SummaryEnricher.php'],
        ['phase' => 'report projection', 'participant' => '11 FormatterInterface implementations', 'inputs' => 'Report, FormatterContext', 'outputs' => 'string', 'state_owner' => 'Reporting', 'dependency' => 'selected after filtering/enrichment', 'source' => 'src/Reporting/Formatter/FormatterInterface.php'],
    ];
}

/**
 * @param list<string> $header
 * @param list<list<string>> $rows
 */
function tsv(array $header, array $rows): string
{
    $stream = fopen('php://temp', 'w+');
    if ($stream === false) {
        fail('cannot allocate temporary TSV stream');
    }
    fputcsv($stream, $header, "\t", '"', '');
    foreach ($rows as $row) {
        $normalized = array_map(
            static fn(string $field): string => $field === '' ? '-' : $field,
            $row,
        );
        fputcsv($stream, $normalized, "\t", '"', '');
    }
    rewind($stream);
    $contents = stream_get_contents($stream);
    fclose($stream);
    if ($contents === false) {
        fail('cannot read temporary TSV stream');
    }

    return $contents;
}

function writeGenerated(string $path, string $contents): void
{
    $temporary = $path . '.tmp.' . getmypid();
    if (file_put_contents($temporary, $contents) === false || !rename($temporary, $path)) {
        fail('cannot write ' . $path);
    }
}

$root = dirname(__DIR__);
$arguments = $_SERVER['argv'] ?? [];
$check = in_array('--check', $arguments, true);
$manifestArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--manifest='),
));
$outputDirectoryArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--output-directory='),
));
$qmxOutputArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--qmx-output='),
));
$qmxSourceArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--qmx-source='),
));
$documentationProbeArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--documentation-probe='),
));
if (count($manifestArguments) > 1) {
    fail('only one --manifest path may be provided');
}
if (count($outputDirectoryArguments) > 1 || count($qmxOutputArguments) > 1 || count($qmxSourceArguments) > 1 || count($documentationProbeArguments) > 1) {
    fail('only one output directory, qmx source/output, and documentation probe may be provided');
}
$unknownArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => $argument !== '--check'
        && !str_starts_with($argument, '--manifest=')
        && !str_starts_with($argument, '--output-directory=')
        && !str_starts_with($argument, '--qmx-output=')
        && !str_starts_with($argument, '--qmx-source=')
        && !str_starts_with($argument, '--documentation-probe='),
));
if ($unknownArguments !== []) {
    fail('unknown argument: ' . implode(', ', $unknownArguments));
}
$manifestPath = $manifestArguments === []
    ? $root . '/docs/internal/modular-architecture-manifest.json'
    : substr($manifestArguments[0], strlen('--manifest='));
$schemaPath = $root . '/docs/internal/modular-architecture-manifest.schema.json';
$outputDirectory = $outputDirectoryArguments === []
    ? $root . '/docs/internal/generated/modular-architecture'
    : substr($outputDirectoryArguments[0], strlen('--output-directory='));
$qmxOutputPath = $qmxOutputArguments === []
    ? $root . '/qmx.yaml'
    : substr($qmxOutputArguments[0], strlen('--qmx-output='));
$qmxSourcePath = $qmxSourceArguments === []
    ? $root . '/qmx.yaml'
    : substr($qmxSourceArguments[0], strlen('--qmx-source='));
$documentationProbe = $documentationProbeArguments === []
    ? null
    : substr($documentationProbeArguments[0], strlen('--documentation-probe='));

$manifest = loadAndValidateManifest($manifestPath, $schemaPath);
if ($documentationProbe !== null) {
    fwrite(STDOUT, implode("\t", documentationDisposition($documentationProbe)) . "\n");
    exit(0);
}
$rows = declarations($root);
if ($rows === []) {
    fail('no production declarations found');
}

$declarations = $manifest['declarations'];
$actualNames = array_map(static fn(array $row): string => $row['fqcn'], $rows);
$manifestNames = array_map(static fn(int|string $name): string => (string) $name, array_keys($declarations));
sort($actualNames, SORT_STRING);
sort($manifestNames, SORT_STRING);
if ($actualNames !== $manifestNames) {
    failSetDifference('manifest declarations do not match the production AST', $manifestNames, $actualNames);
}

$usedOwners = [];
foreach ($rows as &$row) {
    $entry = $declarations[$row['fqcn']];
    foreach (['path', 'kind'] as $field) {
        if ($entry[$field] !== $row[$field]) {
            fail(sprintf('%s mismatch for %s: manifest=%s AST=%s', $field, $row['fqcn'], $entry[$field], $row[$field]));
        }
    }
    validateDeclarationEntry($row['fqcn'], $entry, $declarations, $manifest['owners']);
    $row['proposed_owner'] = $entry['owner'];
    $row['proposed_status'] = $entry['visibility'];
    $row['closure_package'] = $entry['closure_package'];
    $usedOwners[$entry['owner']] = true;
}
unset($row);

$owners = $manifest['owners'];
sort($owners, SORT_STRING);
$actualOwners = array_keys($usedOwners);
sort($actualOwners, SORT_STRING);
if ($owners !== $actualOwners) {
    failSetDifference('manifest owners do not match declaration owners', $owners, $actualOwners);
}
validateGeneratedLayerNames($owners, $manifest['enforcement_seams']);

$byName = [];
foreach ($rows as $row) {
    $byName[$row['fqcn']] = $row;
}

$observedPairs = [];
$crossOwnerImports = [];
foreach ($rows as $row) {
    foreach ($row['dependencies'] as $dependency) {
        if (!isset($byName[$dependency])) {
            continue;
        }
        $observedPairs[$row['fqcn'] . "\0" . $dependency] = true;
        if ($row['proposed_owner'] === $byName[$dependency]['proposed_owner']) {
            continue;
        }
        $crossOwnerImports[] = [
            $row['fqcn'],
            $row['proposed_owner'],
            $dependency,
            $byName[$dependency]['proposed_owner'],
            $byName[$dependency]['proposed_status'],
            $byName[$dependency]['closure_package'],
        ];
    }
}
usort($crossOwnerImports, static fn(array $left, array $right): int => $left <=> $right);

$authorization = validateAuthorizations($manifest, $byName, $observedPairs);
$enforcement = buildEnforcementProjection($manifest, $byName, $observedPairs);
assertDag($enforcement['allow'], 'generated qmx allow graph');
validateSeamNecessity($manifest, $byName, $observedPairs);
$consumerCount = 0;
$temporaryConsumerCount = 0;
foreach ($declarations as $entry) {
    $consumerCount += count($entry['consumers']);
    $temporaryConsumerCount += count(array_filter(
        $entry['consumers'],
        static fn(array $consumer): bool => $consumer['source_fqcn'] !== null,
    ));
}

$ownershipRows = [];
foreach ($rows as $row) {
    $ownershipRows[] = [
        $row['path'],
        $row['fqcn'],
        $row['kind'],
        $row['proposed_owner'],
        $row['proposed_status'],
        $row['closure_package'],
        json_encode($declarations[$row['fqcn']]['consumers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ];
}

$extensionDefinitions = [
    'rule' => ['Qualimetrix\\Core\\Rule\\RuleInterface', 'qmx.rule', 'RuleConfigurator + ArchitectureConfigurator -> rule compiler passes'],
    'regular_collector' => ['Qualimetrix\\Core\\Metric\\MetricCollectorInterface', 'qmx.collector', 'CollectorConfigurator -> CollectorCompilerPass -> CompositeCollector'],
    'derived_collector' => ['Qualimetrix\\Core\\Metric\\DerivedCollectorInterface', 'qmx.derived_collector', 'CollectorConfigurator -> CollectorCompilerPass -> CompositeCollector'],
    'global_collector' => ['Qualimetrix\\Core\\Metric\\GlobalContextCollectorInterface', 'qmx.global_collector', 'CollectorConfigurator -> GlobalCollectorCompilerPass -> GlobalCollectorRunner'],
    'formatter' => ['Qualimetrix\\Reporting\\Formatter\\FormatterInterface', 'qmx.formatter', 'OutputConfigurator -> FormatterCompilerPass -> FormatterRegistry'],
    'configuration_stage' => ['Qualimetrix\\Configuration\\Pipeline\\Stage\\ConfigurationStageInterface', 'qmx.configuration_stage', 'ConfigurationConfigurator -> ConfigurationStageCompilerPass -> ConfigurationPipeline'],
    'lifecycle_hook' => ['Qualimetrix\\Analysis\\Lifecycle\\AnalysisLifecycleHookInterface', 'qmx.analysis.lifecycle_hook', 'ArchitectureConfigurator -> tagged iterator -> AnalysisRuntimeConfigurator'],
];
$extensionRows = [];
foreach ($extensionDefinitions as $family => [$target, $tag, $registration]) {
    $extensionCount = 0;
    foreach ($rows as $row) {
        if ($row['kind'] !== 'class' || $row['abstract'] || !isA($row['fqcn'], $target, $byName)) {
            continue;
        }
        $extensionCount++;
        $extensionRows[] = [$family, $row['fqcn'], $row['path'], $tag, $registration];
    }
    if ($extensionCount !== EXPECTED_EXTENSION_COUNTS[$family]) {
        fail("expected {$family} count " . EXPECTED_EXTENSION_COUNTS[$family] . ", got {$extensionCount}");
    }
}
usort($extensionRows, static fn(array $left, array $right): int => $left <=> $right);

$reportingRows = [];
$reportingCounts = [];
foreach ($rows as $row) {
    if (!str_starts_with($row['path'], 'src/Reporting/')) {
        continue;
    }
    $disposition = reportingDisposition($row['fqcn']);
    $reportingCounts[$disposition['classification']] = ($reportingCounts[$disposition['classification']] ?? 0) + 1;
    $reportingRows[] = [$row['path'], $row['fqcn'], $disposition['classification'], $disposition['target_owner'], $row['closure_package']];
}
if (count($reportingRows) !== EXPECTED_REPORTING_DECLARATIONS) {
    fail('expected ' . EXPECTED_REPORTING_DECLARATIONS . ' Reporting declarations, got ' . count($reportingRows));
}
ksort($reportingCounts, SORT_STRING);
if ($reportingCounts !== EXPECTED_REPORTING_CLASSIFICATION_COUNTS) {
    fail('unexpected Reporting classification counts: ' . json_encode($reportingCounts));
}

$stateRows = [];
foreach ($rows as $row) {
    $mutable = array_values(array_filter($row['properties'], static fn(array $property): bool => !$property['readonly']));
    if ($mutable === []) {
        continue;
    }
    $static = array_column(array_values(array_filter($mutable, static fn(array $property): bool => $property['static'])), 'name');
    $instance = array_column(array_values(array_filter($mutable, static fn(array $property): bool => !$property['static'])), 'name');
    sort($static, SORT_STRING);
    sort($instance, SORT_STRING);
    $stateRows[] = [$row['path'], $row['fqcn'], $row['proposed_owner'], $row['closure_package'], stateScope($row), implode(',', $static), implode(',', $instance), lifecycleMethods($row['methods'])];
}

$phaseRows = phaseParticipants();
if (count($phaseRows) !== EXPECTED_PHASE_PARTICIPANTS) {
    fail('expected ' . EXPECTED_PHASE_PARTICIPANTS . ' phase participant rows, got ' . count($phaseRows));
}
foreach ($phaseRows as $phase) {
    if (!is_file($root . '/' . $phase['source']) && !is_dir($root . '/' . $phase['source'])) {
        fail('phase participant source does not exist: ' . $phase['source']);
    }
}

$outputs = [
    'production-ownership.tsv' => tsv(['path', 'fqcn', 'kind', 'proposed_owner', 'proposed_status', 'closure_package', 'consumers'], $ownershipRows),
    'production-cross-owner-imports.tsv' => tsv(['consumer', 'consumer_owner', 'dependency', 'dependency_owner', 'dependency_visibility', 'closure_package'], $crossOwnerImports),
    'production-extension-families.tsv' => tsv(['family', 'implementation', 'path', 'di_tag', 'registration_path'], $extensionRows),
    'production-state-services.tsv' => tsv(['path', 'fqcn', 'proposed_owner', 'closure_package', 'state_scope', 'mutable_static_properties', 'mutable_instance_properties', 'lifecycle_methods'], $stateRows),
    'production-phase-participants.tsv' => tsv(['phase', 'participant', 'typed_inputs', 'typed_outputs_or_state', 'state_owner', 'actual_dependency', 'source'], array_values(array_map(static fn(array $phase): array => array_values($phase), $phaseRows))),
    'production-reporting-classification.tsv' => tsv(['path', 'fqcn', 'classification', 'target_owner', 'closure_package'], $reportingRows),
    'documentation-ownership.tsv' => documentationInventory($root),
    'manifest-enforcement-summary.tsv' => tsv(
        ['metric', 'count'],
        [
            ['declarations', (string) count($rows)],
            ['files', (string) count(array_unique(array_column($rows, 'path')))],
            ['semantic_owners', (string) count($owners)],
            ['contract_consumer_entries', (string) $consumerCount],
            ['temporary_contract_consumer_entries', (string) $temporaryConsumerCount],
            ['exact_dependency_edges', (string) count($observedPairs)],
            ['cross_owner_imports', (string) count($crossOwnerImports)],
            ['semantic_owner_layers', (string) $enforcement['semantic_owner_layer_count']],
            ['singleton_seams', (string) count($manifest['enforcement_seams'])],
            ['exact_internal_grants', (string) $authorization['internal_grant_count']],
            ['coarse_internal_grant_edges', (string) $authorization['coarse_internal_grant_edge_count']],
            ['internal_enforcement_layers', (string) count($enforcement['layers'])],
            ['declared_allow_edges', (string) array_sum(array_map('count', $enforcement['allow']))],
        ],
    ),
];

if (!is_dir($outputDirectory) && !$check && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
    fail('cannot create generated output directory');
}
foreach ($outputs as $name => $contents) {
    emitGenerated($outputDirectory . '/' . $name, $contents, $check);
}
updateQmx($qmxSourcePath, $qmxOutputPath, renderQmxRegion($enforcement), $check);

fwrite(STDOUT, sprintf(
    "%s modular-architecture governance: %d declarations, %d semantic-owner layers, %d seams, %d exact internal grants -> %d coarse edges.\n",
    $check ? 'Checked' : 'Generated',
    count($rows),
    $enforcement['semantic_owner_layer_count'],
    count($manifest['enforcement_seams']),
    $authorization['internal_grant_count'],
    $authorization['coarse_internal_grant_edge_count'],
));

/** @return array<string, mixed> */
function loadAndValidateManifest(string $manifestPath, string $schemaPath): array
{
    foreach ([$manifestPath, $schemaPath] as $path) {
        if (!is_file($path)) {
            fail('missing governance file ' . $path);
        }
    }
    $manifestJson = file_get_contents($manifestPath);
    $schemaJson = file_get_contents($schemaPath);
    if ($manifestJson === false || $schemaJson === false) {
        fail('cannot read manifest or schema');
    }
    $manifestObject = json_decode($manifestJson);
    $schemaObject = json_decode($schemaJson);
    $manifest = json_decode($manifestJson, true);
    if (!is_object($manifestObject) || !is_object($schemaObject) || !is_array($manifest)) {
        fail('manifest or schema is not valid JSON');
    }
    $validator = new JsonSchema\Validator();
    $validator->validate($manifestObject, $schemaObject);
    if (!$validator->isValid()) {
        $errors = array_map(static fn(array $error): string => sprintf('%s: %s', $error['property'], $error['message']), $validator->getErrors());
        fail("manifest schema validation failed:\n- " . implode("\n- ", $errors));
    }

    return $manifest;
}

/**
 * @param array<string, mixed> $entry
 * @param array<string, array<string, mixed>> $declarations
 * @param list<string> $owners
 */
function validateDeclarationEntry(string $fqcn, array $entry, array $declarations, array $owners): void
{
    if (!in_array($entry['owner'], $owners, true)) {
        fail("declaration {$fqcn} names unknown owner {$entry['owner']}");
    }
    $consumers = $entry['consumers'];
    if ($entry['visibility'] === 'internal' && $consumers !== []) {
        fail("internal declaration {$fqcn} cannot publish consumers");
    }
    if ($entry['visibility'] === 'contract' && $consumers === []) {
        fail("contract declaration {$fqcn} must publish at least one used consumer");
    }
    $seen = [];
    foreach ($consumers as $index => $consumer) {
        if (!in_array($consumer['owner'], $owners, true)) {
            fail("consumer {$fqcn}#{$index} names unknown owner {$consumer['owner']}");
        }
        $permanent = $consumer['source_fqcn'] === null && $consumer['closes_in'] === null;
        $temporary = is_string($consumer['source_fqcn']) && is_string($consumer['closes_in']);
        if (!$permanent && !$temporary) {
            fail("consumer {$fqcn}#{$index} must be permanent owner-wide or temporary exact-source");
        }
        if ($temporary) {
            $source = $consumer['source_fqcn'];
            if (!isset($declarations[$source])) {
                fail("temporary consumer {$fqcn}#{$index} names unknown source {$source}");
            }
            if ($declarations[$source]['owner'] !== $consumer['owner']) {
                fail("temporary consumer {$fqcn}#{$index} source owner does not match {$consumer['owner']}");
            }
        }
        $key = $consumer['owner'] . "\0" . ($consumer['source_fqcn'] ?? '*');
        if (isset($seen[$key])) {
            fail("duplicate consumer authorization on {$fqcn} for {$consumer['owner']}");
        }
        $seen[$key] = true;
    }
}

/**
 * @param list<string> $expected
 * @param list<string> $actual
 */
function failSetDifference(string $label, array $expected, array $actual): never
{
    $missing = array_values(array_diff($expected, $actual));
    $extra = array_values(array_diff($actual, $expected));
    fail(sprintf('%s; missing=[%s] extra=[%s]', $label, implode(', ', $missing), implode(', ', $extra)));
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>> $byName
 * @param array<string, true> $observedPairs
 *
 * @return array{internal_grant_count: int, coarse_internal_grant_edge_count: int}
 */
function validateAuthorizations(array $manifest, array $byName, array $observedPairs): array
{
    $consumerUse = [];
    foreach ($manifest['declarations'] as $target => $entry) {
        foreach ($entry['consumers'] as $index => $_consumer) {
            $consumerUse[$target . "\0" . $index] = false;
        }
    }
    $grantByPair = [];
    $grantUse = [];
    foreach ($manifest['temporary_internal_grants'] as $index => $grant) {
        $source = $grant['source_fqcn'];
        $target = $grant['target_fqcn'];
        if (!isset($byName[$source], $byName[$target])) {
            fail("internal grant {$index} references an unknown declaration");
        }
        if ($byName[$target]['proposed_status'] !== 'internal') {
            fail("internal grant {$source} -> {$target} targets a contract declaration");
        }
        if ($byName[$target]['proposed_owner'] !== $grant['owner']) {
            fail("internal grant {$source} -> {$target} has the wrong accountable owner");
        }
        $key = $source . "\0" . $target;
        if (isset($grantByPair[$key])) {
            fail("duplicate internal grant {$source} -> {$target}");
        }
        $grantByPair[$key] = $grant;
        $grantUse[$key] = false;
    }

    foreach ($observedPairs as $pair => $_true) {
        [$source, $target] = explode("\0", $pair, 2);
        $sourceRow = $byName[$source];
        $targetRow = $byName[$target];
        if ($sourceRow['proposed_owner'] === $targetRow['proposed_owner']) {
            continue;
        }
        if ($targetRow['proposed_status'] === 'internal') {
            if (!isset($grantByPair[$pair])) {
                fail("unapproved exact internal import {$source} -> {$target}");
            }
            $grantUse[$pair] = true;
            continue;
        }
        $matches = [];
        foreach ($manifest['declarations'][$target]['consumers'] as $index => $consumer) {
            if ($consumer['owner'] !== $sourceRow['proposed_owner']) {
                continue;
            }
            if ($consumer['source_fqcn'] !== null && $consumer['source_fqcn'] !== $source) {
                continue;
            }
            $matches[] = $index;
        }
        if (count($matches) !== 1) {
            fail(sprintf('contract import %s -> %s has %d matching consumer entries', $source, $target, count($matches)));
        }
        $consumerUse[$target . "\0" . $matches[0]] = true;
    }

    foreach ($consumerUse as $key => $used) {
        if (!$used) {
            [$target, $index] = explode("\0", $key, 2);
            fail("unused contract consumer entry {$target}#{$index}");
        }
    }
    foreach ($grantUse as $pair => $used) {
        if (!$used) {
            [$source, $target] = explode("\0", $pair, 2);
            fail("unused internal grant {$source} -> {$target}");
        }
    }

    $coarse = [];
    foreach ($grantByPair as $grant) {
        $source = $byName[$grant['source_fqcn']];
        $target = $byName[$grant['target_fqcn']];
        $coarse[semanticLayerName($source) . "\0" . semanticLayerName($target)] = true;
    }

    return ['internal_grant_count' => count($grantByPair), 'coarse_internal_grant_edge_count' => count($coarse)];
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>> $byName
 * @param array<string, true> $observedPairs
 *
 * @return array{layers: array<string, list<string>>, allow: array<string, list<string>>, semantic_owner_layer_count: int}
 */
function buildEnforcementProjection(array $manifest, array $byName, array $observedPairs, ?string $disabledSeam = null): array
{
    $layers = [];
    $semantic = [];
    foreach ($byName as $fqcn => $row) {
        $semanticName = semanticLayerName($row);
        $semantic[$semanticName] = true;
        $layer = enforcementLayerName($fqcn, $row, $manifest['enforcement_seams'], $disabledSeam);
        $layers[$layer][] = $fqcn;
    }
    ksort($layers, SORT_STRING);
    foreach ($layers as &$classes) {
        sort($classes, SORT_STRING);
    }
    unset($classes);
    $allowSets = array_fill_keys(array_keys($layers), []);
    foreach ($observedPairs as $pair => $_true) {
        [$source, $target] = explode("\0", $pair, 2);
        $sourceLayer = enforcementLayerName($source, $byName[$source], $manifest['enforcement_seams'], $disabledSeam);
        $targetLayer = enforcementLayerName($target, $byName[$target], $manifest['enforcement_seams'], $disabledSeam);
        if ($sourceLayer !== $targetLayer) {
            $allowSets[$sourceLayer][$targetLayer] = true;
        }
    }
    $allow = [];
    foreach ($allowSets as $source => $targetSet) {
        $targets = array_keys($targetSet);
        sort($targets, SORT_STRING);
        $targets[] = 'external';
        $allow[$source] = $targets;
    }
    $allow['external'] = [];

    return ['layers' => $layers, 'allow' => $allow, 'semantic_owner_layer_count' => count($semantic)];
}

/** @param array<string, mixed> $row */
function semanticLayerName(array $row): string
{
    return strtolower(str_replace('.', '-', $row['proposed_owner']));
}

/**
 * @param list<string> $owners
 * @param array<string, array<string, mixed>> $seams
 */
function validateGeneratedLayerNames(array $owners, array $seams): void
{
    $sources = ['external' => 'reserved external layer'];
    foreach ($owners as $owner) {
        $layer = strtolower(str_replace('.', '-', $owner));
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*$/', $layer) !== 1) {
            fail("owner {$owner} produces invalid qmx layer name {$layer}");
        }
        if (isset($sources[$layer])) {
            fail("generated qmx layer name collision on {$layer}: {$sources[$layer]} and owner {$owner}");
        }
        $sources[$layer] = "owner {$owner}";
    }
    foreach ($seams as $fqcn => $seam) {
        $layer = $seam['layer'];
        if (preg_match('/^seam-[a-z0-9]+(?:-[a-z0-9]+)*$/', $layer) !== 1) {
            fail("seam {$fqcn} has invalid canonical qmx layer name {$layer}");
        }
        if (isset($sources[$layer])) {
            fail("generated qmx layer name collision on {$layer}: {$sources[$layer]} and seam {$fqcn}");
        }
        $sources[$layer] = "seam {$fqcn}";
    }
}

/**
 * @param array<string, mixed> $row
 * @param array<string, array<string, mixed>> $seams
 */
function enforcementLayerName(string $fqcn, array $row, array $seams, ?string $disabledSeam): string
{
    if ($fqcn !== $disabledSeam && isset($seams[$fqcn])) {
        return $seams[$fqcn]['layer'];
    }

    return semanticLayerName($row);
}

/** @param array<string, list<string>> $graph */
function assertDag(array $graph, string $label): void
{
    $cycle = findGraphCycle($graph);
    if ($cycle !== null) {
        fail($label . ' contains a cycle: ' . implode(' -> ', $cycle));
    }
}

/** @param array<string, list<string>> $graph */
function graphIsDag(array $graph): bool
{
    return findGraphCycle($graph) === null;
}

/**
 * @param array<string, list<string>> $graph
 *
 * @return ?list<string>
 */
function findGraphCycle(array $graph): ?array
{
    $state = [];
    $path = [];
    $cycle = null;
    $visit = function (string $node) use (&$visit, &$state, &$path, &$cycle, $graph): void {
        if ($cycle !== null || ($state[$node] ?? 0) === 2) {
            return;
        }
        if (($state[$node] ?? 0) === 1) {
            $start = array_search($node, $path, true);
            $cycle = array_slice($path, $start === false ? 0 : $start);
            $cycle[] = $node;
            return;
        }
        $state[$node] = 1;
        $path[] = $node;
        foreach ($graph[$node] ?? [] as $target) {
            if ($target !== 'external') {
                $visit($target);
            }
        }
        array_pop($path);
        $state[$node] = 2;
    };
    foreach (array_keys($graph) as $node) {
        $visit($node);
    }

    return $cycle;
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>> $byName
 * @param array<string, true> $observedPairs
 */
function validateSeamNecessity(array $manifest, array $byName, array $observedPairs): void
{
    $layers = [];
    foreach ($manifest['enforcement_seams'] as $fqcn => $seam) {
        if (!isset($byName[$fqcn])) {
            fail("enforcement seam references unknown declaration {$fqcn}");
        }
        if ($byName[$fqcn]['proposed_owner'] !== $seam['semantic_owner']) {
            fail("enforcement seam {$fqcn} changes semantic owner");
        }
        if (isset($layers[$seam['layer']])) {
            fail("enforcement seam layer {$seam['layer']} is not singleton");
        }
        $layers[$seam['layer']] = true;
        $candidate = buildEnforcementProjection($manifest, $byName, $observedPairs, $fqcn);
        if (graphIsDag($candidate['allow'])) {
            fail("enforcement seam {$fqcn} is not necessary to break the current graph");
        }
    }
}

/** @param array{layers: array<string, list<string>>, allow: array<string, list<string>>} $projection */
function renderQmxRegion(array $projection): string
{
    $lines = ['# BEGIN GENERATED MODULAR ARCHITECTURE - DO NOT EDIT', 'architecture:', '  layers:'];
    foreach ($projection['layers'] as $name => $classes) {
        $lines[] = "    - name: {$name}";
        $lines[] = '      patterns:';
        foreach ($classes as $class) {
            $lines[] = "        - '" . str_replace("'", "''", $class) . "'";
        }
    }
    $lines[] = '    - name: external';
    $lines[] = "      patterns: ['**']";
    $lines[] = '      exclude:';
    $lines[] = "        patterns: ['Qualimetrix\\**']";
    $lines[] = '';
    $lines[] = '  allow:';
    foreach ($projection['allow'] as $source => $targets) {
        if ($targets === []) {
            $lines[] = "    {$source}: []";
            continue;
        }
        $lines[] = "    {$source}:";
        foreach ($targets as $target) {
            $lines[] = "      - {$target}";
        }
    }
    $lines[] = '';
    $lines[] = '  coverage: error';
    $lines[] = '# END GENERATED MODULAR ARCHITECTURE';

    return implode("\n", $lines) . "\n";
}

function updateQmx(string $sourcePath, string $outputPath, string $region, bool $check): void
{
    $current = file_get_contents($sourcePath);
    if ($current === false) {
        fail('cannot read qmx.yaml');
    }
    $begin = '# BEGIN GENERATED MODULAR ARCHITECTURE - DO NOT EDIT';
    $end = '# END GENERATED MODULAR ARCHITECTURE';
    if (substr_count($current, $begin) !== 1 || substr_count($current, $end) !== 1) {
        fail('qmx.yaml must contain exactly one generated architecture marker pair');
    }
    $beginPosition = strpos($current, $begin);
    $endPosition = strpos($current, $end);
    if ($beginPosition === false || $endPosition === false || $beginPosition >= $endPosition) {
        fail('qmx.yaml generated architecture markers are misordered');
    }
    $pattern = '/^' . preg_quote($begin, '/') . '.*?^' . preg_quote($end, '/') . '\n(?:[ \t]*\n)*/ms';
    $updated = preg_replace($pattern, $region . "\n", $current, 1, $count);
    if ($updated === null || $count !== 1) {
        fail('cannot locate the qmx architecture region');
    }
    if ($check) {
        $output = is_file($outputPath) ? file_get_contents($outputPath) : false;
        if ($output !== $updated) {
            fail('qmx.yaml generated architecture region is stale');
        }
        return;
    }
    writeGenerated($outputPath, $updated);
}

function emitGenerated(string $path, string $contents, bool $check): void
{
    if ($check) {
        $current = is_file($path) ? file_get_contents($path) : false;
        if ($current !== $contents) {
            fail('generated artifact is stale: ' . $path);
        }
        return;
    }
    writeGenerated($path, $contents);
}

function documentationInventory(string $root): string
{
    $paths = commandOutputLines([
        'git',
        'ls-files',
        '--cached',
        '--others',
        '--exclude-standard',
        '--',
        'AGENTS.md',
        'CLAUDE.md',
        'CHANGELOG.md',
        'README.md',
        ':(glob)docs/**/*.md',
        ':(glob)website/docs/**/*.md',
        ':(glob)src/**/README.md',
    ], $root);
    $paths = array_values(array_filter(
        $paths,
        static fn(string $path): bool => !str_ends_with($path, '.local.md')
            && !str_starts_with($path, 'docs/internal/generated/')
            && (is_file($root . '/' . $path) || is_link($root . '/' . $path)),
    ));
    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);
    $rows = [];
    foreach ($paths as $path) {
        [$owner, $closure, $disposition] = documentationDisposition($path);
        $rows[] = [$path, $owner, $closure, $disposition];
    }

    return tsv(['current_path', 'subject_owner', 'closure_package', 'disposition'], $rows);
}

/** @param list<string> $command
 *
 * @return list<string>
 */
function commandOutputLines(array $command, string $workingDirectory): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory);
    if (!is_resource($process)) {
        fail('cannot start documentation discovery');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($stdout === false || $stderr === false || $exitCode !== 0) {
        fail('documentation discovery failed: ' . trim($stderr === false ? '' : $stderr));
    }

    return array_values(array_filter(explode("\n", trim($stdout)), static fn(string $line): bool => $line !== ''));
}

/** @return array{string, string, string} */
function documentationDisposition(string $path): array
{
    $p0 = [
        'AGENTS.md',
        'CLAUDE.md',
        'CHANGELOG.md',
        'docs/ARCHITECTURE.md',
        'docs/adr/0022-capability-oriented-modular-monolith.md',
        'docs/adr/README.md',
        'docs/internal/MODULE_README_TEMPLATE.md',
        'docs/internal/plans/modular-architecture.md',
        'src/Architecture/README.md',
        'src/Configuration/README.md',
        'website/docs/reference/default-thresholds.md',
        'website/docs/reference/default-thresholds.ru.md',
        'website/docs/rules/architecture.md',
        'website/docs/rules/architecture.ru.md',
    ];
    if (in_array($path, $p0, true)) {
        return ['Architecture.Governance', 'P0-D', 'P0 governance documentation; review with the manifest and generated topology.'];
    }

    $exact = [
        'docs/adr/0001-computed-metrics.md' => ['Analysis.Evidence.ComputedMetrics', 'P5'],
        'docs/adr/0017-baseline-ceiling.md' => ['Analysis.Policy.Baseline', 'P6'],
        'docs/adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md' => ['Analysis.Evidence.DependencyModel', 'P2'],
        'src/Analysis/README.md' => ['Analysis.Run', 'P3'],
        'src/Analysis/Evidence/Duplication/README.md' => ['Analysis.Evidence.Duplication', 'P1'],
        'src/Baseline/README.md' => ['Analysis.Policy.Baseline', 'P6'],
        'website/docs/getting-started/configuration.md' => ['Analysis.Run', 'P3'],
        'website/docs/getting-started/configuration.ru.md' => ['Analysis.Run', 'P3'],
        'website/docs/reference/health-scores.md' => ['Analysis.Evidence.ComputedMetrics', 'P5'],
        'website/docs/reference/health-scores.ru.md' => ['Analysis.Evidence.ComputedMetrics', 'P5'],
        'website/docs/rules/duplication.md' => ['Analysis.Evidence.Duplication', 'P1'],
        'website/docs/rules/duplication.ru.md' => ['Analysis.Evidence.Duplication', 'P1'],
        'website/docs/usage/baseline.md' => ['Analysis.Policy.Baseline', 'P6'],
        'website/docs/usage/baseline.ru.md' => ['Analysis.Policy.Baseline', 'P6'],
        'website/docs/usage/output-formats.md' => ['Analysis.Finding', 'P6'],
        'website/docs/usage/output-formats.ru.md' => ['Analysis.Finding', 'P6'],
    ];
    if (isset($exact[$path])) {
        $mapped = $exact[$path];

        return [$mapped[0], $mapped[1], 'Move or update atomically with the named migration package.'];
    }

    $prefixes = [
        'src/Metrics/' => ['Analysis.Evidence.Measurement', 'P7'],
        'src/Rules/' => ['Analysis.Evidence.Measurement', 'P7'],
        'website/docs/rules/code-smell' => ['Analysis.Evidence.Measurement', 'P7'],
        'website/docs/rules/cohesion' => ['Analysis.Evidence.Measurement', 'P7'],
        'website/docs/rules/complexity' => ['Analysis.Evidence.Measurement', 'P7'],
        'website/docs/rules/coupling' => ['Analysis.Evidence.Measurement', 'P7'],
        'website/docs/rules/design' => ['Analysis.Evidence.Measurement', 'P7'],
        'website/docs/rules/maintainability' => ['Analysis.Evidence.Measurement', 'P7'],
        'website/docs/rules/security' => ['Analysis.Evidence.Measurement', 'P7'],
        'website/docs/rules/size' => ['Analysis.Evidence.Measurement', 'P7'],
    ];
    foreach ($prefixes as $prefix => [$owner, $closure]) {
        if (str_starts_with($path, $prefix)) {
            return [$owner, $closure, 'Move or update atomically with the named migration package.'];
        }
    }

    $shared = [
        'README.md',
        'docs/README.md',
        'docs/adr/0002-html-report.md',
        'docs/adr/0003-reporting-ux-redesign.md',
        'docs/adr/0004-architecture-findings-april-2026.md',
        'docs/adr/0005-architecture-rules.md',
        'docs/adr/0006-architecture-rules-declaration-order.md',
        'docs/adr/0007-architecture-rules-phase-2-design.md',
        'docs/adr/0008-architecture-processor-service.md',
        'docs/adr/0009-yaml-loader-normalization-model.md',
        'docs/adr/0010-architecture-vertical-slice.md',
        'docs/adr/0011-architecture-rules-errata.md',
        'docs/adr/0012-hybrid-architectural-direction.md',
        'docs/adr/0013-threshold-override-validators.md',
        'docs/adr/0014-deptrac-retirement.md',
        'docs/adr/0015-relative-path-vo.md',
        'docs/adr/0016-subject-cohesion.md',
        'docs/adr/0018-analysis-coverage-verdict-and-output-projection.md',
        'docs/adr/0019-namespace-metric-ownership-and-attribution.md',
        'docs/adr/0020-method-size-and-npath-semantics.md',
        'docs/internal/CLI_CONVENTIONS.md',
        'docs/internal/COMPETITOR_COMPARISON.md',
        'docs/internal/PRODUCT_ROADMAP.md',
        'docs/internal/PRODUCT_VISION.md',
        'docs/internal/SCANNER_VALIDATION_ROUND_1_FINDINGS.md',
        'docs/internal/SCANNER_VALIDATION_ROUND_1_PLAN.md',
        'docs/internal/SCANNER_VALIDATION_ROUND_2_PLAN.md',
        'docs/internal/plans/diff-mode-new-only.md',
        'docs/internal/plans/global-functions-graph.md',
        'docs/internal/plans/phpdoc-dependencies.md',
        'src/Core/Path/README.md',
        'src/Core/README.md',
        'src/Infrastructure/Cache/README.md',
        'src/Infrastructure/Console/README.md',
        'src/Infrastructure/Git/README.md',
        'src/Infrastructure/Logging/README.md',
        'src/Infrastructure/Profiler/README.md',
        'src/Infrastructure/README.md',
        'src/Reporting/README.md',
        'website/docs/changelog.md',
        'website/docs/ci-cd/github-actions.md',
        'website/docs/ci-cd/github-actions.ru.md',
        'website/docs/ci-cd/other-ci.md',
        'website/docs/ci-cd/other-ci.ru.md',
        'website/docs/getting-started/installation.md',
        'website/docs/getting-started/installation.ru.md',
        'website/docs/getting-started/quick-start.md',
        'website/docs/getting-started/quick-start.ru.md',
        'website/docs/guides/architecture-investigation.md',
        'website/docs/guides/architecture-investigation.ru.md',
        'website/docs/index.md',
        'website/docs/index.ru.md',
        'website/docs/rules/index.md',
        'website/docs/rules/index.ru.md',
        'website/docs/usage/cli-options.md',
        'website/docs/usage/cli-options.ru.md',
        'website/docs/usage/git-integration.md',
        'website/docs/usage/git-integration.ru.md',
        'website/docs/usage/usage-scenarios.md',
        'website/docs/usage/usage-scenarios.ru.md',
    ];
    if (in_array($path, $shared, true)) {
        return ['Architecture.Governance', 'shared', 'Shared repository documentation; retain in place and update only when its governed surface changes.'];
    }

    return fail('unclassified committable documentation path: ' . $path);
}
