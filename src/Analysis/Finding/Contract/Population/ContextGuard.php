<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;

final readonly class ContextGuard implements GatePredicate
{
    private const array FACTS = [
        'graphAvailable', 'preparedEvidenceAvailable', 'namespaceClaimsJudged', 'frameworkClassifiedNonempty',
        'namedTypesJudged', 'suppressionPathJudged', 'suppressionNamespaceJudged', 'excludeVerdictJudged',
        'directiveScopeMeasured', 'edgeSourceAssigned', 'edgeTargetAssigned', 'directiveProducerRan',
        'namespaceCoordinateKnown', 'callableHasClassContext', 'excludeClauseActive',
    ];

    public function __construct(public string $source, public bool $allowOppositeSuppressionTag = false)
    {
        if (!\in_array($source, self::FACTS, true)
            || ($allowOppositeSuppressionTag && !\in_array($source, ['suppressionPathJudged', 'suppressionNamespaceJudged'], true))) {
            throw new LogicException('Unknown declared population context fact.');
        }
    }

    public function evaluate(GateInput $input): ?string
    {
        if ($this->allowOppositeSuppressionTag && $input->variant === 'context'
            && \in_array($input->source, ['suppressionPathJudged', 'suppressionNamespaceJudged'], true)
            && $input->source !== $this->source) {
            return null;
        }
        $input->requireVariant('context', $this->source);
        return $input->bound === true ? null : 'Required population context was not judged.';
    }
}
