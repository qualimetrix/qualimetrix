<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Generator;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Observation\EdgeEvidenceWalk;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidence;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidenceCollector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Reports forbidden dependency edges against the prepared layer policy. */
#[CliAlias('layer-violation', 'enabled')]
#[CliAlias('layer-violation-severity', 'severity')]
final class LayerViolationRule extends AbstractRule
{
    public const string NAME = ArchitectureChannels::PRODUCER_RULE_NAME;
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

    public static function getDescription(): string
    {
        return 'Detects dependencies between layers that are not explicitly allowed by the architecture policy.';
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
            self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Class_)->readingRunEvidence()->withGates(
                new PopulationGate('prepared-evidence', new FindingChannel(self::NAME), SymbolLevel::Class_, 'dependency-edge', new ContextGuard('preparedEvidenceAvailable'), 'Prepared layer evidence is unavailable.', 'invocation'),
                ...EdgeEvidenceWalk::populationGates(),
            ),

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
            $context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::invocation(self::NAME), self::channelDeclarations()[self::NAME], (static function (): Generator {
                yield GateInput::context('preparedEvidenceAvailable', false);
            })());
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
            $dependency = $edge->dependency;
            $fromLayer = $edge->fromMatch->layerName;
            $toLayer = $edge->toMatch->layerName;

            $edgeFindings = (new LayerViolationFinding(
                dependency: $dependency,
                fromMatch: $edge->fromMatch,
                toMatch: $edge->toMatch,
                ownedTargets: $ownedTargets->forLogical($dependency->targetLogical()),
                ruleName: self::NAME,
                severity: $this->options->severity,
                recommendation: LayerRoutingGuidance::forForbiddenEdge($fromLayer, $evidence->architecture),
            ))->toFindings();

            foreach ($edgeFindings as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
