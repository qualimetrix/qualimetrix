<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;

interface AnnotationSuppressionInterface
{
    /**
     * @param list<Violation> $violations
     * @param array<string, list<Suppression>> $suppressions
     */
    public function apply(array $violations, array $suppressions): AnnotationSuppressionResult;
}
