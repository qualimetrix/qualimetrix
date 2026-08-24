<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;

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
 * Both read one {@see LayerEvidence}, produced once per run by the shared
 * {@see LayerEvidenceCollector}: `coverage` needs the coverage state,
 * `unreachable-layer` the merged assignment hits, `pending-layer-matched` the
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
        $keys = [
            self::COVERAGE_DIAGNOSTIC_NAME,
            self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
            self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
            self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
            self::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME,
        ];

        $declarations = [];
        foreach ($keys as $name) {
            $declarations[(new ViolationChannel($name, $name))->toKey()] = ChannelDeclaration::occurrence(SymbolLevel::Project);
        }

        return $declarations;
    }

    /**
     * The emission order is the one the single `analyze()` produced before the
     * split, and it is load-bearing: reports that do not sort — SARIF among
     * them — publish findings in production order.
     *
     * @return list<Violation>
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

        return [
            ...DeclaredLayerReachability::coverage($evidence->architecture->coverage(), $evidence->coverageState),
            ...DeclaredLayerReachability::unreachableLayers($definitions, $evidence->assignedHits),
            ...DeclaredLayerReachability::pendingLayersMatched($definitions, $evidence->matchedCounts()),
            ...DeclaredLayerReachability::potentialShadows($evidence->shadowEvidence),
            ...DeclaredLayerReachability::emptyTemplates($evidence->architecture->emptyTemplateNames()),
        ];
    }
}
