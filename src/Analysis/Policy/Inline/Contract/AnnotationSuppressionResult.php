<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use Qualimetrix\Analysis\Finding\Contract\Finding;

final readonly class AnnotationSuppressionResult
{
    /**
     * @param list<Finding> $retained
     * @param list<Finding> $suppressed
     */
    public function __construct(public array $retained, public array $suppressed) {}
}
