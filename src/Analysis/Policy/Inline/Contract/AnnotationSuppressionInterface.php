<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;

interface AnnotationSuppressionInterface
{
    /**
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions
     */
    public function apply(array $findings, array $suppressions): AnnotationSuppressionResult;
}
