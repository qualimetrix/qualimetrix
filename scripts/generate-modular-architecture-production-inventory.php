<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return never */
function fail(string $message): void
{
    fwrite(STDERR, "Production inventory generation failed: {$message}\n");
    exit(1);
}

/**
 * @param array<string, string> $sourceOverrides repository-relative source path to replacement source path
 *
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
 *     contract_property_containments: list<string>,
 *     contract_surface_containments: list<string>,
 *     class_string_targets: list<array{source_fqcn: string, member_kind: string, member_name: string, target_fqcn: string}>,
 *     class_string_metadata_targets: list<string>,
 *     native_method_returns: array<string, list<string>>,
 *     dependencies: list<string>,
 * }>
 */
function declarations(string $root, array $sourceOverrides = []): array
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
        $relativePath = relativePath($root, $path);
        $sourcePath = $sourceOverrides[$relativePath] ?? $path;
        $code = file_get_contents($sourcePath);
        if ($code === false) {
            fail('cannot read ' . $sourcePath);
        }

        $ast = $parser->parse($code);
        if ($ast === null) {
            fail('parser returned no AST for ' . $path);
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);
        $imports = importAliases($ast);

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
            $contractPropertyContainments = [];
            foreach ($declaration->getProperties() as $property) {
                foreach (resolvedTypeNames($property->type) as $type) {
                    $contractPropertyContainments[$type] = true;
                }
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
            $nativeMethodReturns = [];
            foreach ($declaration->getMethods() as $method) {
                $methods[] = $method->name->toString();
                $nativeMethodReturns[$method->name->toString()] = resolvedTypeNames($method->returnType);
                foreach ($method->params as $parameter) {
                    if (
                        !$parameter->isPromoted()
                        || !$parameter->var instanceof Node\Expr\Variable
                        || !is_string($parameter->var->name)
                    ) {
                        continue;
                    }
                    foreach (resolvedTypeNames($parameter->type) as $type) {
                        $contractPropertyContainments[$type] = true;
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
            $contractPropertyContainments = array_keys($contractPropertyContainments);
            sort($contractPropertyContainments, SORT_STRING);
            $contractSurfaceContainments = array_values(array_unique([
                ...$contractPropertyContainments,
                ...array_merge(...array_values($nativeMethodReturns)),
                ...documentedSurfaceContainments($declaration, $imports, substr($fqcn, 0, (int) strrpos($fqcn, '\\'))),
            ]));
            sort($contractSurfaceContainments, SORT_STRING);
            ksort($nativeMethodReturns, SORT_STRING);
            $classStringTargets = documentedClassStringTargets(
                $declaration,
                $imports,
                substr($fqcn, 0, (int) strrpos($fqcn, '\\')),
                $fqcn,
            );
            $classStringMetadataTargets = documentedClassStringMetadataTargets($declaration, $imports, substr($fqcn, 0, (int) strrpos($fqcn, '\\')));

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
                'contract_property_containments' => $contractPropertyContainments,
                'contract_surface_containments' => $contractSurfaceContainments,
                'class_string_targets' => $classStringTargets,
                'class_string_metadata_targets' => $classStringMetadataTargets,
                'native_method_returns' => $nativeMethodReturns,
                'dependencies' => $dependencies,
            ];
        }
    }

    usort($rows, static fn(array $left, array $right): int => $left['fqcn'] <=> $right['fqcn']);

    return $rows;
}

/**
 * @param array<Node> $ast
 *
 * @return array<string, string>
 */
function importAliases(array $ast): array
{
    $imports = [];
    foreach ((new NodeFinder())->findInstanceOf($ast, Node\Stmt\UseUse::class) as $use) {
        $resolved = $use->name->getAttribute('resolvedName');
        $fqcn = $resolved instanceof Node\Name ? $resolved->toString() : $use->name->toString();
        $alias = $use->alias?->toString() ?? shortName($fqcn);
        $imports[$alias] = $fqcn;
    }

    return $imports;
}

/**
 * PHPDoc proves a surface containment only for a promoted property or a public
 * method signature whose generic/array-shape type resolves to a project class.
 * Free text is deliberately ignored.
 *
 * @param array<string, string> $imports
 *
 * @return list<string>
 */
function documentedSurfaceContainments(Node\Stmt\ClassLike $declaration, array $imports, string $namespace): array
{
    $constructor = $declaration->getMethod('__construct');
    $promoted = [];
    if ($constructor !== null) {
        foreach ($constructor->params as $parameter) {
            if ($parameter->isPromoted()
                && $parameter->var instanceof Node\Expr\Variable
                && is_string($parameter->var->name)
            ) {
                $promoted[$parameter->var->name] = true;
            }
        }
    }

    $containments = [];
    $constructorDoc = $constructor?->getDocComment()?->getText();
    if ($constructorDoc !== null) {
        preg_match_all('/@param\s+([^\r\n]*?)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $constructorDoc, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (isset($promoted[$match[2]])) {
                collectDocumentedTypes($match[1], $imports, $namespace, $containments);
            }
        }
    }
    foreach ($declaration->getMethods() as $method) {
        $doc = $method->getDocComment()?->getText();
        if ($doc === null) {
            continue;
        }
        preg_match_all('/@param\s+([^\r\n]*?)\s+\$[A-Za-z_][A-Za-z0-9_]*/', $doc, $parameterMatches);
        foreach ($parameterMatches[1] as $typeExpression) {
            collectDocumentedTypes($typeExpression, $imports, $namespace, $containments);
        }
        if (preg_match('/@return\s+([^\s]+)/', $doc, $match) === 1) {
            collectDocumentedTypes($match[1], $imports, $namespace, $containments);
        }
    }

    $containments = array_keys($containments);
    sort($containments, SORT_STRING);

    return $containments;
}

/**
 * @param array<string, string> $imports
 *
 * @return list<array{source_fqcn: string, member_kind: string, member_name: string, target_fqcn: string}>
 */
function documentedClassStringTargets(Node\Stmt\ClassLike $declaration, array $imports, string $namespace, string $sourceFqcn): array
{
    $targets = [];
    foreach ($declaration->getProperties() as $property) {
        $doc = $property->getDocComment()?->getText();
        if ($doc === null || preg_match('/@var\s+([^\r\n*]+)/', $doc, $match) !== 1) {
            continue;
        }
        foreach ($property->props as $item) {
            addClassStringTargets($targets, $match[1], $imports, $namespace, $sourceFqcn, 'property', $item->name->toString());
        }
    }

    $constructor = $declaration->getMethod('__construct');
    if ($constructor !== null) {
        addClassStringParameterTargets($targets, $constructor, $imports, $namespace, $sourceFqcn, 'constructor');
    }
    foreach ($declaration->getMethods() as $method) {
        if ($method->name->toString() !== '__construct') {
            addClassStringParameterTargets($targets, $method, $imports, $namespace, $sourceFqcn, 'method');
        }
        $doc = $method->getDocComment()?->getText();
        if ($doc !== null && preg_match('/@return\s+([^\r\n*]+)/', $doc, $match) === 1) {
            addClassStringTargets($targets, $match[1], $imports, $namespace, $sourceFqcn, 'method', $method->name->toString());
        }
    }

    usort($targets, static fn(array $left, array $right): int => [
        $left['source_fqcn'],
        $left['member_kind'],
        $left['member_name'],
        $left['target_fqcn'],
    ] <=> [
        $right['source_fqcn'],
        $right['member_kind'],
        $right['member_name'],
        $right['target_fqcn'],
    ]);

    return $targets;
}

/**
 * Covers local PHPDoc metadata proofs (such as compiler-pass assertions) that
 * do not belong to a property, constructor, or method surface row.
 *
 * @param array<string, string> $imports
 *
 * @return list<string>
 */
function documentedClassStringMetadataTargets(Node\Stmt\ClassLike $declaration, array $imports, string $namespace): array
{
    $targets = [];
    foreach ((new NodeFinder())->find($declaration, static fn(Node $node): bool => $node->getDocComment() !== null) as $node) {
        $doc = $node->getDocComment()?->getText();
        if ($doc === null) {
            continue;
        }
        preg_match_all('/@(?:var|param|return)\s+([^\r\n*]+)/', $doc, $matches);
        foreach ($matches[1] as $typeExpression) {
            $resolved = [];
            addClassStringTargets($resolved, $typeExpression, $imports, $namespace, '__metadata__', 'metadata', 'metadata');
            foreach ($resolved as $target) {
                $targets[$target['target_fqcn']] = true;
            }
        }
    }

    $targets = array_keys($targets);
    sort($targets, SORT_STRING);

    return $targets;
}

/**
 * @param list<array{source_fqcn: string, member_kind: string, member_name: string, target_fqcn: string}> $targets
 * @param array<string, string> $imports
 */
function addClassStringParameterTargets(array &$targets, Node\Stmt\ClassMethod $method, array $imports, string $namespace, string $sourceFqcn, string $memberKind): void
{
    $doc = $method->getDocComment()?->getText();
    if ($doc === null) {
        return;
    }
    preg_match_all('/@param\s+([^\r\n*]*?)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $doc, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        addClassStringTargets($targets, $match[1], $imports, $namespace, $sourceFqcn, $memberKind, $match[2]);
    }
}

/**
 * @param list<array{source_fqcn: string, member_kind: string, member_name: string, target_fqcn: string}> $targets
 * @param array<string, string> $imports
 */
function addClassStringTargets(array &$targets, string $typeExpression, array $imports, string $namespace, string $sourceFqcn, string $memberKind, string $memberName): void
{
    $unquoted = preg_replace('/(?:\'[^\']*\'|"[^"]*")/', '', $typeExpression);
    if ($unquoted === null) {
        fail('cannot normalize PHPDoc type expression');
    }
    if (preg_match_all('/(?<![A-Za-z0-9_-])class-string\s*</i', $unquoted, $matches, PREG_OFFSET_CAPTURE) !== 1 && $matches[0] === []) {
        return;
    }
    foreach ($matches[0] as [$token, $offset]) {
        $start = $offset + strlen($token) - 1;
        $depth = 0;
        $end = null;
        for ($index = $start; $index < strlen($unquoted); $index++) {
            if ($unquoted[$index] === '<') {
                $depth++;
            } elseif ($unquoted[$index] === '>' && --$depth === 0) {
                $end = $index;
                break;
            }
        }
        if ($end === null) {
            continue;
        }
        $candidate = trim(substr($unquoted, $start + 1, $end - $start - 1));
        if (preg_match('/^\\\\?([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)$/', $candidate, $name) !== 1) {
            continue;
        }
        if (preg_match('/^[A-Z]$/', ltrim($candidate, '\\')) === 1) {
            continue;
        }
        $target = resolveDocumentedType($candidate, $imports, $namespace);
        if (!str_starts_with($target, 'Qualimetrix\\')) {
            continue;
        }
        $targets[] = [
            'source_fqcn' => $sourceFqcn,
            'member_kind' => $memberKind,
            'member_name' => $memberName,
            'target_fqcn' => $target,
        ];
    }
}

/** @param array<string, string> $imports */
function resolveDocumentedType(string $type, array $imports, string $namespace): string
{
    if (str_starts_with($type, '\\')) {
        return substr($type, 1);
    }

    $firstSeparator = strpos($type, '\\');
    $alias = $firstSeparator === false ? $type : substr($type, 0, $firstSeparator);

    return isset($imports[$alias])
        ? $imports[$alias] . ($firstSeparator === false ? '' : substr($type, $firstSeparator))
        : $namespace . '\\' . $type;
}

/**
 * @param array<string, string> $imports
 * @param array<string, true> $containments
 */
function collectDocumentedTypes(string $typeExpression, array $imports, string $namespace, array &$containments): void
{
    preg_match_all('/(?<![A-Za-z0-9_\\\\])([A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/', $typeExpression, $types);
    foreach ($types[1] as $type) {
        $firstSeparator = strpos($type, '\\');
        $alias = $firstSeparator === false ? $type : substr($type, 0, $firstSeparator);
        $fqcn = isset($imports[$alias])
            ? $imports[$alias] . ($firstSeparator === false ? '' : substr($type, $firstSeparator))
            : $namespace . '\\' . $type;
        if (str_starts_with($fqcn, 'Qualimetrix\\')) {
            $containments[$fqcn] = true;
        }
    }
}

/** @return list<string> */
function resolvedTypeNames(Node\Identifier|Node\Name|Node\ComplexType|null $type): array
{
    if ($type === null || $type instanceof Node\Identifier) {
        return [];
    }
    if ($type instanceof Node\NullableType) {
        return resolvedTypeNames($type->type);
    }
    if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
        $names = [];
        foreach ($type->types as $nested) {
            array_push($names, ...resolvedTypeNames($nested));
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    $resolved = $type->getAttribute('resolvedName');

    if ($resolved instanceof Node\Name) {
        return [$resolved->toString()];
    }
    if ($type instanceof Node\Name) {
        return [$type->toString()];
    }

    fail('unsupported native complex type ' . $type::class);
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
    if ($row['fqcn'] === 'Qualimetrix\\Analysis\\Evidence\\Coupling\\CouplingAnalysis') {
        return 'analysis-run configuration';
    }
    if (str_starts_with($row['path'], 'src/Analysis/Evidence/Measurement/Repository/')) {
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
        ['phase' => 'configuration', 'participant' => '5 ConfigurationStageInterface implementations', 'inputs' => 'ConfigurationContext', 'outputs' => '?ConfigurationLayer', 'state_owner' => 'Analysis.Configuration', 'dependency' => 'priority 0,10,15,20,30; sequential merge', 'source' => 'src/Analysis/Configuration/Pipeline/Stage'],
        ['phase' => 'runtime setup', 'participant' => 'ArchitecturePolicyConfiguratorInterface', 'inputs' => 'ConfigurationDocument', 'outputs' => 'configured policy state and warnings', 'state_owner' => 'Analysis.Policy.Architecture', 'dependency' => 'Console configures after its logger is available', 'source' => 'src/Analysis/Policy/Architecture/Contract/ArchitecturePolicyConfiguratorInterface.php'],
        ['phase' => 'discovery', 'participant' => 'FileDiscoveryInterface implementation', 'inputs' => 'AbsolutePath|list<AbsolutePath>', 'outputs' => 'iterable<AbsolutePath,SplFileInfo>', 'state_owner' => 'Analysis.Run', 'dependency' => 'first run phase; generated filter follows', 'source' => 'src/Analysis/Run/Contract/Discovery/FileDiscoveryInterface.php'],
        ['phase' => 'collection', 'participant' => 'CollectionOrchestratorInterface', 'inputs' => 'list<SplFileInfo>, MetricRepositoryInterface, AbsolutePath', 'outputs' => 'CollectionPhaseOutput', 'state_owner' => 'Analysis.Run', 'dependency' => 'after discovery', 'source' => 'src/Analysis/Run/Contract/Collection/CollectionOrchestratorInterface.php'],
        ['phase' => 'per-file measurement', 'participant' => '21 MetricCollectorInterface implementations plus DependencyTraversalParticipantInterface', 'inputs' => 'SplFileInfo, Node[]', 'outputs' => 'MetricBag, typed projections and list<Dependency>', 'state_owner' => 'Measurement and DependencyModel', 'dependency' => 'one AST traversal; reset per file', 'source' => 'src/Analysis/Evidence/Measurement/Contract/MetricCollectorInterface.php'],
        ['phase' => 'per-file derivation', 'participant' => 'TypeCoveragePercentCollector', 'inputs' => 'MetricBag', 'outputs' => 'MetricBag(typeCoveragePct)', 'state_owner' => 'Analysis.Evidence.Design', 'dependency' => 'requires collector id type-coverage', 'source' => 'src/Analysis/Evidence/Design/TypeCoveragePercentCollector.php'],
        ['phase' => 'per-file derivation', 'participant' => 'MaintainabilityIndexCollector', 'inputs' => 'MetricBag', 'outputs' => 'MetricBag(maintainabilityIndex)', 'state_owner' => 'Analysis.Evidence.Maintainability', 'dependency' => 'requires halstead, cyclomatic-complexity, method-statement-count', 'source' => 'src/Analysis/Evidence/Maintainability/MaintainabilityIndexCollector.php'],
        ['phase' => 'dependency graph', 'participant' => 'DependencyGraphBuilder', 'inputs' => 'list<Dependency>, list<LogicalClassPath>', 'outputs' => 'DependencyGraphInterface', 'state_owner' => 'Analysis.Evidence.DependencyModel', 'dependency' => 'consumes raw collection dependencies', 'source' => 'src/Analysis/Evidence/DependencyModel/DependencyGraphBuilder.php'],
        ['phase' => 'architecture preparation', 'participant' => 'LayerPolicyPreparationInterface', 'inputs' => 'DependencyGraphInterface, ClassSet, enabled', 'outputs' => 'leaf-owned prepared ArchitectureConfiguration', 'state_owner' => 'Analysis.Policy.Architecture', 'dependency' => 'Run selects it by rule state; disabled preparation clears state without expansion work', 'source' => 'src/Analysis/Policy/Architecture/Contract/LayerPolicyPreparationInterface.php'],
        ['phase' => 'aggregation', 'participant' => '4 AggregationPhaseInterface implementations', 'inputs' => 'MetricRepositoryInterface, list<MetricDefinition>', 'outputs' => 'repository enrichment and NamespaceTree', 'state_owner' => 'Analysis.Evidence.Measurement', 'dependency' => 'callable -> class -> namespace tree -> project', 'source' => 'src/Analysis/Evidence/Measurement/Aggregation'],
        ['phase' => 'global derivation', 'participant' => 'CouplingCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'CA, CE, CBO, instability', 'state_owner' => 'Analysis.Evidence.Coupling', 'dependency' => 'no global predecessor', 'source' => 'src/Analysis/Evidence/Coupling/CouplingCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'AbstractnessCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'abstractness', 'state_owner' => 'Analysis.Evidence.Coupling', 'dependency' => 'requires aggregated size type counts', 'source' => 'src/Analysis/Evidence/Coupling/AbstractnessCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'ClassRankCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'classRank', 'state_owner' => 'Analysis.Evidence.Coupling', 'dependency' => 'requires CA and CE', 'source' => 'src/Analysis/Evidence/Coupling/ClassRankCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'DistanceCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'distance', 'state_owner' => 'Analysis.Evidence.Coupling', 'dependency' => 'requires instability and abstractness', 'source' => 'src/Analysis/Evidence/Coupling/DistanceCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'DitGlobalCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'DIT', 'state_owner' => 'Analysis.Evidence.Design', 'dependency' => 'no global predecessor; overwrites per-file DIT', 'source' => 'src/Analysis/Evidence/Design/DitGlobalCollector.php'],
        ['phase' => 'global derivation', 'participant' => 'NocCollector', 'inputs' => 'DependencyGraphInterface, MetricRepositoryInterface', 'outputs' => 'NOC', 'state_owner' => 'Analysis.Evidence.Design', 'dependency' => 'no global predecessor', 'source' => 'src/Analysis/Evidence/Design/NocCollector.php'],
        ['phase' => 'global reaggregation', 'participant' => 'MeasurementAggregationService', 'inputs' => 'MetricRepositoryInterface, NamespaceTree', 'outputs' => 'namespace/project aggregates', 'state_owner' => 'Analysis.Evidence.Measurement', 'dependency' => 'after all global collectors', 'source' => 'src/Analysis/Evidence/Measurement/Aggregation/MeasurementAggregationService.php'],
        ['phase' => 'computed derivation', 'participant' => 'Contract\\Evaluation\\ComputedMetricEvaluator', 'inputs' => 'MetricRepositoryInterface, files analyzed; one catalog snapshot', 'outputs' => 'configured computed metrics', 'state_owner' => 'Analysis.Evidence.ComputedMetrics', 'dependency' => 'definition DAG; instance-owned catalog; skipped without files/definitions', 'source' => 'src/Analysis/Evidence/ComputedMetrics/Contract/Evaluation/ComputedMetricEvaluator.php'],
        ['phase' => 'graph inspection', 'participant' => 'CircularDependencyPreparationInterface', 'inputs' => 'DependencyGraphInterface, enabled', 'outputs' => 'leaf-owned list<Cycle>', 'state_owner' => 'Analysis.Evidence.CircularDependency', 'dependency' => 'Run invokes it after graph construction; disabled preparation clears state without SCC work', 'source' => 'src/Analysis/Evidence/CircularDependency/Contract/CircularDependencyPreparationInterface.php'],
        ['phase' => 'file-set inspection', 'participant' => 'FileSetInspectionParticipantInterface implementations', 'inputs' => 'list<SplFileInfo>', 'outputs' => 'capability-owned run state', 'state_owner' => 'owning evidence capabilities', 'dependency' => 'producer-rule selection gated; lexical participant order', 'source' => 'src/Analysis/Run/Contract/FileSetInspectionParticipantInterface.php'],
        ['phase' => 'rule execution', 'participant' => 'Analysis\\Finding\\RuleExecution + 41 RuleInterface implementations', 'inputs' => 'AnalysisContext', 'outputs' => 'list<Violation> and last RuleExclusionStats', 'state_owner' => 'Analysis.Finding and feature rules', 'dependency' => 'producer selection then per-rule exclusions and channel selection', 'source' => 'src/Analysis/Finding/RuleExecution.php'],
        ['phase' => 'finding projection', 'participant' => 'FindingProjector', 'inputs' => 'list<Violation>, suppressions, FindingProjectionOptions', 'outputs' => 'FindingProjectionResult', 'state_owner' => 'Reporting', 'dependency' => 'annotation suppression -> path -> namespace -> baseline -> annotation rejoin -> git', 'source' => 'src/Reporting/FindingProjection/FindingProjector.php'],
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
$compositionProbeArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--composition-probe='),
));
$sourceOverridesArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--source-overrides='),
));
if (count($manifestArguments) > 1) {
    fail('only one --manifest path may be provided');
}
if (count($outputDirectoryArguments) > 1 || count($qmxOutputArguments) > 1 || count($qmxSourceArguments) > 1 || count($documentationProbeArguments) > 1 || count($compositionProbeArguments) > 1 || count($sourceOverridesArguments) > 1) {
    fail('only one output directory, qmx source/output, documentation probe, composition probe, and source overrides path may be provided');
}
$unknownArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => $argument !== '--check'
        && !str_starts_with($argument, '--manifest=')
        && !str_starts_with($argument, '--output-directory=')
        && !str_starts_with($argument, '--qmx-output=')
        && !str_starts_with($argument, '--qmx-source=')
        && !str_starts_with($argument, '--documentation-probe=')
        && !str_starts_with($argument, '--composition-probe=')
        && !str_starts_with($argument, '--source-overrides='),
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
$compositionProbe = $compositionProbeArguments === []
    ? null
    : substr($compositionProbeArguments[0], strlen('--composition-probe='));
$sourceOverridesPath = $sourceOverridesArguments === []
    ? null
    : substr($sourceOverridesArguments[0], strlen('--source-overrides='));

$compositionProbeData = null;
if ($compositionProbe !== null) {
    $contents = file_get_contents($compositionProbe);
    if ($contents === false) {
        fail('cannot read composition probe ' . $compositionProbe);
    }
    $compositionProbeData = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($compositionProbeData)) {
        fail('composition probe must be an object');
    }
}
$sourceOverrides = [];
if ($sourceOverridesPath !== null) {
    $contents = file_get_contents($sourceOverridesPath);
    if ($contents === false) {
        fail('cannot read source overrides ' . $sourceOverridesPath);
    }
    $sourceOverridesData = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($sourceOverridesData)) {
        fail('source overrides must be an object');
    }
    $sourceOverrides = $sourceOverridesData;
}
foreach ($compositionProbeData['source_overrides'] ?? [] as $relativePath => $replacementPath) {
    if (!is_string($relativePath) || !is_string($replacementPath)) {
        fail('composition probe source_overrides must map repository-relative paths to source paths');
    }
    if (!is_file($root . '/' . $relativePath)) {
        fail('composition probe source override names an unknown production path ' . $relativePath);
    }
    if (!is_file($replacementPath)) {
        fail('composition probe source override replacement does not exist for ' . $relativePath);
    }
    $sourceOverrides[$relativePath] = $replacementPath;
}
foreach ($sourceOverrides as $relativePath => $replacementPath) {
    if (!is_string($relativePath) || !is_string($replacementPath)) {
        fail('source overrides must map repository-relative paths to source paths');
    }
    if (!is_file($root . '/' . $relativePath)) {
        fail('source override names an unknown production path ' . $relativePath);
    }
    if (!is_file($replacementPath)) {
        fail('source override replacement does not exist for ' . $relativePath);
    }
}

$manifest = loadAndValidateManifest($manifestPath, $schemaPath);
validateMaterializedP5Boundary($manifest);
if ($documentationProbe !== null) {
    fwrite(STDOUT, implode("\t", documentationDisposition($documentationProbe)) . "\n");
    exit(0);
}
$rows = declarations($root, $sourceOverrides);
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

foreach ($rows as $row) {
    $classStringTargets = array_merge(
        array_column($row['class_string_targets'], 'target_fqcn'),
        $row['class_string_metadata_targets'],
    );
    foreach ($classStringTargets as $target) {
        if (!isset($byName[$target])) {
            continue;
        }
        if ($row['proposed_owner'] !== $byName[$target]['proposed_owner']
            && $byName[$target]['proposed_status'] === 'internal'
        ) {
            fail("cross-owner class-string target {$row['fqcn']} -> {$target} is internal");
        }
    }
}

if ($compositionProbe !== null) {
    $probe = $compositionProbeData;
    foreach ($probe['rows'] ?? [] as $fqcn => $changes) {
        if (!isset($byName[$fqcn]) || !is_array($changes)) {
            fail('composition probe names an unknown row ' . $fqcn);
        }
        $byName[$fqcn] = array_replace($byName[$fqcn], $changes);
    }
    foreach ($probe['remove_pairs'] ?? [] as $pair) {
        unset($observedPairs[$pair[0] . "\0" . $pair[1]]);
    }
    foreach ($probe['add_pairs'] ?? [] as $pair) {
        $observedPairs[$pair[0] . "\0" . $pair[1]] = true;
    }
    $consumerUse = [];
    foreach ($manifest['declarations'] as $target => $entry) {
        foreach ($entry['consumers'] as $index => $consumer) {
            if (($consumer['relation'] ?? 'import') === 'import') {
                $consumerUse[$target . "\0" . $index] = true;
            }
        }
    }
    validateContractCompositions($manifest, $byName, $observedPairs, $consumerUse);
    fwrite(STDOUT, "contract_composition probe passed\n");
    exit(0);
}

$actualBindingOperations = classifyCompositionBindingOperations($root, $manifest, $byName, $sourceOverrides);
$authorization = validateAuthorizations($manifest, $byName, $observedPairs, $rows, $actualBindingOperations);
$enforcement = buildEnforcementProjection($manifest, $byName, $observedPairs);
assertDag($enforcement['allow'], 'generated qmx allow graph');
validateSeamNecessity($manifest, $byName, $observedPairs);
$consumerCount = 0;
$temporaryConsumerCount = 0;
foreach ($declarations as $entry) {
    $consumerCount += count($entry['consumers']);
    $temporaryConsumerCount += count(array_filter(
        $entry['consumers'],
        static fn(array $consumer): bool => $consumer['closes_in'] !== null,
    ));
}

$ownershipRows = [];
$classStringTargetRows = [];
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
    foreach ($row['class_string_targets'] as $target) {
        $classStringTargetRows[] = [
            $target['source_fqcn'],
            $target['member_kind'],
            $target['member_name'],
            $target['target_fqcn'],
        ];
    }
}
usort($classStringTargetRows, static fn(array $left, array $right): int => $left <=> $right);

$compositionBindingRows = [];
foreach ($authorization['bindings'] as $pair => $binding) {
    [$source, $target] = explode("\0", $pair, 2);
    $sourceRow = $byName[$source];
    $targetRow = $byName[$target];
    $declaredOperations = implode(',', $binding['consumer']['operations']);
    $observedOperations = implode(',', $binding['actual_operations']);
    $compositionBindingRows[] = [
        $sourceRow['path'],
        $source,
        $sourceRow['proposed_owner'],
        $targetRow['path'],
        $target,
        $targetRow['proposed_owner'],
        $declaredOperations,
        $observedOperations,
        'used',
        semanticLayerName($sourceRow) . ' -> ' . semanticLayerName($targetRow),
    ];
}
usort($compositionBindingRows, static fn(array $left, array $right): int => $left <=> $right);

$publicImportRows = [];
foreach ($crossOwnerImports as [$source, $sourceOwner, $target, $targetOwner, $visibility]) {
    if ($visibility !== 'contract') {
        continue;
    }
    $publicImportRows[] = [$target, $targetOwner, $source, $sourceOwner, $byName[$source]['path'], 'ast-name'];
}
usort($publicImportRows, static fn(array $left, array $right): int => $left <=> $right);
$fanIn = [];
foreach ($publicImportRows as [$target, $targetOwner, $source, $sourceOwner]) {
    $fanIn[$target]['owner'] = $targetOwner;
    $fanIn[$target]['sources'][$source] = true;
    $fanIn[$target]['owners'][$sourceOwner] = true;
}
$fanInRows = [];
foreach ($fanIn as $target => $data) {
    $fanInRows[] = [$target, $data['owner'], (string) count($data['sources']), (string) count($data['owners'])];
}
usort($fanInRows, static fn(array $left, array $right): int => $left <=> $right);

$productionToTestRows = [];
foreach ($rows as $row) {
    foreach ($row['dependencies'] as $dependency) {
        if (str_starts_with($dependency, 'Qualimetrix\\Tests\\')) {
            $productionToTestRows[] = [$row['path'], $row['fqcn'], $dependency, 'ast-name'];
        }
    }
}
if ($productionToTestRows !== []) {
    fail('production source imports a test namespace');
}

$extensionDefinitions = [
    'rule' => ['Qualimetrix\\Analysis\\Finding\\Rule\\RuleInterface', 'qmx.rule', 'capability configurator -> rule compiler passes'],
    'regular_collector' => ['Qualimetrix\\Analysis\\Evidence\\Measurement\\Contract\\MetricCollectorInterface', 'qmx.collector', 'capability configurator -> CollectorCompilerPass -> CompositeCollector'],
    'derived_collector' => ['Qualimetrix\\Analysis\\Evidence\\Measurement\\Contract\\DerivedCollectorInterface', 'qmx.derived_collector', 'capability configurator -> CollectorCompilerPass -> CompositeCollector'],
    'global_collector' => ['Qualimetrix\\Analysis\\Evidence\\Measurement\\Contract\\GlobalContextCollectorInterface', 'qmx.global_collector', 'capability configurator -> GlobalCollectorCompilerPass -> GlobalCollectorRunner'],
    'formatter' => ['Qualimetrix\\Reporting\\Formatter\\FormatterInterface', 'qmx.formatter', 'OutputConfigurator -> FormatterCompilerPass -> FormatterRegistry'],
    'configuration_stage' => ['Qualimetrix\\Analysis\\Configuration\\Pipeline\\ConfigurationStageInterface', 'qmx.configuration_stage', 'ConfigurationConfigurator -> ConfigurationStageCompilerPass -> ConfigurationPipeline'],
];
$extensionRows = [];
$capabilityConfigurators = [
    'Analysis.Evidence.CodeSmell' => 'CodeSmellConfigurator',
    'Analysis.Evidence.Cohesion' => 'CohesionConfigurator',
    'Analysis.Evidence.Complexity' => 'ComplexityConfigurator',
    'Analysis.Evidence.Coupling' => 'CouplingConfigurator',
    'Analysis.Evidence.Design' => 'DesignConfigurator',
    'Analysis.Evidence.Maintainability' => 'MaintainabilityConfigurator',
    'Analysis.Evidence.Security' => 'SecurityConfigurator',
    'Analysis.Evidence.Size' => 'SizeConfigurator',
];
$otherRuleConfigurators = [
    'Analysis.Policy.Architecture' => 'ArchitectureConfigurator',
    'Analysis.Evidence.CircularDependency' => 'CircularDependencyConfigurator',
    'Analysis.Evidence.ComputedMetrics' => 'ComputedMetricsConfigurator',
    'Analysis.Evidence.Duplication' => 'DuplicationConfigurator',
];
foreach ($extensionDefinitions as $family => [$target, $tag, $registration]) {
    $extensionCount = 0;
    foreach ($rows as $row) {
        if ($row['kind'] !== 'class' || $row['abstract'] || !isA($row['fqcn'], $target, $byName)) {
            continue;
        }
        $extensionCount++;
        $configurator = $capabilityConfigurators[$row['proposed_owner']]
            ?? ($family === 'rule' ? ($otherRuleConfigurators[$row['proposed_owner']] ?? null) : null);
        $registrationPath = $configurator === null
            ? $registration
            : $configurator . substr($registration, strlen('capability configurator'));
        $extensionRows[] = [$family, $row['fqcn'], $row['path'], $tag, $registrationPath];
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
ksort($reportingCounts, SORT_STRING);

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
foreach ($phaseRows as $phase) {
    if (!is_file($root . '/' . $phase['source']) && !is_dir($root . '/' . $phase['source'])) {
        fail('phase participant source does not exist: ' . $phase['source']);
    }
}

$outputs = [
    'production-ownership.tsv' => tsv(['path', 'fqcn', 'kind', 'proposed_owner', 'proposed_status', 'closure_package', 'consumers'], $ownershipRows),
    'production-class-string-targets.tsv' => tsv(['source_fqcn', 'member_kind', 'member_name', 'target_fqcn'], $classStringTargetRows),
    'production-composition-bindings.tsv' => tsv(['source_path', 'source_fqcn', 'source_owner', 'target_path', 'target_fqcn', 'target_owner', 'declared_operations', 'observed_operations', 'behavioral_verdict', 'qmx_projection'], $compositionBindingRows),
    'production-cross-owner-imports.tsv' => tsv(['consumer', 'consumer_owner', 'dependency', 'dependency_owner', 'dependency_visibility', 'closure_package'], $crossOwnerImports),
    'production-public-imports.tsv' => tsv(['target_contract_fqcn', 'target_owner', 'consumer_fqcn', 'consumer_owner', 'source_path', 'import_kind'], $publicImportRows),
    'production-module-fan-in.tsv' => tsv(['target_contract_fqcn', 'target_owner', 'distinct_consumer_fqcns', 'distinct_consumer_owners'], $fanInRows),
    'production-to-test-imports.tsv' => tsv(['source_path', 'source_fqcn', 'imported_test_fqcn', 'import_kind'], $productionToTestRows),
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
            ['permanent_composition_bindings', (string) count($compositionBindingRows)],
            ['exact_dependency_edges', (string) count($observedPairs)],
            ['cross_owner_imports', (string) count($crossOwnerImports)],
            ['semantic_owner_layers', (string) $enforcement['semantic_owner_layer_count']],
            ['singleton_seams', (string) count($manifest['enforcement_seams'])],
            ['exact_composition_bindings', (string) $authorization['internal_grant_count']],
            ['coarse_composition_binding_edges', (string) $authorization['coarse_internal_grant_edge_count']],
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
    $compositionConsumers = array_values(array_filter(
        $consumers,
        static fn(array $consumer): bool => ($consumer['relation'] ?? 'import') === 'composition_binding',
    ));
    if ($entry['visibility'] === 'internal' && count($compositionConsumers) !== count($consumers)) {
        fail("internal declaration {$fqcn} can publish only composition bindings");
    }
    if ($entry['visibility'] === 'contract' && $consumers === []) {
        fail("contract declaration {$fqcn} must publish at least one used consumer");
    }
    $seen = [];
    foreach ($consumers as $index => $consumer) {
        if (!in_array($consumer['owner'], $owners, true)) {
            fail("consumer {$fqcn}#{$index} names unknown owner {$consumer['owner']}");
        }
        if (($consumer['relation'] ?? 'import') === 'contract_composition') {
            $key = $consumer['owner'] . "\0contract_composition\0" . $consumer['carrier_fqcn'] . "\0" . $consumer['boundary_fqcn'];
            if (isset($seen[$key])) {
                fail("duplicate contract_composition relation on {$fqcn} for {$consumer['owner']}");
            }
            $seen[$key] = true;
            continue;
        }
        if (($consumer['relation'] ?? 'import') === 'composition_binding') {
            if ($entry['visibility'] !== 'internal'
                || $consumer['owner'] !== 'Infrastructure.DependencyInjection'
                || !is_string($consumer['source_fqcn'])
                || $consumer['closes_in'] !== null
                || $consumer['operations'] === []
            ) {
                fail("composition_binding {$fqcn}#{$index} must permanently authorize an exact DI source to an internal target");
            }
            $source = $consumer['source_fqcn'];
            if (!isset($declarations[$source]) || $declarations[$source]['owner'] !== 'Infrastructure.DependencyInjection') {
                fail("composition_binding {$fqcn}#{$index} names an invalid DI source");
            }
            $key = 'composition_binding' . "\0" . $source;
            if (isset($seen[$key])) {
                fail("duplicate composition_binding {$source} -> {$fqcn}");
            }
            $seen[$key] = true;
            continue;
        }
        if (($consumer['relation'] ?? 'import') === 'contract_surface') {
            if (!is_string($consumer['source_fqcn']) || $consumer['closes_in'] !== null) {
                fail("contract_surface {$fqcn}#{$index} must name a permanent exact source");
            }
            $source = $consumer['source_fqcn'];
            if (!isset($declarations[$source])) {
                fail("contract_surface {$fqcn}#{$index} names unknown source {$source}");
            }
            if ($declarations[$source]['owner'] !== $consumer['owner']) {
                fail("contract_surface {$fqcn}#{$index} source owner does not match {$consumer['owner']}");
            }
            $key = $consumer['owner'] . "\0contract_surface\0" . $source . "\0" . $consumer['carrier_fqcn'];
            if (isset($seen[$key])) {
                fail("duplicate contract_surface relation on {$fqcn} for {$consumer['owner']}");
            }
            $seen[$key] = true;
            continue;
        }
        $permanentOwnerWide = $consumer['source_fqcn'] === null && $consumer['closes_in'] === null;
        $permanentExact = is_string($consumer['source_fqcn']) && $consumer['closes_in'] === null;
        $temporary = is_string($consumer['source_fqcn']) && is_string($consumer['closes_in']);
        if (!$permanentOwnerWide && !$permanentExact && !$temporary) {
            fail("consumer {$fqcn}#{$index} must be permanent owner-wide, permanent exact-source, or temporary exact-source");
        }
        if ($permanentExact || $temporary) {
            $source = $consumer['source_fqcn'];
            if (!isset($declarations[$source])) {
                fail("exact consumer {$fqcn}#{$index} names unknown source {$source}");
            }
            if ($declarations[$source]['owner'] !== $consumer['owner']) {
                fail("exact consumer {$fqcn}#{$index} source owner does not match {$consumer['owner']}");
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
 * Validates the materialized P5 boundary directly from current authority.
 *
 * @param array<string, mixed> $manifest
 */
function validateMaterializedP5Boundary(array $manifest): void
{
    $declarations = $manifest['declarations'];
    $requiredReporting = [
        'Qualimetrix\\Reporting\\Health\\HealthHintProjector',
        'Qualimetrix\\Reporting\\Health\\HealthScoreResolver',
        'Qualimetrix\\Reporting\\Health\\SummaryEnricher',
    ];
    foreach ($requiredReporting as $fqcn) {
        if (!isset($declarations[$fqcn])) {
            fail("Materialized P5 Reporting declaration is missing: {$fqcn}");
        }
    }

    foreach ([
        'Qualimetrix\\Core\\ComputedMetric\\ComputedMetricDefinitionHolder',
        'Qualimetrix\\Analysis\\Run\\Enrichment\\TransitionalMetricEnricher',
        'Qualimetrix\\Analysis\\Run\\Enrichment\\TransitionalEnrichmentResult',
        'Qualimetrix\\Reporting\\Health\\MetricHintProvider',
        'Qualimetrix\\Reporting\\Health\\HealthReasonBuilder',
    ] as $obsoleteFqcn) {
        if (isset($declarations[$obsoleteFqcn])) {
            fail("Obsolete P5 declaration remains materialized: {$obsoleteFqcn}");
        }
    }
}
/**
 * P4 is intentionally recorded as a finite target projection while the
 * authoritative declaration map still describes the physical P3 tree. This
 * keeps target ownership reviewable without pretending that unpublished paths
 * already exist in the source tree.
 *
 * @param array<string, mixed> $manifest
 */
function validateP4Target(array $manifest): void
{
    $target = $manifest['p4_target'];
    /** @var array<string, array<string, mixed>> $current */
    $current = $target['current_declaration_targets'];
    $additions = $target['additions'];
    $declarations = $manifest['declarations'];

    $currentP4 = array_filter(
        $declarations,
        static fn(array $declaration): bool => $declaration['closure_package'] === 'P4',
    );
    if (array_keys($current) !== array_keys($currentP4)) {
        failSetDifference('P4 current declaration target map does not match the authoritative P4 declaration set', array_keys($currentP4), array_keys($current));
    }

    $currentOwners = array_count_values(array_column($currentP4, 'owner'));
    if (($currentOwners['Analysis.Policy.Architecture'] ?? 0) !== 52
        || ($currentOwners['Analysis.Evidence.CircularDependency'] ?? 0) !== 6
        || count($currentOwners) !== 2
    ) {
        fail('P4 current declaration map must contain exactly 52 Architecture and six CircularDependency declarations');
    }

    $deletions = array_filter($current, static fn(array $entry): bool => $entry['disposition'] === 'delete');
    if (array_keys($deletions) !== [
        'Qualimetrix\\Architecture\\Processing\\ArchitectureLifecycleHook',
        'Qualimetrix\\Architecture\\Processing\\ArchitectureProcessorInterface',
        'Qualimetrix\\Core\\Dependency\\CycleInterface',
    ]) {
        fail('P4 target must delete exactly the two Architecture lifecycle declarations and CycleInterface from its current P4 declaration set');
    }

    /** @var array<string, list<string>> $architectureZones */
    $architectureZones = $target['architecture_zone_dag'];
    $expectedZones = [
        'Contract',
        'Configuration/Allow',
        'Layer',
        'Configuration',
        'Layer/Expansion',
        'ArchitecturePolicy',
        'LayerViolation',
    ];
    if (array_keys($architectureZones) !== $expectedZones) {
        failSetDifference('P4 Architecture internal zone DAG does not declare the reviewed exact zones', $expectedZones, array_keys($architectureZones));
    }
    $expectedZoneEdges = [
        'Contract' => ['Core.Neutral', 'Core.Path', 'Core.Symbol', 'Analysis.Evidence.DependencyModel'],
        'Configuration/Allow' => ['Contract'],
        'Layer' => ['Contract', 'Configuration/Allow'],
        'Configuration' => ['Contract', 'Configuration/Allow', 'Layer'],
        'Layer/Expansion' => ['Contract', 'Configuration', 'Configuration/Allow', 'Layer'],
        'ArchitecturePolicy' => ['Contract', 'Configuration', 'Layer', 'Layer/Expansion'],
        'LayerViolation' => ['Contract', 'ArchitecturePolicy', 'Configuration', 'Layer'],
    ];
    if ($architectureZones !== $expectedZoneEdges) {
        fail('P4 Architecture internal zone DAG differs from the reviewed fail-closed allow set');
    }

    $allTargets = array_merge($current, $additions);
    $targetFqcns = [];
    $targetPaths = [];
    $architectureTargetCount = 0;
    $circularTargetCount = 0;
    foreach ($allTargets as $source => $entry) {
        if ($entry['disposition'] === 'delete') {
            continue;
        }
        if (isset($targetFqcns[$entry['fqcn']]) || isset($targetPaths[$entry['path']])) {
            fail('P4 target declarations must have unique FQCNs and paths');
        }
        $targetFqcns[$entry['fqcn']] = $source;
        $targetPaths[$entry['path']] = $source;
        if ($entry['owner'] === 'Analysis.Policy.Architecture') {
            ++$architectureTargetCount;
            if (!array_key_exists($entry['zone'], $architectureZones)) {
                fail('P4 Architecture target declaration ' . $entry['fqcn'] . ' names an unknown internal zone ' . $entry['zone']);
            }
        }
        if ($entry['owner'] === 'Analysis.Evidence.CircularDependency') {
            ++$circularTargetCount;
            if (!in_array($entry['zone'], ['Contract', 'Internal'], true)) {
                fail('P4 CircularDependency target declaration ' . $entry['fqcn'] . ' names an unknown zone ' . $entry['zone']);
            }
        }
    }
    if ($architectureTargetCount !== 57 || $circularTargetCount !== 7) {
        fail('P4 target must materialize 57 Architecture and seven CircularDependency declarations after the reviewed additions and deletions');
    }
    if (count($additions) !== 12) {
        fail('P4 target must declare exactly twelve explicit additions');
    }

    $circularContract = 'Qualimetrix\\Analysis\\Evidence\\CircularDependency\\Contract\\CircularDependencyPreparationInterface';
    if (($additions[$circularContract]['visibility'] ?? null) !== 'contract'
        || $target['circular_dependency_contract_consumers'] !== ['Analysis.Run']
    ) {
        fail('P4 CircularDependency publishes only its preparation contract to the named Run consumer');
    }

    $topology = $target['test_topology'];
    if ($topology['p6_exclusions'] !== [
        'tests/Architecture/Integration/InlineSuppressionLayerViolationIntegrationTest.php',
        'tests/Architecture/Fixtures/IgnoreSample/',
    ]) {
        fail('P4 test topology must keep the exact P6 InlineSuppression and IgnoreSample exclusions');
    }
    $closures = $target['closures'];
    if ($closures['seams'] !== [
        'seam-config-load-exception',
        'seam-deferred-warning',
        'seam-architecture-lifecycle-hook',
    ]) {
        fail('P4 target must close exactly the reviewed three seams');
    }
    $actualSeams = [];
    foreach ($manifest['enforcement_seams'] as $entry) {
        if ($entry['closes_in'] === 'P4') {
            $actualSeams[] = $entry['layer'];
        }
    }
    sort($actualSeams, SORT_STRING);
    $expectedSeams = $closures['seams'];
    sort($expectedSeams, SORT_STRING);
    if ($actualSeams !== $expectedSeams) {
        fail('P4 target seam closure does not match current manifest seams');
    }
    $expectedGrant = $closures['temporary_internal_grants'];
    $actualGrants = array_values(array_filter(
        $manifest['temporary_internal_grants'],
        static fn(array $grant): bool => $grant['closes_in'] === 'P4',
    ));
    if (count($actualGrants) !== 1
        || ($expectedGrant[0]['source_fqcn'] ?? null) !== $actualGrants[0]['source_fqcn']
        || ($expectedGrant[0]['target_fqcn'] ?? null) !== $actualGrants[0]['target_fqcn']
    ) {
        fail('P4 target must close exactly the ArchitectureConfigurator -> ArchitectureProcessor temporary grant');
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
 * Classifies the closed Symfony composition vocabulary from executable AST
 * nodes. Imports, strings outside a binding expression, guards and comments
 * deliberately do not contribute evidence.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>> $byName
 * @param array<string, string> $sourceOverrides
 *
 * @return array<string, list<string>> exact source\0target => sorted operations
 */
function classifyCompositionBindingOperations(string $root, array $manifest, array $byName, array $sourceOverrides): array
{
    $targetsBySource = [];
    foreach ($manifest['declarations'] as $target => $entry) {
        foreach ($entry['consumers'] as $consumer) {
            if (($consumer['relation'] ?? 'import') !== 'composition_binding') {
                continue;
            }
            $targetsBySource[$consumer['source_fqcn']][$target] = true;
        }
    }

    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $finder = new NodeFinder();
    $operations = [];
    foreach ($targetsBySource as $source => $targets) {
        $path = $byName[$source]['path'] ?? null;
        if (!is_string($path)) {
            fail("composition binding source {$source} has no production path");
        }
        $sourcePath = $sourceOverrides[$path] ?? $root . '/' . $path;
        $code = file_get_contents($sourcePath);
        if ($code === false) {
            fail("cannot read composition binding source {$sourcePath}");
        }
        $ast = $parser->parse($code);
        if ($ast === null) {
            fail("composition binding parser returned no AST for {$path}");
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $containerVariables = containerBuilderVariables($ast);
        $definitionVariables = [];
        $serviceVariables = [];
        foreach ($finder->findInstanceOf($ast, Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)) {
                $serviceTarget = expressionFqcn($assign->expr);
                if ($serviceTarget !== null && isset($targets[$serviceTarget])) {
                    $serviceVariables[$assign->var->name] = $serviceTarget;
                }
            }
            if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)
                || !$assign->expr instanceof Node\Expr\MethodCall
                || methodName($assign->expr) !== 'getdefinition'
                || !isContainerBuilderCall($assign->expr, $containerVariables)
            ) {
                continue;
            }
            $target = expressionFqcn($assign->expr->args[0]->value ?? null);
            if ($target !== null && isset($targets[$target])) {
                $definitionVariables[$assign->var->name] = $target;
            } elseif ($assign->expr->args !== []) {
                // A compiler pass may select its Definition from a dynamic tagged
                // service id. It is still a container definition receiver, but it
                // is not evidence of a definition mutation for an exact target.
                $definitionVariables[$assign->var->name] = '';
            }
        }

        $conditionalTargets = [];
        foreach ($finder->findInstanceOf($ast, Node\Stmt\If_::class) as $if) {
            foreach ($targets as $target => $_true) {
                if (ifContainsConditionalReference($if, $target, $serviceVariables)) {
                    $conditionalTargets[$target] = true;
                }
            }
        }
        foreach (conditionalServiceVariables($ast, $targets) as $variable => $target) {
            $serviceVariables[$variable] = $target;
            $conditionalTargets[$target] = true;
        }

        foreach ($finder->findInstanceOf($ast, Node\Expr\MethodCall::class) as $call) {
            $method = methodName($call);
            if ($method === null) {
                continue;
            }
            if ($method === 'register' && isContainerBuilderCall($call, $containerVariables)) {
                $target = expressionFqcn($call->args[0]->value ?? null);
                if ($target !== null && isset($targets[$target])) {
                    $operations[$source . "\0" . $target]['service_registration'] = true;
                }
            }
            if ($method === 'setalias' && isContainerBuilderCall($call, $containerVariables)) {
                foreach ($call->args as $argument) {
                    if (!$argument instanceof Node\Arg) {
                        continue;
                    }
                    $target = expressionFqcn($argument->value);
                    if ($target !== null && isset($targets[$target])) {
                        $operations[$source . "\0" . $target]['service_alias'] = true;
                    }
                }
            }
            if (in_array($method, ['setargument', 'addargument', 'replaceargument', 'setarguments'], true)) {
                if ($call->var instanceof Node\Expr\Variable && is_string($call->var->name)
                    && isset($definitionVariables[$call->var->name])
                ) {
                    $target = $definitionVariables[$call->var->name];
                    if ($target !== '') {
                        $operations[$source . "\0" . $target]['definition_argument_mutation'] = true;
                    }
                }
                $chainedTarget = definitionTargetForCall($call, $containerVariables);
                if ($chainedTarget !== null && isset($targets[$chainedTarget])) {
                    $operations[$source . "\0" . $chainedTarget]['definition_argument_mutation'] = true;
                }
            }
            if (isContainerBindingCall($call, $containerVariables, $definitionVariables)) {
                foreach (referenceTargetsInBinding($call, $serviceVariables) as $target) {
                    if (!isset($targets[$target])) {
                        continue;
                    }
                    $operation = isset($conditionalTargets[$target])
                        ? 'conditional_service_reference'
                        : 'service_reference';
                    $operations[$source . "\0" . $target][$operation] = true;
                }
            }
        }
        foreach ($targets as $target => $_true) {
            if (hasConditionalHelperReference($ast, $target)) {
                $operations[$source . "\0" . $target]['conditional_service_reference'] = true;
            }
        }
    }

    $result = [];
    foreach ($operations as $pair => $set) {
        $result[$pair] = array_keys($set);
        sort($result[$pair], SORT_STRING);
    }

    return $result;
}

function methodName(Node\Expr\MethodCall $call): ?string
{
    return $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : null;
}

/** @param array<string, true> $containerVariables */
function isContainerBuilderCall(Node\Expr\MethodCall $call, array $containerVariables): bool
{
    return $call->var instanceof Node\Expr\Variable
        && is_string($call->var->name)
        && isset($containerVariables[$call->var->name]);
}

/**
 * @param array<Node> $ast
 *
 * @return array<string, true>
 */
function containerBuilderVariables(array $ast): array
{
    $finder = new NodeFinder();
    $variables = [];
    foreach ($finder->findInstanceOf($ast, Node\Param::class) as $parameter) {
        if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)
            || !$parameter->type instanceof Node\Name
        ) {
            continue;
        }
        $type = ($parameter->type->getAttribute('resolvedName') ?? $parameter->type)->toString();
        if ($type === 'Symfony\\Component\\DependencyInjection\\ContainerBuilder') {
            $variables[$parameter->var->name] = true;
        }
    }

    return $variables;
}

/**
 * @param array<string, true> $containerVariables
 * @param array<string, string> $definitionVariables
 */
function isContainerBindingCall(Node\Expr\MethodCall $call, array $containerVariables, array $definitionVariables): bool
{
    $receiver = $call->var;
    while ($receiver instanceof Node\Expr\MethodCall) {
        $receiver = $receiver->var;
    }
    if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name)) {
        return isset($containerVariables[$receiver->name]) || isset($definitionVariables[$receiver->name]);
    }

    return false;
}

/** @param array<string, true> $containerVariables */
function definitionTargetForCall(Node\Expr\MethodCall $call, array $containerVariables): ?string
{
    if (!$call->var instanceof Node\Expr\MethodCall
        || methodName($call->var) !== 'getdefinition'
        || !isContainerBuilderCall($call->var, $containerVariables)
    ) {
        return null;
    }

    return expressionFqcn($call->var->args[0]->value ?? null);
}

/**
 * Resolves only a closed DI idiom: a local service-id variable receives a
 * private helper result that returns one exact class behind has()/hasDefinition().
 *
 * @param array<Node> $ast
 * @param array<string, true> $targets
 *
 * @return array<string, string>
 */
function conditionalServiceVariables(array $ast, array $targets): array
{
    $finder = new NodeFinder();
    $methods = [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        $methods[strtolower($method->name->toString())] = $method;
    }
    $resolved = [];
    foreach ($finder->findInstanceOf($ast, Node\Expr\Assign::class) as $assign) {
        if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)
            || !$assign->expr instanceof Node\Expr\MethodCall
            || !$assign->expr->var instanceof Node\Expr\Variable || $assign->expr->var->name !== 'this'
        ) {
            continue;
        }
        $helper = methodName($assign->expr);
        if ($helper === null || !isset($methods[$helper])) {
            continue;
        }
        foreach ($targets as $target => $_true) {
            $hasGuard = false;
            foreach ($finder->findInstanceOf($methods[$helper], Node\Expr\MethodCall::class) as $guard) {
                if (in_array(methodName($guard), ['has', 'hasdefinition'], true)
                    && expressionFqcn($guard->args[0]->value ?? null) === $target
                ) {
                    $hasGuard = true;
                    break;
                }
            }
            $hasReturn = false;
            foreach ($finder->findInstanceOf($methods[$helper], Node\Stmt\Return_::class) as $return) {
                if (expressionFqcn($return->expr) === $target) {
                    $hasReturn = true;
                    break;
                }
            }
            if ($hasGuard && $hasReturn) {
                $resolved[$assign->var->name] = $target;
            }
        }
    }

    return $resolved;
}

/** @param array<Node> $ast */
function hasConditionalHelperReference(array $ast, string $target): bool
{
    $finder = new NodeFinder();
    $helpers = [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        $hasGuard = false;
        foreach ($finder->findInstanceOf($method, Node\Expr\MethodCall::class) as $call) {
            if (in_array(methodName($call), ['has', 'hasdefinition'], true)
                && expressionFqcn($call->args[0]->value ?? null) === $target
            ) {
                $hasGuard = true;
                break;
            }
        }
        if (!$hasGuard) {
            continue;
        }
        foreach ($finder->findInstanceOf($method, Node\Stmt\Return_::class) as $return) {
            if (expressionFqcn($return->expr) === $target) {
                $helpers[strtolower($method->name->toString())] = true;
            }
        }
    }
    if ($helpers === []) {
        return false;
    }
    $serviceVariables = [];
    foreach ($finder->findInstanceOf($ast, Node\Expr\Assign::class) as $assign) {
        if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)
            && $assign->expr instanceof Node\Expr\MethodCall
            && $assign->expr->var instanceof Node\Expr\Variable && $assign->expr->var->name === 'this'
            && isset($helpers[methodName($assign->expr) ?? ''])
        ) {
            $serviceVariables[$assign->var->name] = true;
        }
    }
    foreach ($finder->findInstanceOf($ast, Node\Expr\New_::class) as $new) {
        if (!$new->class instanceof Node\Name
            || (($new->class->getAttribute('resolvedName') ?? $new->class)->toString() !== 'Symfony\Component\DependencyInjection\Reference')
        ) {
            continue;
        }
        $argument = $new->args[0]->value ?? null;
        if ($argument instanceof Node\Expr\Variable && is_string($argument->name) && isset($serviceVariables[$argument->name])) {
            return true;
        }
    }

    return false;
}

function expressionFqcn(?Node\Expr $expression): ?string
{
    if ($expression instanceof Node\Expr\ClassConstFetch
        && $expression->name instanceof Node\Identifier
        && strtolower($expression->name->toString()) === 'class'
        && $expression->class instanceof Node\Name
    ) {
        return ($expression->class->getAttribute('resolvedName') ?? $expression->class)->toString();
    }
    if ($expression instanceof Node\Scalar\String_) {
        return str_starts_with($expression->value, 'Qualimetrix\\') ? $expression->value : null;
    }

    return null;
}

/**
 * @param array<string, string> $serviceVariables
 *
 * @return list<string>
 */
function referenceTargetsInBinding(Node\Expr\MethodCall $call, array $serviceVariables): array
{
    $finder = new NodeFinder();
    $targets = [];
    foreach ($finder->findInstanceOf($call->args, Node\Expr\New_::class) as $new) {
        if (!$new->class instanceof Node\Name
            || (($new->class->getAttribute('resolvedName') ?? $new->class)->toString() !== 'Symfony\\Component\\DependencyInjection\\Reference')
        ) {
            continue;
        }
        $argument = $new->args[0]->value ?? null;
        $target = expressionFqcn($argument);
        if ($target === null && $argument instanceof Node\Expr\Variable && is_string($argument->name)) {
            $target = $serviceVariables[$argument->name] ?? null;
        }
        if ($target !== null) {
            $targets[$target] = true;
        }
    }

    return array_keys($targets);
}

/** @param array<string, string> $serviceVariables */
function ifContainsConditionalReference(Node\Stmt\If_ $if, string $target, array $serviceVariables): bool
{
    $finder = new NodeFinder();
    $guarded = false;
    foreach ($finder->findInstanceOf($if->cond, Node\Expr\MethodCall::class) as $call) {
        if (in_array(methodName($call), ['has', 'hasdefinition'], true)
            && expressionFqcn($call->args[0]->value ?? null) === $target
        ) {
            $guarded = true;
            break;
        }
    }
    if (!$guarded) {
        return false;
    }
    foreach ($finder->findInstanceOf($if->stmts, Node\Expr\New_::class) as $new) {
        if (!$new->class instanceof Node\Name
            || (($new->class->getAttribute('resolvedName') ?? $new->class)->toString() !== 'Symfony\\Component\\DependencyInjection\\Reference')
        ) {
            continue;
        }
        $argument = $new->args[0]->value ?? null;
        if ($argument instanceof Node\Expr\Variable && is_string($argument->name)
            && ($serviceVariables[$argument->name] ?? null) === $target
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>> $byName
 * @param array<string, true> $observedPairs
 * @param list<array{fqcn: string, proposed_owner: string, class_string_targets: list<array{source_fqcn: string, member_kind: string, member_name: string, target_fqcn: string}>, class_string_metadata_targets: list<string>}> $rows
 * @param array<string, list<string>> $actualBindingOperations
 *
 * @return array{internal_grant_count: int, coarse_internal_grant_edge_count: int, bindings: array<string, array{target: string, index: int, consumer: array<string, mixed>, used: bool, actual_operations: list<string>}>}
 */
function validateAuthorizations(array $manifest, array $byName, array $observedPairs, array $rows, array $actualBindingOperations): array
{
    $consumerUse = [];
    $bindings = [];
    foreach ($manifest['declarations'] as $target => $entry) {
        foreach ($entry['consumers'] as $index => $consumer) {
            $relation = $consumer['relation'] ?? 'import';
            if ($relation === 'import') {
                $consumerUse[$target . "\0" . $index] = false;
                continue;
            }
            if ($relation === 'composition_binding') {
                $key = $consumer['source_fqcn'] . "\0" . $target;
                if (isset($bindings[$key])) {
                    fail("duplicate composition_binding {$consumer['source_fqcn']} -> {$target}");
                }
                $bindings[$key] = ['target' => $target, 'index' => $index, 'consumer' => $consumer, 'used' => false, 'actual_operations' => $actualBindingOperations[$key] ?? []];
            }
        }
    }
    foreach ($rows as $row) {
        $classStringTargets = array_merge(
            array_column($row['class_string_targets'], 'target_fqcn'),
            $row['class_string_metadata_targets'],
        );
        foreach ($classStringTargets as $target) {
            if (!isset($byName[$target])
                || $byName[$target]['proposed_owner'] === $row['proposed_owner']
                || $byName[$target]['proposed_status'] !== 'contract'
            ) {
                continue;
            }
            $matches = [];
            foreach ($manifest['declarations'][$target]['consumers'] as $index => $consumer) {
                if (($consumer['relation'] ?? 'import') === 'import'
                    && $consumer['owner'] === $row['proposed_owner']
                    && ($consumer['source_fqcn'] === null || $consumer['source_fqcn'] === $row['fqcn'])
                ) {
                    $matches[] = $index;
                }
            }
            if (count($matches) === 1) {
                $consumerUse[$target . "\0" . $matches[0]] = true;
            }
        }
    }
    foreach ($observedPairs as $pair => $_true) {
        [$source, $target] = explode("\0", $pair, 2);
        $sourceRow = $byName[$source];
        $targetRow = $byName[$target];
        if ($sourceRow['proposed_owner'] === $targetRow['proposed_owner']) {
            continue;
        }
        if ($targetRow['proposed_status'] === 'internal') {
            if (!isset($bindings[$pair])) {
                fail("unapproved exact internal import {$source} -> {$target}");
            }
            continue;
        }
        $matches = [];
        foreach ($manifest['declarations'][$target]['consumers'] as $index => $consumer) {
            if (($consumer['relation'] ?? 'import') !== 'import') {
                continue;
            }
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
    validateContractCompositions($manifest, $byName, $observedPairs, $consumerUse);
    validateContractSurfaces($manifest, $byName, $observedPairs, $consumerUse);
    foreach ($bindings as $pair => &$binding) {
        $declared = $binding['consumer']['operations'];
        sort($declared, SORT_STRING);
        $actual = $binding['actual_operations'];
        sort($actual, SORT_STRING);
        if ($actual === []) {
            [$source, $target] = explode("\0", $pair, 2);
            fail("unclassified composition_binding {$source} -> {$target}: no Symfony container binding operation");
        }
        if ($declared !== $actual) {
            [$source, $target] = explode("\0", $pair, 2);
            fail(sprintf(
                'composition_binding operation mismatch %s -> %s: declared=[%s] actual=[%s]',
                $source,
                $target,
                implode(',', $declared),
                implode(',', $actual),
            ));
        }
        $binding['used'] = true;
    }
    unset($binding);

    $coarse = [];
    foreach ($bindings as $pair => $_binding) {
        [$sourceFqcn, $targetFqcn] = explode("\0", $pair, 2);
        $source = $byName[$sourceFqcn];
        $target = $byName[$targetFqcn];
        $coarse[semanticLayerName($source) . "\0" . semanticLayerName($target)] = true;
    }

    return ['internal_grant_count' => count($bindings), 'coarse_internal_grant_edge_count' => count($coarse), 'bindings' => $bindings];
}

/**
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>> $byName
 * @param array<string, true> $observedPairs
 * @param array<string, bool> $consumerUse
 */
function validateContractCompositions(array $manifest, array $byName, array $observedPairs, array $consumerUse): void
{
    foreach ($manifest['declarations'] as $target => $entry) {
        foreach ($entry['consumers'] as $index => $consumer) {
            if (($consumer['relation'] ?? 'import') !== 'contract_composition') {
                continue;
            }
            $carrier = $consumer['carrier_fqcn'];
            $boundary = $consumer['boundary_fqcn'];
            if ($carrier === $target) {
                fail("contract_composition {$target}#{$index} must name a distinct carrier");
            }
            if (!isset($byName[$carrier], $byName[$boundary])) {
                fail("contract_composition {$target}#{$index} references an unknown carrier or boundary");
            }
            if ($entry['visibility'] !== 'contract'
                || $byName[$carrier]['proposed_status'] !== 'contract'
                || $byName[$carrier]['proposed_owner'] !== $byName[$target]['proposed_owner']
            ) {
                fail("contract_composition {$target}#{$index} requires same-owner contract target and carrier");
            }
            if (!in_array($target, $byName[$carrier]['contract_property_containments'], true)) {
                fail("contract_composition {$target}#{$index} carrier lacks exact native stored target containment");
            }
            if ($byName[$boundary]['proposed_owner'] !== $consumer['owner']) {
                fail("contract_composition {$target}#{$index} boundary owner does not match {$consumer['owner']}");
            }
            if (!in_array('Amp\\Parallel\\Worker\\Task', $byName[$boundary]['implements'], true)) {
                fail("contract_composition {$target}#{$index} boundary must implement Amp\\Parallel\\Worker\\Task");
            }
            if (($byName[$boundary]['native_method_returns']['run'] ?? []) !== [$carrier]) {
                fail("contract_composition {$target}#{$index} boundary run() must have the exact native carrier return");
            }
            if (!isset($observedPairs[$boundary . "\0" . $carrier])) {
                fail("contract_composition {$target}#{$index} boundary lacks the exact carrier dependency");
            }
            if (isset($observedPairs[$boundary . "\0" . $target])) {
                fail("contract_composition {$target}#{$index} cannot replace a direct boundary import");
            }
            $carrierConsumerUsed = false;
            foreach ($manifest['declarations'][$carrier]['consumers'] as $carrierIndex => $carrierConsumer) {
                if (($carrierConsumer['relation'] ?? 'import') === 'import'
                    && $carrierConsumer['owner'] === $consumer['owner']
                    && ($consumerUse[$carrier . "\0" . $carrierIndex] ?? false)
                ) {
                    $carrierConsumerUsed = true;
                    break;
                }
            }
            if (!$carrierConsumerUsed) {
                fail("contract_composition {$target}#{$index} carrier lacks a separately used import consumer");
            }
            foreach ($observedPairs as $pair => $_true) {
                [$source, $dependency] = explode("\0", $pair, 2);
                if ($dependency === $target && $byName[$source]['proposed_owner'] !== $byName[$target]['proposed_owner']) {
                    fail("contract_composition {$target}#{$index} cannot authorize direct cross-owner import {$source}");
                }
            }
        }
    }
}

/**
 * A contract value may be exposed through another contract without a direct
 * cross-owner import. This is deliberately narrower than a general visibility
 * escape hatch: the carrier must be a same-owner public contract, it must
 * refer to the value in its declared source, and that carrier must itself be
 * used by the named external owner (possibly through another surface value).
 *
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>> $byName
 * @param array<string, true> $observedPairs
 * @param array<string, bool> $consumerUse
 */
function validateContractSurfaces(array $manifest, array $byName, array $observedPairs, array $consumerUse): void
{
    foreach ($manifest['declarations'] as $target => $entry) {
        foreach ($entry['consumers'] as $index => $consumer) {
            if (($consumer['relation'] ?? 'import') !== 'contract_surface') {
                continue;
            }
            $carrier = $consumer['carrier_fqcn'];
            if ($carrier === $target || !isset($byName[$carrier])) {
                fail("contract_surface {$target}#{$index} names an invalid carrier");
            }
            if ($entry['visibility'] !== 'contract'
                || $byName[$carrier]['proposed_status'] !== 'contract'
                || $byName[$carrier]['proposed_owner'] !== $byName[$target]['proposed_owner']
            ) {
                fail("contract_surface {$target}#{$index} requires a same-owner contract carrier");
            }
            if (!in_array($target, $byName[$carrier]['contract_surface_containments'], true)) {
                fail("contract_surface {$target}#{$index} carrier lacks exact declared target containment");
            }

            $carrierIsUsed = false;
            foreach ($manifest['declarations'][$carrier]['consumers'] as $carrierIndex => $carrierConsumer) {
                if (($carrierConsumer['relation'] ?? 'import') === 'import'
                    && $carrierConsumer['owner'] === $consumer['owner']
                    && ($carrierConsumer['source_fqcn'] === null || $carrierConsumer['source_fqcn'] === $consumer['source_fqcn'])
                    && ($consumerUse[$carrier . "\0" . $carrierIndex] ?? false)
                ) {
                    $carrierIsUsed = true;
                    break;
                }
                if (($carrierConsumer['relation'] ?? 'import') === 'contract_surface'
                    && $carrierConsumer['owner'] === $consumer['owner']
                    && $carrierConsumer['source_fqcn'] === $consumer['source_fqcn']
                ) {
                    $carrierIsUsed = true;
                    break;
                }
            }
            if (!$carrierIsUsed) {
                fail("contract_surface {$target}#{$index} carrier lacks an exposed consumer for {$consumer['owner']}");
            }
            foreach ($observedPairs as $pair => $_true) {
                [$sourceFqcn, $dependency] = explode("\0", $pair, 2);
                if ($dependency === $target && $byName[$sourceFqcn]['proposed_owner'] !== $byName[$target]['proposed_owner']) {
                    $hasDirectConsumer = false;
                    foreach ($entry['consumers'] as $directIndex => $directConsumer) {
                        if (($directConsumer['relation'] ?? 'import') === 'import'
                            && $directConsumer['owner'] === $byName[$sourceFqcn]['proposed_owner']
                            && ($directConsumer['source_fqcn'] === null || $directConsumer['source_fqcn'] === $sourceFqcn)
                            && ($consumerUse[$target . "\0" . $directIndex] ?? false)
                        ) {
                            $hasDirectConsumer = true;
                            break;
                        }
                    }
                    if (!$hasDirectConsumer) {
                        fail("contract_surface {$target}#{$index} cannot authorize direct cross-owner import {$sourceFqcn}");
                    }
                }
            }
        }
    }
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
        'CLAUDE.md',
        'docs/adr/README.md',
        'docs/internal/MODULE_README_TEMPLATE.md',
        'website/docs/reference/default-thresholds.md',
        'website/docs/reference/default-thresholds.ru.md',
    ];
    if (in_array($path, $p0, true)) {
        return ['Architecture.Governance', 'P0-D', 'P0 governance documentation; review with the manifest and generated topology.'];
    }

    $exact = [
        'AGENTS.md' => ['Architecture.Governance', 'P2'],
        'CHANGELOG.md' => ['Architecture.Governance', 'P2'],
        'docs/ARCHITECTURE.md' => ['Architecture.Governance', 'P2'],
        'docs/adr/0001-computed-metrics.md' => ['Analysis.Evidence.ComputedMetrics', 'P5'],
        'docs/adr/0017-baseline-ceiling.md' => ['Analysis.Policy.Baseline', 'P6'],
        'docs/adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md' => ['Analysis.Evidence.DependencyModel', 'P2'],
        'docs/adr/0022-capability-oriented-modular-monolith.md' => ['Architecture.Governance', 'P2'],
        'docs/adr/0023-p8-context-locality-and-composition-bindings.md' => ['Architecture.Governance', 'P8'],
        'docs/internal/plans/modular-architecture.md' => ['Architecture.Governance', 'P2'],
        'docs/internal/plans/modular-architecture/decisions-and-target.md' => ['Architecture.Governance', 'P2'],
        'docs/internal/plans/modular-architecture/p0-governance.md' => ['Architecture.Governance', 'P2'],
        'docs/internal/plans/modular-architecture/p1-duplication.md' => ['Architecture.Governance', 'P2'],
        'docs/internal/plans/modular-architecture/p2-dependency-model.md' => ['Architecture.Governance', 'P2'],
        'docs/internal/plans/modular-architecture/p3-run-measurement-configuration.md' => ['Architecture.Governance', 'P2'],
        'docs/internal/plans/modular-architecture/p4-architecture-policy.md' => ['Architecture.Governance', 'P2'],
        'docs/internal/plans/modular-architecture/p5-computed-metrics.md' => ['Architecture.Governance', 'P2'],
        'docs/internal/plans/modular-architecture/p6-finding-policy.md' => ['Architecture.Governance', 'P6-F'],
        'docs/internal/plans/modular-architecture/p6/p6-production-ledger.md' => ['Architecture.Governance', 'P6-0'],
        'docs/internal/plans/modular-architecture/p6/p6-relations-ledger.md' => ['Architecture.Governance', 'P6-0'],
        'docs/internal/plans/modular-architecture/p6/p6-test-ledger.md' => ['Architecture.Governance', 'P6-0'],
        'docs/internal/plans/modular-architecture/roadmap-p5-p8.md' => ['Architecture.Governance', 'P2'],
        'src/Analysis/README.md' => ['Analysis.Run', 'P2'],
        'src/Analysis/Configuration/README.md' => ['Analysis.Configuration', 'P3'],
        'src/Analysis/Evidence/CircularDependency/README.md' => ['Analysis.Evidence.CircularDependency', 'P4'],
        'src/Analysis/Evidence/ComputedMetrics/README.md' => ['Analysis.Evidence.ComputedMetrics', 'P5'],
        'src/Analysis/Evidence/DependencyModel/README.md' => ['Analysis.Evidence.DependencyModel', 'P2'],
        'src/Analysis/Evidence/Duplication/README.md' => ['Analysis.Evidence.Duplication', 'P1'],
        'src/Analysis/Evidence/Measurement/README.md' => ['Analysis.Evidence.Measurement', 'P3'],
        'src/Analysis/Evidence/Prioritization/README.md' => ['Analysis.Evidence.Prioritization', 'P6-D'],
        'src/Analysis/Evidence/CodeSmell/README.md' => ['Analysis.Evidence.CodeSmell', 'P7'],
        'src/Analysis/Evidence/Cohesion/README.md' => ['Analysis.Evidence.Cohesion', 'P7'],
        'src/Analysis/Evidence/Complexity/README.md' => ['Analysis.Evidence.Complexity', 'P7'],
        'src/Analysis/Evidence/Coupling/README.md' => ['Analysis.Evidence.Coupling', 'P7'],
        'src/Analysis/Evidence/Design/README.md' => ['Analysis.Evidence.Design', 'P7'],
        'src/Analysis/Evidence/Maintainability/README.md' => ['Analysis.Evidence.Maintainability', 'P7'],
        'src/Analysis/Evidence/Security/README.md' => ['Analysis.Evidence.Security', 'P7'],
        'src/Analysis/Evidence/Size/README.md' => ['Analysis.Evidence.Size', 'P7'],
        'src/Analysis/Finding/README.md' => ['Analysis.Finding', 'P6-A'],
        'src/Analysis/Policy/Inline/README.md' => ['Analysis.Policy.Inline', 'P6-B'],
        'src/Analysis/Run/README.md' => ['Analysis.Run', 'P3'],
        'src/Analysis/Policy/Architecture/README.md' => ['Analysis.Policy.Architecture', 'P4'],
        'src/Analysis/Policy/Baseline/README.md' => ['Analysis.Policy.Baseline', 'P6-C'],
        'src/Core/README.md' => ['Architecture.Governance', 'P2'],
        'src/Core/Profiler/README.md' => ['Core.Profiler', 'P8'],
        'src/Core/Symbol/README.md' => ['Core.Symbol', 'P8'],
        'src/Infrastructure/Ast/README.md' => ['Infrastructure.Ast', 'P8'],
        'src/Infrastructure/DependencyInjection/README.md' => ['Infrastructure.DependencyInjection', 'P8'],
        'src/Infrastructure/Parallel/README.md' => ['Infrastructure.Parallel', 'P8'],
        'src/Infrastructure/Rule/README.md' => ['Infrastructure.Rule', 'P8'],
        'src/Infrastructure/Serializer/README.md' => ['Infrastructure.Serializer', 'P8'],
        'src/Infrastructure/Console/README.md' => ['Architecture.Governance', 'P2'],
        'src/Infrastructure/README.md' => ['Architecture.Governance', 'P2'],
        'src/Reporting/GraphProjection/README.md' => ['Reporting.GraphProjection', 'P2'],
        'src/Reporting/README.md' => ['Architecture.Governance', 'P2'],
        'website/docs/getting-started/configuration.md' => ['Analysis.Run', 'P3'],
        'website/docs/getting-started/configuration.ru.md' => ['Analysis.Run', 'P3'],
        'website/docs/reference/health-scores.md' => ['Analysis.Evidence.ComputedMetrics', 'P5'],
        'website/docs/reference/health-scores.ru.md' => ['Analysis.Evidence.ComputedMetrics', 'P5'],
        'website/docs/rules/duplication.md' => ['Analysis.Evidence.Duplication', 'P1'],
        'website/docs/rules/duplication.ru.md' => ['Analysis.Evidence.Duplication', 'P1'],
        'website/docs/rules/architecture.md' => ['Architecture.Governance', 'P2'],
        'website/docs/rules/architecture.ru.md' => ['Architecture.Governance', 'P2'],
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
        'website/docs/rules/annotation' => ['Analysis.Policy.Inline', 'P6-B'],
        'website/docs/rules/code-smell' => ['Analysis.Evidence.CodeSmell', 'P7'],
        'website/docs/rules/cohesion' => ['Analysis.Evidence.Cohesion', 'P7'],
        'website/docs/rules/complexity' => ['Analysis.Evidence.Complexity', 'P7'],
        'website/docs/rules/coupling' => ['Analysis.Evidence.Coupling', 'P7'],
        'website/docs/rules/design' => ['Analysis.Evidence.Design', 'P7'],
        'website/docs/rules/maintainability' => ['Analysis.Evidence.Maintainability', 'P7'],
        'website/docs/rules/security' => ['Analysis.Evidence.Security', 'P7'],
        'website/docs/rules/size' => ['Analysis.Evidence.Size', 'P7'],
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
        'docs/adr/0024-channel-identity-and-selector-semantics.md',
        'docs/adr/0025-channel-selectors-in-configuration-keys.md',
        'docs/adr/0026-assigned-declaration-ordinal.md',
        'docs/internal/CLI_CONVENTIONS.md',
        'docs/internal/COMPETITOR_COMPARISON.md',
        'docs/internal/PRODUCT_ROADMAP.md',
        'docs/internal/PRODUCT_VISION.md',
        'docs/internal/SCANNER_VALIDATION_ROUND_1_FINDINGS.md',
        'docs/internal/SCANNER_VALIDATION_ROUND_1_PLAN.md',
        'docs/internal/SCANNER_VALIDATION_ROUND_2_PLAN.md',
        'docs/internal/plans/baseline-compaction/PLAN-IDENTITY.md',
        'docs/internal/plans/baseline-compaction/PLAN.md',
        'docs/internal/plans/baseline-compaction/enumeration-artifact.md',
        'docs/internal/plans/baseline-compaction/enumeration-identity.md',
        'docs/internal/plans/baseline-compaction/enumeration-identity-r4.md',
        'docs/internal/plans/baseline-compaction/identity-map.md',
        'docs/internal/plans/baseline-compaction/measurements/README.md',
        'docs/internal/plans/channel-identity-substrate.md',
        'docs/internal/plans/diff-mode-new-only.md',
        'docs/internal/plans/global-functions-graph.md',
        'docs/internal/plans/phpdoc-dependencies.md',
        'docs/internal/plans/client-requests/abstractness-enum-exclusion.md',
        'docs/internal/plans/client-requests/architecture-unassigned-class.md',
        'docs/internal/plans/client-requests/architecture-layer-pending.md',
        'docs/internal/plans/client-requests/debug-layer-assignment-json.md',
        'docs/internal/plans/client-requests/severity-report-only.md',
        'docs/internal/plans/client-requests/threshold-override-exact-matching.md',
        'src/Core/Path/README.md',
        'src/Infrastructure/Cache/README.md',
        'src/Infrastructure/Git/README.md',
        'src/Infrastructure/Logging/README.md',
        'src/Infrastructure/Profiler/README.md',
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
