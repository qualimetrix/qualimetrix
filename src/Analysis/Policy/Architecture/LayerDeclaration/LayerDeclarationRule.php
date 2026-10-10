<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration;

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
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidenceCollector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Reports observed gaps between declared layer criteria and their effects. */
final class LayerDeclarationRule extends AbstractRule
{
    public const string NAME = ArchitectureChannels::LAYER_DECLARATION_PRODUCER_NAME;
    public const string DOCS_PAGE = 'rules/architecture.md';
    public const int REMEDIATION_MINUTES = 15;
    public const ChannelShape SHAPE = ChannelShape::Occurrence;

    public function __construct(RuleOptionsInterface $options, private readonly LayerEvidenceCollector $evidence)
    {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Reports declared layer criteria whose observed effects differ from the declaration.';
    }

    /** @return class-string<LayerDeclarationOptions> */
    public static function getOptionsClass(): string
    {
        return LayerDeclarationOptions::class;
    }

    /** @return array<string, ChannelDeclaration> */
    public static function channelDeclarations(): array
    {
        return [
            ArchitectureChannels::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME => ChannelDeclaration::occurrence(SymbolLevel::Project)
                ->describedAs('Reports a layer\'s exclude clause that removed no class while the layer\'s own criteria matched some.')
                ->withGates(new PopulationGate('namespace-scope', new FindingChannel(ArchitectureChannels::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME), SymbolLevel::Project, 'declared-exclude-clause', new ContextGuard('namespaceClaimsJudged'), 'Namespace absence cannot be judged.'), ...UnmatchedExcludeDiagnostic::populationGates()),
            ArchitectureChannels::DOUBTED_ASSIGNMENT_DIAGNOSTIC_NAME => ChannelDeclaration::occurrence(SymbolLevel::Project)
                ->describedAs('Counts the symbols whose layer assignment is in doubt because a layer criterion could not be answered about them.')
                ->withGates(new PopulationGate('prepared-evidence', new FindingChannel(ArchitectureChannels::DOUBTED_ASSIGNMENT_DIAGNOSTIC_NAME), SymbolLevel::Project, 'invocation', new ContextGuard('preparedEvidenceAvailable'), 'Prepared layer evidence is unavailable.')),
            ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME => ChannelDeclaration::occurrence(SymbolLevel::Project)
                ->describedAs('Reports classes taken from a reachable non-pattern layer by an earlier layer.')
                ->withGates(new PopulationGate('prepared-evidence', new FindingChannel(ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME), SymbolLevel::Project, 'precedence-pair', new ContextGuard('preparedEvidenceAvailable'), 'Prepared layer evidence is unavailable.', 'invocation')),
            ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME => ChannelDeclaration::occurrence(SymbolLevel::Project)
                ->describedAs('Reports an authored layer type that the complete run did not meet.')->withGates(...UnmatchedTypeDiagnostic::populationGates()),
        ];
    }

    /** @return list<Finding> */
    public function analyze(AnalysisContext $context): array
    {
        $evidence = $this->evidence->collect($context);
        $declarations = self::channelDeclarations();
        $doubted = ArchitectureChannels::DOUBTED_ASSIGNMENT_DIAGNOSTIC_NAME;
        $overlap = ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME;
        $context->admit(self::NAME, new FindingChannel($doubted), SymbolLevel::Project, PopulationIdentity::invocation(self::NAME), $declarations[$doubted], [GateInput::context('preparedEvidenceAvailable', $evidence !== null)]);
        if ($evidence === null) {
            $context->admit(self::NAME, new FindingChannel($overlap), SymbolLevel::Project, PopulationIdentity::invocation(self::NAME), $declarations[$overlap], [GateInput::context('preparedEvidenceAvailable', false)]);
            return [];
        }
        foreach ($evidence->architecture->registry()->layerNames() as $later) {
            foreach ($evidence->lostByPrecedence($later) as $pair) {
                $context->admit(self::NAME, new FindingChannel($overlap), SymbolLevel::Project, PopulationIdentity::selector(json_encode([$later, $pair['earlier']], \JSON_THROW_ON_ERROR), 'precedence-pair'), $declarations[$overlap], [GateInput::context('preparedEvidenceAvailable', true)]);
            }
        }

        return [
            ...LayerOverlapDiagnostic::forPrecedence($evidence),
            ...UnmatchedTypeDiagnostic::forEvidence($evidence, $context->projectScope, $context, $declarations[ArchitectureChannels::UNMATCHED_TYPE_DIAGNOSTIC_NAME]),
            ...UnmatchedExcludeDiagnostic::forInertClauses($evidence, ArchitectureChannels::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME, $context, $declarations[ArchitectureChannels::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME]),
            ...DoubtedAssignmentDiagnostic::forDoubts(
                $evidence->coverageState,
                $evidence->undecidedSymbolsByLayer(),
                $evidence->ownsIfExcludedSymbolsByLayer(),
                ArchitectureChannels::DOUBTED_ASSIGNMENT_DIAGNOSTIC_NAME,
            ),
        ];
    }
}
