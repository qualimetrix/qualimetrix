<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Baseline\Filter\BaselineFilter;
use Qualimetrix\Core\Violation\Violation;

/**
 * Result of the violation filter pipeline.
 */
final readonly class ViolationFilterResult
{
    /**
     * @param list<Violation> $violations
     * @param list<string> $staleBaselineKeys
     */
    public function __construct(
        public array $violations,
        public int $baselineFiltered,
        public int $suppressionFiltered,
        public int $pathExclusionFiltered,
        public int $gitScopeFiltered,
        public ?BaselineFilter $baselineFilter,
        public array $staleBaselineKeys,
        public int $staleBaselineCount,
    ) {}
}
