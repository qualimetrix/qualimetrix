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
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassRule;

/**
 * Every layer verdict over one shared walk, in the order the executor runs
 * them.
 *
 * The seven layer channels used to come out of a single `analyze()`; they now
 * come from two rules and a configuration validator that occupy the same slot.
 * A test asking "what does this policy report" wants all of it, concatenated
 * the way {@see \Qualimetrix\Analysis\Finding\RuleExecution} concatenates it
 * — otherwise it would pin an order the product does not produce.
 *
 * The two options objects are the two gates the walk answers to. The
 * unassigned-class gate defaults to its own default rather than to something
 * this harness invents, so a test that says nothing about it gets the
 * behaviour a project that says nothing about it gets.
 */
final class LayerVerdicts
{
    private readonly LayerViolationRule $rule;

    private readonly UnassignedClassRule $unassignedClassRule;

    private readonly LayerDeclarationValidator $validator;

    public function __construct(
        LayerViolationOptions $options,
        ArchitecturePolicy $processor,
        ?UnassignedClassOptions $unassignedClassOptions = null,
    ) {
        $unassignedClassOptions ??= new UnassignedClassOptions();
        $collector = new LayerEvidenceCollector($options, $unassignedClassOptions, $processor);
        $this->rule = new LayerViolationRule($options, $collector);
        $this->unassignedClassRule = new UnassignedClassRule($unassignedClassOptions, $collector);
        $this->validator = new LayerDeclarationValidator($collector, $options);
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        return [
            ...$this->rule->analyze($context),
            ...$this->unassignedClassRule->analyze($context),
            ...$this->validator->validate($context),
        ];
    }
}
