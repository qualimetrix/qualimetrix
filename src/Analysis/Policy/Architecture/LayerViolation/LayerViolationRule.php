<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Reports what the *code* does wrong against the declared architecture policy:
 * a dependency edge the allow-list forbids.
 *
 * Under declaration-order matching (ADR 0006), a class is assigned to the
 * FIRST layer whose patterns match its FQN. One channel comes out of that:
 * `architecture.layer-violation`, per use-site, one finding per forbidden
 * dependency edge.
 *
 * How much of the analysed code no layer claims is a fact about the run
 * rather than about one edge, and belongs to {@see UnassignedClassRule}. It
 * reads the same {@see LayerEvidenceCollector}, so the two rules still share
 * one traversal.
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
final class LayerViolationRule extends AbstractRule
{
    public const string NAME = LayerPolicyPreparationInterface::PRODUCER_RULE_NAME;
    public const string DOCS_PAGE = 'rules/architecture.md';

    public const int REMEDIATION_MINUTES = 15;

    public const ChannelShape SHAPE = ChannelShape::Occurrence;

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
     * (`dependencyTarget`/`dependencyType` on the `Finding` — see
     * {@see buildFindings()}), so per ADR 0017 its identity is per-edge; that
     * is an identity-layer concern the channel declaration itself does not
     * encode.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Class_),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof LayerViolationOptions);

        // Own gate, because the shared walk now runs for either producer: the
        // collector answers "is there evidence", not "may this rule report".
        if (!$this->options->isEnabled()) {
            return [];
        }

        $evidence = $this->evidence->collect($context);
        if ($evidence === null) {
            return [];
        }

        $ownedTargets = OwnedLayerTargets::fromDeclarations($context->metrics->allDeclarations());

        return $this->buildFindings($evidence, $ownedTargets);
    }

    /**
     * @return list<Finding>
     */
    private function buildFindings(LayerEvidence $evidence, OwnedLayerTargets $ownedTargets): array
    {
        \assert($this->options instanceof LayerViolationOptions);

        $findings = [];

        foreach ($evidence->forbiddenEdges as $edge) {
            $dependency = $edge['dependency'];
            $fromLayer = $edge['fromMatch']->layerName;
            $toLayer = $edge['toMatch']->layerName;

            $edgeFindings = (new LayerViolationFinding(
                dependency: $dependency,
                fromMatch: $edge['fromMatch'],
                toMatch: $edge['toMatch'],
                ownedTargets: $ownedTargets->forLogical($dependency->targetLogical()),
                ruleName: self::NAME,
                severity: $this->options->severity,
                recommendation: LayerRoutingGuidance::forForbiddenEdge($dependency, $fromLayer, $toLayer, $evidence->architecture),
            ))->toFindings();

            foreach ($edgeFindings as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
