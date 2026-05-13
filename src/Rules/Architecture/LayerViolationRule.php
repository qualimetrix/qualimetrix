<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Architecture;

use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Reports dependencies that violate the user-declared architecture policy.
 *
 * The rule reads {@see AnalysisContext::$architecture} for the layer registry
 * and allow-list, and {@see AnalysisContext::$dependencyGraph} for the set of
 * concrete dependency edges. Every edge whose source and target both fall into
 * declared layers and whose source→target pair is not in the policy's
 * allow-list produces one {@see Violation}.
 *
 * Under declaration-order matching (ADR 0006), a class is assigned to the
 * FIRST layer whose patterns match its FQN. The rule emits three diagnostic
 * channels (each under its own rule name so they can be baselined/suppressed/
 * filtered independently):
 *
 * - `architecture.layer-violation` — per use-site, one violation per
 *   forbidden dependency edge.
 * - `architecture.coverage` — when {@see ArchitectureConfiguration::coverage()}
 *   is not {@see CoverageMode::Ignore}, one aggregated Violation summarising
 *   edges that touch unclassified classes.
 * - `architecture.unreachable-layer` — info-severity, one Violation per
 *   declared layer that matched zero classes during the run (catches the
 *   loud failure mode where a broader pattern earlier in the order
 *   silently swallowed everything).
 * - `architecture.potential-shadow` — info-severity, one Violation per
 *   (assigned, shadowed) layer pair seen in practice. Evidence-based:
 *   every class is walked and all matching layers are recorded; classes
 *   matching more than one layer contribute a (first-match, later-match)
 *   pair. Catches the quiet failure mode where an earlier pattern steals
 *   classes that a user expected a later, narrower layer to own (prefix
 *   overlap, suffix-theft, arbitrary intersection — all caught by the
 *   same mechanism).
 *
 * **Statelessness:** per CLAUDE.md "stateless rules" rule, all per-run state
 * (the unreachable-layer hit counter and the shadow-evidence map) lives as
 * LOCAL variables inside {@see analyze()} (or its private helpers). Storing
 * them as fields would leak counts across `analyze()` calls because the rule
 * executor reuses rule instances.
 *
 * @qmx-threshold complexity.wmc warning=70 error=80
 *                The rule orchestrates four cohesive diagnostic channels
 *                (layer-violation, coverage, unreachable-layer, potential-shadow)
 *                that all share the same registry walk and per-class evidence
 *                pass. Splitting them across classes would multiply file count
 *                without simplifying the data flow; the methods themselves are
 *                individually small.
 *
 * @qmx-ignore design.god-class
 *             reason="The rule is the natural cohesion boundary for the architecture-rule surface (one rule, four cohesive diagnostic channels sharing the same registry walk). LCOM is inflated by parallel diagnostic builders — splitting would not improve structure."
 * @qmx-ignore health.cohesion
 *             reason="Class-level cohesion score is dominated by the multiple diagnostic builders sharing the same registry/options. Splitting would not materially improve the structure (see `@qmx-ignore design.god-class` above)."
 */
final class LayerViolationRule extends AbstractRule
{
    public const string NAME = 'architecture.layer-violation';

    public const string COVERAGE_DIAGNOSTIC_NAME = 'architecture.coverage';

    public const string UNREACHABLE_LAYER_DIAGNOSTIC_NAME = 'architecture.unreachable-layer';

    public const string POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shadow';

    private const int COVERAGE_SAMPLE_LIMIT = 10;

    private const int SHADOW_SAMPLE_LIMIT = 5;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects dependencies between layers that are not explicitly allowed by the architecture policy.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Architecture;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return class-string<LayerViolationOptions>
     */
    public static function getOptionsClass(): string
    {
        return LayerViolationOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'layer-violation' => 'enabled',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof LayerViolationOptions);

        if (!$this->options->isEnabled()) {
            return [];
        }

        $architecture = $context->architecture;
        if ($architecture === null || $architecture->isEmpty()) {
            return [];
        }

        $registry = $architecture->registry();

        // Per-class evidence (local — never fields; statelessness regression in tests).
        [$layerHits, $shadowEvidence] = $this->collectClassEvidence($registry, $context);

        // Per-edge violations + coverage state (also local).
        [$edgeViolations, $coverageState] = $this->collectEdgeViolations($architecture, $context);

        return [
            ...$edgeViolations,
            ...$this->buildCoverageDiagnosticAsList($architecture->coverage(), $coverageState),
            ...$this->buildUnreachableLayerDiagnostics($registry, $layerHits),
            ...$this->buildPotentialShadowDiagnostics($shadowEvidence, $registry),
        ];
    }

    /**
     * Walks `metrics->all(SymbolType::Class_)` once and collects two local
     * structures used downstream:
     *
     * 1. `layerHits` — per-layer count of classes that ended up in that layer
     *    (feeds `architecture.unreachable-layer`).
     * 2. `shadowEvidence` — per (assigned, shadowed) pair, list of class FQNs
     *    that matched both layers (feeds `architecture.potential-shadow`).
     *
     * Both are LOCAL variables here. Per CLAUDE.md "stateless rules", the rule
     * instance is reused across `analyze()` invocations — any field-based
     * accumulator would leak counts. The dedicated statelessness regression
     * test pins this contract.
     *
     * @return array{0: array<string, int>, 1: array<string, array<string, list<string>>>}
     */
    private function collectClassEvidence(LayerRegistry $registry, AnalysisContext $context): array
    {
        $layerHits = [];
        foreach ($registry->layerNames() as $layerName) {
            $layerHits[$layerName] = 0;
        }

        /** @var array<string, array<string, list<string>>> $shadowEvidence */
        $shadowEvidence = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classSymbol) {
            $matches = $registry->resolveAll($classSymbol->symbolPath);
            if ($matches === []) {
                continue;
            }

            $assigned = $matches[0];
            $layerHits[$assigned->layerName] = ($layerHits[$assigned->layerName] ?? 0) + 1;

            $matchCount = \count($matches);
            if ($matchCount === 1) {
                continue;
            }

            $classFqn = $classSymbol->symbolPath->toString();
            for ($i = 1; $i < $matchCount; $i++) {
                $shadowEvidence[$assigned->layerName][$matches[$i]->layerName][] = $classFqn;
            }
        }

        return [$layerHits, $shadowEvidence];
    }

    /**
     * Walks the dependency graph and produces per-edge layer violations.
     * Returns the violation list and the coverage-state struct used by
     * `architecture.coverage` (counts of unmatched ends + the set of
     * unclassified class FQNs).
     *
     * @return array{0: list<Violation>, 1: array{sourceEdges: int, targetEdges: int, classes: array<string, string>}}
     */
    private function collectEdgeViolations(ArchitectureConfiguration $architecture, AnalysisContext $context): array
    {
        $violations = [];
        $sourceEdges = 0;
        $targetEdges = 0;
        $classes = [];

        $graph = $context->dependencyGraph;
        if ($graph === null) {
            return [$violations, ['sourceEdges' => 0, 'targetEdges' => 0, 'classes' => []]];
        }

        $registry = $architecture->registry();
        foreach ($graph->getAllDependencies() as $dependency) {
            $fromLayer = $registry->resolveLayer($dependency->source);
            $toLayer = $registry->resolveLayer($dependency->target);

            if ($fromLayer === null) {
                $sourceEdges++;
                $classes[$dependency->source->toCanonical()] = $dependency->source->toString();
            }

            if ($toLayer === null) {
                $targetEdges++;
                $classes[$dependency->target->toCanonical()] = $dependency->target->toString();
            }

            $violation = $this->buildViolation($dependency, $fromLayer, $toLayer, $architecture);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return [$violations, ['sourceEdges' => $sourceEdges, 'targetEdges' => $targetEdges, 'classes' => $classes]];
    }

    /**
     * Trivial wrapper around {@see buildCoverageDiagnostic()} so the spread
     * in {@see analyze()} stays homogeneous.
     *
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>} $state
     *
     * @return list<Violation>
     */
    private function buildCoverageDiagnosticAsList(CoverageMode $mode, array $state): array
    {
        $diagnostic = $this->buildCoverageDiagnostic(
            $mode,
            $state['sourceEdges'],
            $state['targetEdges'],
            array_values($state['classes']),
        );

        return $diagnostic === null ? [] : [$diagnostic];
    }

    private function buildViolation(
        Dependency $dependency,
        ?string $fromLayer,
        ?string $toLayer,
        ArchitectureConfiguration $architecture,
    ): ?Violation {
        if ($fromLayer === null || $toLayer === null) {
            return null;
        }

        if ($architecture->policy()->isAllowed($fromLayer, $toLayer)) {
            return null;
        }

        \assert($this->options instanceof LayerViolationOptions);

        return new Violation(
            location: $dependency->location,
            symbolPath: $dependency->source,
            ruleName: self::NAME,
            violationCode: self::NAME,
            message: \sprintf(
                'Layer "%s" must not depend on layer "%s" (%s → %s, %s)',
                $fromLayer,
                $toLayer,
                $dependency->source->toString(),
                $dependency->target->toString(),
                $dependency->type->description(),
            ),
            severity: $this->options->severity,
            recommendation: $this->buildRecommendation($dependency, $fromLayer, $toLayer, $architecture),
            dependencyTarget: $dependency->target,
            dependencyType: $dependency->type,
        );
    }

    private function buildRecommendation(
        Dependency $dependency,
        string $fromLayer,
        string $toLayer,
        ArchitectureConfiguration $architecture,
    ): string {
        $allowed = $architecture->policy()->allowedTargets($fromLayer);

        if ($allowed === []) {
            $line1 = \sprintf(
                'Layer "%s" is not allowed to depend on any other declared layer.',
                $fromLayer,
            );
        } else {
            $line1 = \sprintf(
                'Allowed targets for layer "%s": %s. Consider routing through one of them.',
                $fromLayer,
                implode(', ', $allowed),
            );
        }

        $payload = json_encode(
            [
                'fromLayer' => $fromLayer,
                'toLayer' => $toLayer,
                'source' => $dependency->source->toString(),
                'target' => $dependency->target->toString(),
                'type' => $dependency->type->value,
            ],
            \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        return $line1 . "\n" . 'Dep data: ' . $payload;
    }

    /**
     * Builds the coverage diagnostic Violation when {@see CoverageMode} is not
     * {@see CoverageMode::Ignore} and at least one out-of-layer end was seen.
     *
     * @param list<string> $unmatchedClasses Deduplicated class display-name FQNs seen at out-of-layer ends.
     */
    private function buildCoverageDiagnostic(
        CoverageMode $mode,
        int $unmatchedSourceEdges,
        int $unmatchedTargetEdges,
        array $unmatchedClasses,
    ): ?Violation {
        if ($mode === CoverageMode::Ignore) {
            return null;
        }

        $unmatchedEnds = $unmatchedSourceEdges + $unmatchedTargetEdges;
        if ($unmatchedEnds === 0) {
            return null;
        }

        $severity = $mode === CoverageMode::Error ? Severity::Error : Severity::Info;

        sort($unmatchedClasses);
        $sample = \array_slice($unmatchedClasses, 0, self::COVERAGE_SAMPLE_LIMIT);
        $remaining = \count($unmatchedClasses) - \count($sample);

        $sampleList = implode(', ', $sample);
        if ($remaining > 0) {
            $sampleList .= \sprintf(' (+%d more)', $remaining);
        }

        $message = \sprintf(
            'Architecture coverage: %d edge(s) with unmatched source layer, %d edge(s) with unmatched target layer, %d class(es) outside all declared layers.',
            $unmatchedSourceEdges,
            $unmatchedTargetEdges,
            \count($unmatchedClasses),
        );

        $recommendation = $sample === []
            ? 'Declare layers covering the remaining classes or accept the gap by leaving coverage on "ignore".'
            : 'Examples of unclassified classes: ' . $sampleList . '. Declare layers covering these classes or accept the gap by leaving coverage on "ignore".';

        return new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forProject(),
            ruleName: self::COVERAGE_DIAGNOSTIC_NAME,
            violationCode: self::COVERAGE_DIAGNOSTIC_NAME,
            message: $message,
            severity: $severity,
            recommendation: $recommendation,
        );
    }

    /**
     * Emits one info diagnostic per declared layer whose patterns matched
     * zero classes during analysis.
     *
     * @param array<string, int> $layerHits Local map (NOT a field) of
     *                                      layerName → number of classes assigned.
     *
     * @return list<Violation>
     */
    private function buildUnreachableLayerDiagnostics(LayerRegistry $registry, array $layerHits): array
    {
        $violations = [];

        foreach ($registry->layerNames() as $layerName) {
            if (($layerHits[$layerName] ?? 0) > 0) {
                continue;
            }

            $patterns = [];
            foreach ($registry->definitions() as $definition) {
                if ($definition->name() === $layerName) {
                    $patterns = $definition->patterns();
                    break;
                }
            }

            $message = \sprintf(
                'Layer "%s" was never matched during analysis. Possible causes: (1) it is shadowed by a broader layer earlier in the declaration order, (2) the pattern(s) [%s] match no class in the analysed codebase. Run "qmx debug:layer-assignment <class>" to inspect specific classes.',
                $layerName,
                implode(', ', array_map(static fn(string $p): string => '"' . $p . '"', $patterns)),
            );

            $violations[] = new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forProject(),
                ruleName: self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                violationCode: self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                message: $message,
                severity: Severity::Info,
                recommendation: 'Move the layer above any broader layer that captures its classes, or remove the layer if its pattern intentionally covers no class.',
            );
        }

        return $violations;
    }

    /**
     * Emits one info diagnostic per (assigned, shadowed) layer pair observed
     * during the class iteration.
     *
     * Determinism: `metrics->all()` iteration order is not stable under
     * parallel collection. Both the per-pair sample (5 example FQNs) and the
     * pair list are sorted lexicographically before emission so CI diffs are
     * stable across runs.
     *
     * @param array<string, array<string, list<string>>> $shadowEvidence
     *
     * @return list<Violation>
     */
    private function buildPotentialShadowDiagnostics(array $shadowEvidence, LayerRegistry $registry): array
    {
        // Flatten (assigned, shadowed, fqnList) and sort for determinism.
        $pairs = [];
        foreach ($shadowEvidence as $assigned => $shadowedMap) {
            foreach ($shadowedMap as $shadowed => $fqns) {
                $sortedFqns = $fqns;
                sort($sortedFqns);
                $pairs[] = [
                    'assigned' => $assigned,
                    'shadowed' => $shadowed,
                    'fqns' => $sortedFqns,
                ];
            }
        }

        usort($pairs, static function (array $a, array $b): int {
            $cmp = strcmp($a['assigned'], $b['assigned']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['shadowed'], $b['shadowed']);
        });

        $violations = [];
        foreach ($pairs as $pair) {
            $assignedLayer = $pair['assigned'];
            $shadowedLayer = $pair['shadowed'];
            $fqns = $pair['fqns'];
            $total = \count($fqns);

            $sample = \array_slice($fqns, 0, self::SHADOW_SAMPLE_LIMIT);
            $remaining = $total - \count($sample);

            // Identify the patterns involved (best-effort — use the first
            // sample class to find which pattern matched on each side).
            $sampleFqn = $sample[0] ?? '';
            $assignedPattern = $this->findMatchingPattern($registry, $assignedLayer, $sampleFqn);
            $shadowedPattern = $this->findMatchingPattern($registry, $shadowedLayer, $sampleFqn);

            $sampleList = implode(', ', $sample);
            if ($remaining > 0) {
                $sampleList .= \sprintf(' ...and %d more', $remaining);
            }

            $message = \sprintf(
                'Layer "%s" (pattern: %s) shadows layer "%s" (pattern: %s) for %d class(es) including %s. Run "qmx debug:layer-assignment <class>" to inspect specific cases.',
                $assignedLayer,
                $assignedPattern !== null ? '"' . $assignedPattern . '"' : '(unknown)',
                $shadowedLayer,
                $shadowedPattern !== null ? '"' . $shadowedPattern . '"' : '(unknown)',
                $total,
                $sampleList,
            );

            $violations[] = new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forProject(),
                ruleName: self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                violationCode: self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                message: $message,
                severity: Severity::Info,
                recommendation: \sprintf(
                    'If layer "%s" should own these classes, declare it BEFORE "%s" (declaration order, first match wins). Otherwise tighten the patterns so the layers no longer overlap.',
                    $shadowedLayer,
                    $assignedLayer,
                ),
            );
        }

        return $violations;
    }

    /**
     * Helper for the shadow diagnostic: ask the named layer which of its
     * patterns matched the given FQN.
     */
    private function findMatchingPattern(LayerRegistry $registry, string $layerName, string $fqn): ?string
    {
        if ($fqn === '') {
            return null;
        }

        foreach ($registry->definitions() as $definition) {
            if ($definition->name() !== $layerName) {
                continue;
            }
            return $definition->firstMatchingPattern($fqn);
        }

        return null;
    }
}
