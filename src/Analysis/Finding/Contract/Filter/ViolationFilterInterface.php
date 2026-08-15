<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Violation;

interface ViolationFilterInterface
{
    /**
     * Determines if violation should be included in report.
     */
    public function shouldInclude(Violation $violation): bool;
}
