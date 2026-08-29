<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;

interface FindingFilterInterface
{
    /**
     * Determines if finding should be included in report.
     */
    public function shouldInclude(Finding $finding): bool;
}
