<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Reports dependencies that violate the user-declared architecture policy.
 *
 * The rule reads the prepared {@see ArchitectureConfiguration} from the
 * injected {@see ArchitecturePolicy} for the layer registry
 * and allow-list, and {@see AnalysisContext::$dependencyGraph} for the set of
 * concrete dependency edges. Every edge whose source and target both fall into
 * declared layers and whose source→target pair is not in the policy's
 * allow-list produces one {@see Violation}.
 *
 * Under declaration-order matching (ADR 0006), a class is assigned to the
 * FIRST layer whose patterns match its FQN. The rule emits seven diagnostic
 * channels (each under its own rule name so they can be baselined/suppressed/
 * filtered independently), but builds only one of them itself:
 *
 * - `architecture.layer-violation` — per use-site, one violation per
 *   forbidden dependency edge. The rule's own channel.
 * - `architecture.coverage` and `architecture.unassigned-class` — the two
 *   per-run summaries of what fell outside every declared layer, gated by
 *   {@see ArchitectureConfiguration::coverage()} and by
 *   {@see LayerViolationOptions::$unassignedClass} respectively. Both are
 *   built by {@see OutsideLayerSummary}, whose docblock explains why counting
 *   dependency-edge ends makes the first unusable as a gate on one's own code.
 * - `architecture.unreachable-layer`, `architecture.pending-layer-matched`,
 *   `architecture.potential-shadow` and `architecture.empty-template` — the
 *   four verdicts on the declaration itself, built by
 *   {@see DeclaredLayerReachability} from the evidence this rule collects.
 *
 * What the rule contributes to those four is the evidence, and its shape is
 * the part worth knowing here: per layer, a count of the classes and
 * dependency-edge ends ASSIGNED to it, and a set of the distinct symbols it
 * MATCHED at all. Assignment answers "did this layer capture anything";
 * matching answers "does the code this layer describes exist", which is a
 * different question the moment a broader layer declared earlier wins every
 * one of the matches. Considering edge ends as well as classes matters for
 * layers that exist only to classify out-of-tree code — a vendor namespace
 * like `ClickHouseDB\**` is never itself analysed and only ever shows up as
 * a dependency TARGET. The matched side is a set rather than a counter
 * because one symbol is seen once per edge it touches; see
 * {@see tallyMatchedEnd()}.
 *
 * **Statelessness:** per CLAUDE.md "stateless rules" rule, all per-run state
 * (both hit maps and the shadow-evidence map) lives as LOCAL variables inside
 * {@see analyze()} (or its private helpers). Storing them as fields would leak
 * counts across `analyze()` calls because the rule executor reuses rule
 * instances.
 *
 * @qmx-ignore design.god-class
 *             reason="What remains after extracting OutsideLayerSummary, DeclaredLayerReachability and LayerRoutingGuidance is the per-edge verdict plus the evidence walks that feed them, which is the smallest this rule gets. LCOM counts the RuleInterface accessors as their own components and TCC is 0 for any rule that keeps per-run state in locals, as CLAUDE.md requires."
 */
#[CliAlias('layer-violation', 'enabled')]
#[CliAlias('layer-violation-severity', 'severity')]
#[CliAlias('layer-violation-unassigned-class', 'unassigned_class')]
final class LayerViolationRule extends AbstractRule
{
    public const string NAME = LayerPolicyPreparationInterface::PRODUCER_RULE_NAME;
    public const string DOCS_PAGE = 'rules/architecture.md';

    public const string COVERAGE_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME;

    public const string UNASSIGNED_CLASS_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME;

    public const string UNREACHABLE_LAYER_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::UNREACHABLE_LAYER_DIAGNOSTIC_NAME;

    public const string POTENTIAL_SHADOW_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::POTENTIAL_SHADOW_DIAGNOSTIC_NAME;

    public const string EMPTY_TEMPLATE_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::EMPTY_TEMPLATE_DIAGNOSTIC_NAME;

    public const string PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME;

    /**
     * The processor is injected by {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass::resolveExtraDependencies()}
     * via the {@see ArchitecturePolicy} alias registered in
     * {@see \Qualimetrix\Infrastructure\DependencyInjection\Configurator\ArchitectureConfigurator}.
     * Rules cannot use plain constructor autowiring (Critical Rule 7) so the
     * compiler-pass injection is the supported flow.
     */
    public function __construct(
        RuleOptionsInterface $options,
        private readonly ArchitecturePolicy $processor,
    ) {
        parent::__construct($options);
    }

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
     * Six of the seven channels report no magnitude: their emission sites pass
     * no `metricValue:` at all, so `occurrence` is the only shape left to
     * declare for them — the shape follows the emission, and reading it off
     * the call sites is the whole check.
     *
     * `architecture.unassigned-class` is the exception, and its shape is a
     * judgement call rather than a consequence; the argument for it lives with
     * the code that emits it, in {@see OutsideLayerSummary::unassignedClassChannel()}.
     *
     * `architecture.layer-violation` carries a dependency edge
     * (`dependencyTarget`/`dependencyType` on the `Violation` — see
     * {@see buildViolations()}), so per ADR 0017 its identity is per-edge; that
     * is an identity-layer concern the channel declaration itself does not
     * encode.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::occurrence(),
            (new ViolationChannel(self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME, self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME))->toKey()
                => OutsideLayerSummary::unassignedClassChannel(),
            (new ViolationChannel(self::COVERAGE_DIAGNOSTIC_NAME, self::COVERAGE_DIAGNOSTIC_NAME))->toKey()
                => ChannelDeclaration::configurationError(),
            (new ViolationChannel(self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME, self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME))->toKey()
                => ChannelDeclaration::configurationError(),
            (new ViolationChannel(self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME, self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME))->toKey()
                => ChannelDeclaration::configurationError(),
            (new ViolationChannel(self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME, self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME))->toKey()
                => ChannelDeclaration::configurationError(),
            (new ViolationChannel(self::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME, self::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME))->toKey()
                => ChannelDeclaration::configurationError(),
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

        $architecture = $this->processor->getPreparedConfiguration();
        if ($architecture === null || $architecture->isEmpty()) {
            return [];
        }

        // Graph binding already happened inside ArchitecturePolicy::prepare()
        // per ADR 0008 §2. The registry's ClassContextFactory therefore sees
        // the current run's graph; no rebind needed here.
        $registry = $architecture->registry();

        // Per-class evidence (local — never fields; statelessness regression in tests).
        // The source-side walk materialises its out-of-layer set for either
        // consumer independently: `coverage: ignore` with
        // `unassigned_class: warn` is the ordinary configuration for a project
        // that turned coverage off because of vendor edge ends, and gating the
        // walk on coverage alone would leave the new channel with no data.
        [$assignedHits, $matchedSymbols, $shadowEvidence, $uncoveredClasses, $analysedDeclarations] = $this->collectClassEvidence(
            $architecture,
            $context,
        );

        // Per-edge violations + coverage state (also local).
        $ownedTargets = OwnedLayerTargets::fromDeclarations($context->metrics->allDeclarations());
        [$edgeViolations, $coverageState, $edgeAssignedHits, $edgeMatchedSymbols] = $this->collectEdgeViolations($architecture, $context, $ownedTargets);
        $coverageState['classes'] += $uncoveredClasses;

        // A layer matched only as one end of a dependency edge (e.g. a vendor
        // namespace outside `paths:`, never a class in the analysed set) is
        // still "reached" — merge edge-side hits into the class-side hit maps
        // so `architecture.unreachable-layer` doesn't contradict
        // `architecture.layer-violation` about the very same layer.
        $assignedHits = $this->mergeHits($assignedHits, $edgeAssignedHits);
        $matchedSymbols = $this->mergeMatchedSymbols($matchedSymbols, $edgeMatchedSymbols);

        $definitions = $registry->definitions();

        return [
            ...$edgeViolations,
            ...OutsideLayerSummary::coverage($architecture->coverage(), $coverageState),
            ...OutsideLayerSummary::unassignedClasses(
                $this->options->unassignedClass,
                $uncoveredClasses,
                $analysedDeclarations,
            ),
            ...DeclaredLayerReachability::unreachableLayers($definitions, $assignedHits),
            ...DeclaredLayerReachability::pendingLayersMatched(
                $definitions,
                array_map(\count(...), $matchedSymbols),
            ),
            ...DeclaredLayerReachability::potentialShadows($shadowEvidence),
            ...DeclaredLayerReachability::emptyTemplates($architecture->emptyTemplateNames()),
        ];
    }

    /**
     * @param array<string, int> $into
     * @param array<string, int> $from
     *
     * @return array<string, int>
     */
    private function mergeHits(array $into, array $from): array
    {
        foreach ($from as $layerName => $count) {
            $into[$layerName] = ($into[$layerName] ?? 0) + $count;
        }

        return $into;
    }

    /**
     * Walks `metrics->all(SymbolType::Class_)` once and collects two local
     * structures used downstream:
     *
     * 1. `assignedHits` — per-layer count of classes that ended up in that
     *    layer (feeds `architecture.unreachable-layer`), and `matchedSymbols`
     *    — per-layer set of the distinct classes whose criteria the layer
     *    matched at all, winning or not (feeds
     *    `architecture.pending-layer-matched`, which is silent exactly where
     *    a layer matched nothing — see
     *    {@see DeclaredLayerReachability::pendingLayersMatched()}).
     * 2. `shadowEvidence` — per (assigned, shadowed) pair, list of evidence
     *    entries carrying the class FQN plus the specific criterion descriptors
     *    that matched on each side (feeds `architecture.potential-shadow`
     *    without re-walking the layer list at emission time). Descriptors
     *    carry the criterion kind (pattern / suffix / attribute / implements
     *    / extends) so the message can name the actual cause of the shadow.
     * 3. `uncoveredClasses` — canonical logical class key to display FQN for
     *    every analysed class outside all declared layers. Canonical keys make
     *    the later merge with dependency-edge coverage deterministic and
     *    deduplicate a class observed through both repository and graph views.
     *    Materialised only when {@see LayerViolationOptions::collectsOutsideLayerEvidence()}
     *    says a consumer exists, because the map is the size of the
     *    unclassified codebase.
     * 4. `analysedDeclarations` — how many class-like declarations the walk
     *    saw, the denominator `architecture.unassigned-class` reports its
     *    percentage against.
     *
     * `metrics->all(SymbolType::Class_)` enumerates what the collectors
     * recorded, and class scope opens on every `ClassLike` — interfaces,
     * traits and enums included. The blind spot is therefore a declaration no
     * collector recorded any class-level metric for: it is absent here and
     * counts as assigned.
     *
     * All of them are LOCAL variables here. Per CLAUDE.md "stateless rules", the
     * rule instance is reused across `analyze()` invocations — any field-based
     * accumulator would leak counts. The dedicated statelessness regression
     * test pins this contract.
     *
     * @return array{0: array<string, int>, 1: array<string, array<string, true>>, 2: array<string, array<string, list<array{fqn: string, assignedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion, shadowedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion}>>>, 3: array<string, string>, 4: int}
     */
    private function collectClassEvidence(
        ArchitectureConfiguration $architecture,
        AnalysisContext $context,
    ): array {
        \assert($this->options instanceof LayerViolationOptions);

        $registry = $architecture->registry();
        $materializeUncovered = $this->options->collectsOutsideLayerEvidence($architecture->coverage());

        $assignedHits = [];
        $matchedSymbols = [];
        foreach ($registry->layerNames() as $layerName) {
            $assignedHits[$layerName] = 0;
            $matchedSymbols[$layerName] = [];
        }

        /** @var array<string, array<string, list<array{fqn: string, assignedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion, shadowedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion}>>> $shadowEvidence */
        $shadowEvidence = [];
        $uncoveredClasses = [];
        $analysedDeclarations = 0;

        foreach ($context->metrics->all(SymbolType::Class_) as $classSymbol) {
            $analysedDeclarations++;
            $matches = $registry->resolveAll($classSymbol->symbolPath);
            if ($matches === []) {
                if ($materializeUncovered) {
                    $uncoveredClasses[$classSymbol->symbolPath->toCanonical()] = $classSymbol->symbolPath->toString();
                }

                continue;
            }

            $assigned = $matches[0];
            $assignedHits[$assigned->layerName] = ($assignedHits[$assigned->layerName] ?? 0) + 1;
            $matchedSymbols = $this->tallyMatchedEnd($matchedSymbols, $matches, $classSymbol->symbolPath->toCanonical());

            $matchCount = \count($matches);
            if ($matchCount === 1) {
                continue;
            }

            $classFqn = $classSymbol->symbolPath->toString();
            $assignedCriterion = $assigned->primaryCriterion();
            foreach (LayerShadowing::reportableShadows($matches) as $shadowed) {
                $shadowEvidence[$assigned->layerName][$shadowed->layerName][] = [
                    'fqn' => $classFqn,
                    'assignedCriterion' => $assignedCriterion,
                    'shadowedCriterion' => $shadowed->primaryCriterion(),
                ];
            }
        }

        return [$assignedHits, $matchedSymbols, $shadowEvidence, $uncoveredClasses, $analysedDeclarations];
    }

    /**
     * Walks the dependency graph and produces per-edge layer violations.
     * Returns the violation list, the coverage-state struct used by
     * `architecture.coverage` (counts of unmatched ends + the set of
     * unclassified class FQNs), a per-layer assignment count, and a per-layer
     * set of the distinct symbols matched at either end of an edge.
     *
     * The hit map exists because {@see collectClassEvidence()} only walks
     * `metrics->all(SymbolType::Class_)` — classes in the analysed path set.
     * A layer that matches exclusively outside that set (e.g. a vendor
     * namespace such as `ClickHouseDB\**`, reachable only as a dependency
     * TARGET) would otherwise always show zero hits and be reported
     * `architecture.unreachable-layer` even while `architecture.layer-violation`
     * reports a real edge into it — a self-contradictory diagnostic pair.
     * Merging edge-side hits into the class-side count in {@see analyze()}
     * fixes that without weakening unreachable-layer's typo-detection case:
     * a layer matching neither a class nor an edge end still gets zero hits.
     *
     * @return array{0: list<Violation>, 1: array{sourceEdges: int, targetEdges: int, classes: array<string, string>}, 2: array<string, int>, 3: array<string, array<string, true>>}
     */
    private function collectEdgeViolations(ArchitectureConfiguration $architecture, AnalysisContext $context, OwnedLayerTargets $ownedTargets): array
    {
        $violations = [];
        $sourceEdges = 0;
        $targetEdges = 0;
        $classes = [];
        $assignedHits = [];
        $matchedSymbols = [];

        $graph = $context->dependencyGraph;
        if ($graph === null) {
            return [$violations, ['sourceEdges' => 0, 'targetEdges' => 0, 'classes' => []], $assignedHits, $matchedSymbols];
        }

        $registry = $architecture->registry();
        foreach ($graph->getAllDependencies() as $dependency) {
            $fromMatches = $registry->resolveAll($dependency->sourceLogical());
            $toMatches = $registry->resolveAll($dependency->targetLogical());

            $fromMatch = $fromMatches[0] ?? null;
            $toMatch = $toMatches[0] ?? null;

            $matchedSymbols = $this->tallyMatchedEnd($matchedSymbols, $fromMatches, $dependency->sourceLogical()->toCanonical());
            $matchedSymbols = $this->tallyMatchedEnd($matchedSymbols, $toMatches, $dependency->targetLogical()->toCanonical());

            if ($fromMatch === null) {
                $sourceEdges++;
                $classes[$dependency->sourceLogical()->toCanonical()] = $dependency->sourceLogical()->toString();
            } else {
                $assignedHits[$fromMatch->layerName] = ($assignedHits[$fromMatch->layerName] ?? 0) + 1;
            }

            if ($toMatch === null) {
                $targetEdges++;
                $classes[$dependency->targetLogical()->toCanonical()] = $dependency->targetLogical()->toString();
            } else {
                $assignedHits[$toMatch->layerName] = ($assignedHits[$toMatch->layerName] ?? 0) + 1;
            }

            $edgeViolations = $this->buildViolations(
                $dependency,
                $fromMatch,
                $toMatch,
                $architecture,
                $ownedTargets->forLogical($dependency->targetLogical()),
            );
            foreach ($edgeViolations as $violation) {
                $violations[] = $violation;
            }
        }

        return [
            $violations,
            ['sourceEdges' => $sourceEdges, 'targetEdges' => $targetEdges, 'classes' => $classes],
            $assignedHits,
            $matchedSymbols,
        ];
    }

    /**
     * Records the symbol under every layer that matched it, not just the one
     * that won it — the predicate `architecture.pending-layer-matched` needs
     * and `architecture.unreachable-layer` deliberately does not.
     *
     * A set keyed by the symbol's canonical form rather than a counter,
     * because the same symbol is seen many times: once by the class walk and
     * once per dependency edge it sits at either end of. A counter therefore
     * reported edge multiplicity — two classes joined by four edges read as
     * eight — and `architecture.pending-layer-matched` printed a number that
     * answered no question anyone asks.
     *
     * @param array<string, array<string, true>> $matchedSymbols layer name => set of canonical symbols
     * @param list<LayerMatch> $matches
     * @param string $symbolKey The matched symbol in {@see \Qualimetrix\Core\Symbol\SymbolPath::toCanonical()} form.
     *
     * @return array<string, array<string, true>>
     */
    private function tallyMatchedEnd(array $matchedSymbols, array $matches, string $symbolKey): array
    {
        foreach ($matches as $match) {
            $matchedSymbols[$match->layerName][$symbolKey] = true;
        }

        return $matchedSymbols;
    }

    /**
     * @param array<string, array<string, true>> $into
     * @param array<string, array<string, true>> $from
     *
     * @return array<string, array<string, true>>
     */
    private function mergeMatchedSymbols(array $into, array $from): array
    {
        foreach ($from as $layerName => $symbols) {
            $into[$layerName] = ($into[$layerName] ?? []) + $symbols;
        }

        return $into;
    }

    /**
     * @param list<MetricSubject> $ownedTargets
     *
     * @return list<Violation>
     */
    private function buildViolations(
        Dependency $dependency,
        ?LayerMatch $fromMatch,
        ?LayerMatch $toMatch,
        ArchitectureConfiguration $architecture,
        array $ownedTargets,
    ): array {
        if ($fromMatch === null || $toMatch === null) {
            return [];
        }

        $fromLayer = $fromMatch->layerName;
        $toLayer = $toMatch->layerName;

        if ($architecture->policy()->isAllowed($fromLayer, $toLayer, $dependency->type)) {
            return [];
        }

        \assert($this->options instanceof LayerViolationOptions);

        return (new LayerViolationFinding(
            dependency: $dependency,
            fromMatch: $fromMatch,
            toMatch: $toMatch,
            ownedTargets: $ownedTargets,
            ruleName: self::NAME,
            severity: $this->options->severity,
            recommendation: LayerRoutingGuidance::forForbiddenEdge($dependency, $fromLayer, $toLayer, $architecture),
        ))->toViolations();
    }

}
