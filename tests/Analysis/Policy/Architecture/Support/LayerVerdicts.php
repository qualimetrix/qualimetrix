<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Support;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerEvidenceCollector;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;

/**
 * Both layer verdicts over one shared walk, in the order the executor runs
 * them.
 *
 * The seven layer channels used to come out of a single `analyze()`; they now
 * come from a rule and a configuration validator that occupy the same slot.
 * A test asking "what does this policy report" wants both halves, and wants
 * them concatenated the way {@see \Qualimetrix\Analysis\Finding\RuleExecution}
 * concatenates them — otherwise it would pin an order the product does not
 * produce.
 */
final class LayerVerdicts
{
    private readonly LayerViolationRule $rule;

    private readonly LayerDeclarationValidator $validator;

    public function __construct(LayerViolationOptions $options, ArchitecturePolicy $processor)
    {
        $collector = new LayerEvidenceCollector($options, $processor);
        $this->rule = new LayerViolationRule($options, $collector);
        $this->validator = new LayerDeclarationValidator($collector);
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        return [
            ...$this->rule->analyze($context),
            ...$this->validator->validate($context),
        ];
    }
}
