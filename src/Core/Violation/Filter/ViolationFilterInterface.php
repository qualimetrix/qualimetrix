<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation\Filter;

use Qualimetrix\Core\Violation\Violation;

interface ViolationFilterInterface
{
    /**
     * Determines if violation should be included in report.
     */
    public function shouldInclude(Violation $violation): bool;
}
