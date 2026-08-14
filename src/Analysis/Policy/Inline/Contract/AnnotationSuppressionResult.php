<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use Qualimetrix\Analysis\Finding\Contract\Violation;

final readonly class AnnotationSuppressionResult
{
    /**
     * @param list<Violation> $retained
     * @param list<Violation> $suppressed
     */
    public function __construct(public array $retained, public array $suppressed) {}
}
