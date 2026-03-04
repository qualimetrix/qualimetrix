<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Violation\Filter;

use AiMessDetector\Core\Violation\Violation;

interface ViolationFilterInterface
{
    /**
     * Determines if violation should be included in report.
     */
    public function shouldInclude(Violation $violation): bool;
}
