<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverage\RunRuleCoverage;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

/** What one reporting projection invocation asks the finding pipeline to do. */
final readonly class FindingProjectionOptions
{
    /**
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     */
    public function __construct(
        public ?BaselineDocument $baselineDocument = null,
        public array $suppressPaths = [],
        public array $suppressNamespaces = [],
        public bool $annotationSuppressionDisabled = false,
        public ?GitScopeRequest $gitScope = null,
        public ?RunCoverage $runCoverage = null,
        public ?RunRuleCoverage $ruleCoverage = null,
    ) {}

    public function withRunCoverage(RunCoverage $coverage, RunRuleCoverage $ruleCoverage): self
    {
        return new self(
            baselineDocument: $this->baselineDocument,
            suppressPaths: $this->suppressPaths,
            suppressNamespaces: $this->suppressNamespaces,
            annotationSuppressionDisabled: $this->annotationSuppressionDisabled,
            gitScope: $this->gitScope,
            runCoverage: $coverage,
            ruleCoverage: $ruleCoverage,
        );
    }
}
