<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverage\RunRuleCoverage;

/**
 * What one reporting projection invocation asks the finding pipeline to do.
 *
 * @qmx-threshold coupling.cbo 21 -- Fifteen projection stages and adapters read this request, and each of its five typed parts is a field a stage needs; the finite ceiling still reports a sixth part or a wider readership.
 */
final readonly class FindingProjectionOptions
{
    public function __construct(
        public ?BaselineDocument $baselineDocument = null,
        public ConfiguredFindingExclusions $exclusions = new ConfiguredFindingExclusions(),
        public bool $annotationSuppressionDisabled = false,
        public ?GitScopeRequest $gitScope = null,
        public ?RunCoverage $runCoverage = null,
        public ?RunRuleCoverage $ruleCoverage = null,
    ) {}

    public function withRunCoverage(RunCoverage $coverage, RunRuleCoverage $ruleCoverage): self
    {
        return new self(
            baselineDocument: $this->baselineDocument,
            exclusions: $this->exclusions,
            annotationSuppressionDisabled: $this->annotationSuppressionDisabled,
            gitScope: $this->gitScope,
            runCoverage: $coverage,
            ruleCoverage: $ruleCoverage,
        );
    }
}
