<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidenceCollector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The verdict on the layer *declaration*: five ways a `layers:` block can fail
 * to describe the code it is supposed to describe.
 *
 * Its sibling {@see LayerViolationRule} judges the code — a forbidden edge is
 * debt a project can record and pay down. Nothing here is: a layer that can
 * never be reached, a pending layer that already matches, a layer shadowed by
 * a broader one declared earlier, an empty template, and a declaration with a
 * hole in its coverage are all statements that the configuration and the code
 * have drifted apart. Which is why the two are different types rather than one
 * class with a flag on some of its channels.
 *
 * Both read one
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidence},
 * produced once per run by the shared {@see LayerEvidenceCollector}: `coverage` needs the coverage state,
 * `unreachable-layer` the merged assignment hits and the symbols each layer
 * could still own while the run could not decide them, `pending-layer-matched` the
 * merged match sets, `potential-shadow` the class-walk shadow evidence, and
 * `empty-template` only the configuration.
 */
final class LayerDeclarationValidator implements ConfigurationValidatorInterface
{
    public const string COVERAGE_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME;

    public const string UNREACHABLE_LAYER_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::UNREACHABLE_LAYER_DIAGNOSTIC_NAME;

    public const string POTENTIAL_SHADOW_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::POTENTIAL_SHADOW_DIAGNOSTIC_NAME;

    public const string EMPTY_TEMPLATE_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::EMPTY_TEMPLATE_DIAGNOSTIC_NAME;

    public const string PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME = LayerPolicyPreparationInterface::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME;

    /**
     * The producer's options as well as the walk, because the walk now runs
     * for either producer of the family (ADR 0030): "is there evidence" and
     * "may this validator report" became two questions, and the five
     * declaration verdicts belong to `architecture.layer-violation`, so they
     * answer to its `enabled`.
     */
    public function __construct(
        private readonly LayerEvidenceCollector $evidence,
        private readonly LayerViolationOptions $options,
    ) {}

    public static function producerRuleName(): string
    {
        return LayerPolicyPreparationInterface::PRODUCER_RULE_NAME;
    }

    /**
     * Shared with {@see LayerViolationRule}, the rule this validator belongs
     * to: registry assembly refuses the two declaring different shapes under
     * one producer name.
     */
    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    /**
     * Five occurrences: none of the emission sites passes a `metricValue:`,
     * so there is no magnitude to declare a direction for. That every one of
     * them is a configuration error is not said here — it follows from this
     * class implementing {@see ConfigurationValidatorInterface}, and is
     * stamped where the registry is assembled.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        $descriptions = [
            self::COVERAGE_DIAGNOSTIC_NAME => 'Reports analysed classes and dependency edges that belong to no declared layer.',
            self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME => 'Reports a declared layer whose criteria matched no class and no dependency-edge end.',
            self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME => 'Reports a more specific layer declared after a broader one that takes its classes first.',
            self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME => 'Reports a template layer that expanded to no concrete instance.',
            self::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME => 'Reports a layer declared pending whose criteria now match code.',
        ];

        $declarations = [];
        foreach ($descriptions as $name => $description) {
            $declarations[$name] = ChannelDeclaration::occurrence(SymbolLevel::Project)->describedAs($description);
        }

        return $declarations;
    }

    /**
     * The emission order is the one the single `analyze()` produced before the
     * split, and it is load-bearing: reports that do not sort — SARIF among
     * them — publish findings in production order.
     *
     * **Two verdicts need the whole project.** `unreachable-layer` and
     * `empty-template` say that no class matches a declaration, which is a
     * fact about the pair (configuration, run scope): a run narrowed below the
     * project's autoload roots cannot tell a layer that matches nothing from
     * one whose classes are outside the slice, and both fail the run. They are
     * withheld there, on the predicate `architecture.unmatched-exclude` reads,
     * and the report's project scope names them as not judged. A project whose
     * manifest declares no readable production autoload is judged: its paths
     * are the project, and a typo in a layer there is an error as it was
     * before the gate existed. The other three draw only on what the run did read — a
     * gap, a pending layer's match, a shadow between two matches of one
     * analysed class — and stay true of any slice.
     *
     * @return list<Finding>
     */
    public function validate(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $evidence = $this->evidence->collect($context);

        if ($evidence === null) {
            return [];
        }

        $definitions = $evidence->architecture->registry()->definitions();
        $judgesAbsence = $context->coversProjectScope;

        return [
            ...DeclaredLayerReachability::coverage($evidence->architecture->coverage(), $evidence->coverageState),
            ...($judgesAbsence ? DeclaredLayerReachability::unreachableLayers(
                $definitions,
                $evidence->reachedCounts(),
                $evidence->contests(),
            ) : []),
            ...DeclaredLayerReachability::pendingLayersMatched($definitions, $evidence->matchedCounts()),
            ...PotentialShadowDiagnostic::forShadows($evidence->shadowEvidence),
            ...($judgesAbsence ? DeclaredLayerReachability::emptyTemplates($evidence->architecture->emptyTemplateNames()) : []),
        ];
    }
}
