<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Support;

use Qualimetrix\Analysis\Finding\Contract\Finding;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\LayerDeclarationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\LayerDeclarationRule;
use Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidenceCollector;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassOptions;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassRule;

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

    private readonly LayerDeclarationRule $declarationRule;

    private readonly LayerDeclarationOptions $declarationOptions;

    public function __construct(
        LayerViolationOptions $options,
        ArchitecturePolicy $processor,
        ?UnassignedClassOptions $unassignedClassOptions = null,
        ?LayerDeclarationOptions $declarationOptions = null,
    ) {
        $declarationOptions ??= new LayerDeclarationOptions();
        $this->declarationOptions = $declarationOptions;
        $unassignedClassOptions ??= new UnassignedClassOptions();
        $collector = new LayerEvidenceCollector($options, $unassignedClassOptions, $declarationOptions, $processor);
        $this->rule = new LayerViolationRule($options, $collector);
        $this->unassignedClassRule = new UnassignedClassRule($unassignedClassOptions, $collector);
        $this->declarationRule = new LayerDeclarationRule($declarationOptions, $collector);
        $this->validator = new LayerDeclarationValidator($collector);
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        return [
            ...$this->rule->analyze($context),
            ...($this->declarationOptions->isEnabled() ? $this->declarationRule->analyze($context) : []),
            ...($this->declarationOptions->isEnabled() ? $this->validator->validate($context) : []),
            ...$this->unassignedClassRule->analyze($context),
        ];
    }
}
