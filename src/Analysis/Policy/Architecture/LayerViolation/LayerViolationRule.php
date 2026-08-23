<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;

/**
 * Reports what the *code* does wrong against the declared architecture policy:
 * a dependency edge the allow-list forbids, and how much of the analysed code
 * no layer claims.
 *
 * Under declaration-order matching (ADR 0006), a class is assigned to the
 * FIRST layer whose patterns match its FQN. Two channels come out of that:
 *
 * - `architecture.layer-violation` — per use-site, one violation per
 *   forbidden dependency edge.
 * - `architecture.unassigned-class` — the per-run count of analysed
 *   declarations outside every declared layer, gated by
 *   {@see LayerViolationOptions::$unassignedClass} and built by
 *   {@see UnassignedClassSummary}.
 *
 * The five verdicts on the *declaration* — coverage, unreachable layer,
 * pending layer matched, potential shadow, empty template — are not here.
 * They belong to {@see LayerDeclarationValidator}, which is a
 * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}
 * rather than a rule, because being a statement about the configuration is now
 * a property of the producer's type rather than a flag on a channel. The
 * validator runs in this rule's slot, under this rule's name, and answers to
 * this rule's options.
 *
 * **Statelessness:** per CLAUDE.md "stateless rules", nothing this rule
 * computes survives an `analyze()` call — the executor reuses rule instances.
 * The one shared per-run structure, {@see LayerEvidence}, lives in
 * {@see LayerEvidenceCollector} keyed weakly by the run's own
 * {@see AnalysisContext}, so it cannot leak into the next run either.
 */
#[CliAlias('layer-violation', 'enabled')]
#[CliAlias('layer-violation-severity', 'severity')]
#[CliAlias('layer-violation-unassigned-class', 'unassigned_class')]
final class LayerViolationRule extends AbstractRule
{
    public const string NAME = LayerPolicyPreparationInterface::PRODUCER_RULE_NAME;
    public const string DOCS_PAGE = 'rules/architecture.md';

    public const int REMEDIATION_MINUTES = 15;

    public const string UNASSIGNED_CLASS_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME;

    /**
     * The collector is injected by {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass::resolveExtraDependencies()}.
     * Rules cannot use plain constructor autowiring (Critical Rule 7) so the
     * compiler-pass injection is the supported flow.
     */
    public function __construct(
        RuleOptionsInterface $options,
        private readonly LayerEvidenceCollector $evidence,
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
     * `architecture.layer-violation` reports no magnitude — its emission site
     * passes no `metricValue:` at all — so `occurrence` is the only shape left
     * to declare for it. It carries a dependency edge
     * (`dependencyTarget`/`dependencyType` on the `Violation` — see
     * {@see buildViolations()}), so per ADR 0017 its identity is per-edge; that
     * is an identity-layer concern the channel declaration itself does not
     * encode.
     *
     * `architecture.unassigned-class` is the exception in shape, and that
     * shape is a judgement call rather than a consequence; the argument for it
     * lives with the code that emits it, in
     * {@see UnassignedClassSummary::unassignedClassChannel()}.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::occurrence(SymbolLevel::Class_),
            (new ViolationChannel(self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME, self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME))->toKey()
                => UnassignedClassSummary::unassignedClassChannel(),
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof LayerViolationOptions);

        $evidence = $this->evidence->collect($context);
        if ($evidence === null) {
            return [];
        }

        $ownedTargets = OwnedLayerTargets::fromDeclarations($context->metrics->allDeclarations());

        return [
            ...$this->buildViolations($evidence, $ownedTargets),
            ...UnassignedClassSummary::unassignedClasses(
                $this->options->unassignedClass,
                $evidence->uncoveredClasses(),
                $evidence->analysedDeclarations(),
            ),
        ];
    }

    /**
     * @return list<Violation>
     */
    private function buildViolations(LayerEvidence $evidence, OwnedLayerTargets $ownedTargets): array
    {
        \assert($this->options instanceof LayerViolationOptions);

        $violations = [];

        foreach ($evidence->forbiddenEdges as $edge) {
            $dependency = $edge['dependency'];
            $fromLayer = $edge['fromMatch']->layerName;
            $toLayer = $edge['toMatch']->layerName;

            $edgeViolations = (new LayerViolationFinding(
                dependency: $dependency,
                fromMatch: $edge['fromMatch'],
                toMatch: $edge['toMatch'],
                ownedTargets: $ownedTargets->forLogical($dependency->targetLogical()),
                ruleName: self::NAME,
                severity: $this->options->severity,
                recommendation: LayerRoutingGuidance::forForbiddenEdge($dependency, $fromLayer, $toLayer, $evidence->architecture),
            ))->toViolations();

            foreach ($edgeViolations as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }
}
