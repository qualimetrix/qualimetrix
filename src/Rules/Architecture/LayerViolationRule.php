<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Architecture;

use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerMatch;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\MatchedCriterion;
use Qualimetrix\Architecture\Domain\Layer\MatchedCriterionKind;
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

    public const string EMPTY_TEMPLATE_DIAGNOSTIC_NAME = 'architecture.empty-template';

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

        // Rebind the registry's ClassContextFactory to this run's dependency
        // graph so `attributes` / `implements` / `extends` membership criteria
        // see fresh data. Cache invalidation is handled by bindGraph().
        $registry->bindGraph($context->dependencyGraph);

        // Per-class evidence (local — never fields; statelessness regression in tests).
        [$layerHits, $shadowEvidence] = $this->collectClassEvidence($registry, $context);

        // Per-edge violations + coverage state (also local).
        [$edgeViolations, $coverageState] = $this->collectEdgeViolations($architecture, $context);

        // O(1) name → definition lookup for diagnostic builders that need pattern lists.
        $definitionsByName = [];
        foreach ($registry->definitions() as $definition) {
            $definitionsByName[$definition->name()] = $definition;
        }

        return [
            ...$edgeViolations,
            ...$this->buildCoverageDiagnosticAsList($architecture->coverage(), $coverageState),
            ...$this->buildUnreachableLayerDiagnostics($registry, $layerHits, $definitionsByName),
            ...$this->buildPotentialShadowDiagnostics($shadowEvidence),
            ...self::buildEmptyTemplateDiagnostics($architecture->emptyTemplateNames()),
        ];
    }

    /**
     * Emits one warning diagnostic per template name that produced zero
     * concrete layers during expansion (Phase 2 direction 2).
     *
     * The list is populated by
     * {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage} and
     * threaded through
     * {@see \Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder}
     * to the architecture configuration consumed here via
     * {@see \Qualimetrix\Core\Rule\AnalysisContext}.
     *
     * Severity is {@see Severity::Warning} rather than {@see Severity::Info}
     * because an empty template is usually a typo, missing dependency in the
     * scanned paths, or recent refactor that removed the matching classes —
     * all conditions a user wants to be loud.
     *
     * @param list<string> $emptyTemplateNames
     *
     * @return list<Violation>
     */
    private static function buildEmptyTemplateDiagnostics(array $emptyTemplateNames): array
    {
        if ($emptyTemplateNames === []) {
            return [];
        }

        $diagnostics = [];
        foreach ($emptyTemplateNames as $template) {
            $diagnostics[] = new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forProject(),
                ruleName: self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
                violationCode: self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
                message: \sprintf(
                    'Template layer "%s" expanded to zero concrete layers — no class in the analysed codebase '
                    . 'matched the template\'s criteria. Common causes: (1) a typo in the template name or '
                    . 'pattern, (2) matching classes were filtered out by file discovery (`exclude_paths` / '
                    . '`exclude_namespaces` at top level or in rule options), (3) the module disappeared in a '
                    . 'recent refactor, or (4) a single-segment capture `{var}` is used where the binding spans '
                    . 'multiple namespace segments — try `{var:**}` for cross-segment captures.',
                    $template,
                ),
                severity: Severity::Warning,
                recommendation: 'Verify the template patterns against the project structure, or remove the '
                    . 'template if no longer relevant.',
            );
        }

        return $diagnostics;
    }

    /**
     * Walks `metrics->all(SymbolType::Class_)` once and collects two local
     * structures used downstream:
     *
     * 1. `layerHits` — per-layer count of classes that ended up in that layer
     *    (feeds `architecture.unreachable-layer`).
     * 2. `shadowEvidence` — per (assigned, shadowed) pair, list of evidence
     *    entries carrying the class FQN plus the specific criterion descriptors
     *    that matched on each side (feeds `architecture.potential-shadow`
     *    without re-walking the layer list at emission time). Descriptors
     *    carry the criterion kind (pattern / suffix / attribute / implements
     *    / extends) so the message can name the actual cause of the shadow.
     *
     * Both are LOCAL variables here. Per CLAUDE.md "stateless rules", the rule
     * instance is reused across `analyze()` invocations — any field-based
     * accumulator would leak counts. The dedicated statelessness regression
     * test pins this contract.
     *
     * @return array{0: array<string, int>, 1: array<string, array<string, list<array{fqn: string, assignedCriterion: MatchedCriterion, shadowedCriterion: MatchedCriterion}>>>}
     */
    private function collectClassEvidence(LayerRegistry $registry, AnalysisContext $context): array
    {
        $layerHits = [];
        foreach ($registry->layerNames() as $layerName) {
            $layerHits[$layerName] = 0;
        }

        /** @var array<string, array<string, list<array{fqn: string, assignedCriterion: MatchedCriterion, shadowedCriterion: MatchedCriterion}>>> $shadowEvidence */
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
                $shadowEvidence[$assigned->layerName][$matches[$i]->layerName][] = [
                    'fqn' => $classFqn,
                    'assignedCriterion' => $assigned->primaryCriterion(),
                    'shadowedCriterion' => $matches[$i]->primaryCriterion(),
                ];
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
            $fromMatches = $registry->resolveAll($dependency->source);
            $toMatches = $registry->resolveAll($dependency->target);

            $fromMatch = $fromMatches[0] ?? null;
            $toMatch = $toMatches[0] ?? null;

            if ($fromMatch === null) {
                $sourceEdges++;
                $classes[$dependency->source->toCanonical()] = $dependency->source->toString();
            }

            if ($toMatch === null) {
                $targetEdges++;
                $classes[$dependency->target->toCanonical()] = $dependency->target->toString();
            }

            $violation = $this->buildViolation($dependency, $fromMatch, $toMatch, $architecture);
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
        ?LayerMatch $fromMatch,
        ?LayerMatch $toMatch,
        ArchitectureConfiguration $architecture,
    ): ?Violation {
        if ($fromMatch === null || $toMatch === null) {
            return null;
        }

        $fromLayer = $fromMatch->layerName;
        $toLayer = $toMatch->layerName;

        if ($architecture->policy()->isAllowed($fromLayer, $toLayer, $dependency->type)) {
            return null;
        }

        \assert($this->options instanceof LayerViolationOptions);

        return new Violation(
            location: $dependency->location,
            symbolPath: $dependency->source,
            ruleName: self::NAME,
            violationCode: self::NAME,
            message: \sprintf(
                'Layer "%s" must not depend on layer "%s" (%s → %s, %s)%s',
                $fromLayer,
                $toLayer,
                $dependency->source->toString(),
                $dependency->target->toString(),
                $dependency->type->description(),
                self::describeMatchTrailer($fromMatch, $toMatch),
            ),
            severity: $this->options->severity,
            recommendation: $this->buildRecommendation($dependency, $fromLayer, $toLayer, $architecture),
            dependencyTarget: $dependency->target,
            dependencyType: $dependency->type,
        );
    }

    /**
     * Appends a "[matched by ...]" trailer to the violation message when at
     * least one side was assigned via a non-pattern criterion or when the
     * match was multi-criterion (Phase 2 direction 1 diagnostic specificity).
     *
     * For Phase-1-shape patterns-only configs both sides always carry a
     * single Pattern criterion — in that case the trailer collapses to an
     * empty string, leaving the legacy message format byte-for-byte stable.
     */
    private static function describeMatchTrailer(LayerMatch $fromMatch, LayerMatch $toMatch): string
    {
        if (self::isPlainPatternMatch($fromMatch) && self::isPlainPatternMatch($toMatch)) {
            return '';
        }

        return \sprintf(
            ' [source matched by %s; target matched by %s]',
            self::describeCriteriaList($fromMatch->matchedCriteria),
            self::describeCriteriaList($toMatch->matchedCriteria),
        );
    }

    private static function isPlainPatternMatch(LayerMatch $match): bool
    {
        return \count($match->matchedCriteria) === 1
            && $match->matchedCriteria[0]->kind === MatchedCriterionKind::Pattern;
    }

    /**
     * @param list<MatchedCriterion> $criteria
     */
    private static function describeCriteriaList(array $criteria): string
    {
        return implode(', ', array_map(
            static fn(MatchedCriterion $criterion): string => $criterion->describe(),
            $criteria,
        ));
    }

    /**
     * Renders a layer's declared criteria as a human-readable summary, used in
     * the {@code architecture.unreachable-layer} message. Empty kinds are
     * omitted so the message only mentions criteria the user actually wrote.
     */
    private static function describeLayerCriteria(LayerDefinition $definition): string
    {
        $membership = $definition->membership();
        $segments = [];

        if ($membership->patterns !== []) {
            $segments[] = 'patterns: ' . self::quoteCsv($membership->patterns);
        }
        if ($membership->suffix !== []) {
            $segments[] = 'suffix: ' . self::quoteCsv($membership->suffix);
        }
        if ($membership->attributes !== []) {
            $segments[] = 'attributes: ' . self::quoteCsv($membership->attributes);
        }
        if ($membership->implements !== []) {
            $segments[] = 'implements: ' . self::quoteCsv($membership->implements);
        }
        if ($membership->extends !== []) {
            $segments[] = 'extends: ' . self::quoteCsv($membership->extends);
        }

        return implode('; ', $segments);
    }

    /**
     * @param list<string> $values
     */
    private static function quoteCsv(array $values): string
    {
        return implode(', ', array_map(static fn(string $v): string => '"' . $v . '"', $values));
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
            $sampleList .= \sprintf(' ...and %d more', $remaining);
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
     * @param array<string, LayerDefinition> $definitionsByName Precomputed name → definition lookup
     *                                                          for O(1) pattern access.
     *
     * @return list<Violation>
     */
    private function buildUnreachableLayerDiagnostics(LayerRegistry $registry, array $layerHits, array $definitionsByName): array
    {
        $violations = [];

        foreach ($registry->layerNames() as $layerName) {
            if (($layerHits[$layerName] ?? 0) > 0) {
                continue;
            }

            $criteria = self::describeLayerCriteria($definitionsByName[$layerName]);

            $message = \sprintf(
                'Layer "%s" was never matched during analysis. Possible causes: (1) it is shadowed by a broader layer earlier in the declaration order, (2) the declared criteria (%s) match no class in the analysed codebase. Run "qmx debug:layer-assignment <class>" to inspect specific classes.',
                $layerName,
                $criteria,
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
     * parallel collection. The per-pair sample is sorted lexicographically by
     * FQN and the pair list is sorted by (assigned, shadowed) before emission
     * so CI diffs are stable across runs.
     *
     * Each evidence entry already carries the primary criterion that matched
     * on each side (recorded during `collectClassEvidence()`), so no second
     * walk over the layer list is necessary at emission time.
     *
     * @param array<string, array<string, list<array{fqn: string, assignedCriterion: MatchedCriterion, shadowedCriterion: MatchedCriterion}>>> $shadowEvidence
     *
     * @return list<Violation>
     */
    private function buildPotentialShadowDiagnostics(array $shadowEvidence): array
    {
        $pairs = [];
        foreach ($shadowEvidence as $assigned => $shadowedMap) {
            foreach ($shadowedMap as $shadowed => $entries) {
                usort($entries, static fn(array $a, array $b): int => strcmp($a['fqn'], $b['fqn']));
                $pairs[] = [
                    'assigned' => $assigned,
                    'shadowed' => $shadowed,
                    'entries' => $entries,
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
            $entries = $pair['entries'];
            $total = \count($entries);

            // Evidence is only recorded when matchCount > 1, so the entry list
            // for any emitted pair is non-empty by construction.
            \assert($entries !== []);

            $sample = \array_slice($entries, 0, self::SHADOW_SAMPLE_LIMIT);
            $remaining = $total - \count($sample);

            $assignedCriterion = $sample[0]['assignedCriterion'];
            $shadowedCriterion = $sample[0]['shadowedCriterion'];

            $sampleFqns = array_map(static fn(array $entry): string => $entry['fqn'], $sample);
            $sampleList = implode(', ', $sampleFqns);
            if ($remaining > 0) {
                $sampleList .= \sprintf(' ...and %d more', $remaining);
            }

            $message = \sprintf(
                'Layer "%s" (%s) shadows layer "%s" (%s) for %d class(es) including %s. Run "qmx debug:layer-assignment <class>" to inspect specific cases.',
                $assignedLayer,
                $assignedCriterion->describe(),
                $shadowedLayer,
                $shadowedCriterion->describe(),
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
}
