<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command\Baseline;

use Qualimetrix\Analysis\Policy\Baseline\Entry\Baseline;

/**
 * What `BaselineCommand::measureAgainstBaseline()` hands back once the scope
 * guard has passed (ADR 0017): the context of the run just measured, paired
 * with the baseline file it was measured against.
 */
final readonly class LoadedBaselineRun
{
    public function __construct(
        public BaselineRunContext $context,
        public Baseline $baseline,
    ) {}
}
