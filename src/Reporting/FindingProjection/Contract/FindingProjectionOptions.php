<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
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
     * @param array<string, ConfiguredValueWriters> $pathWriters writers of each `$suppressPaths` value, keyed by its
     *                                                           display; a value with no entry has unknown writers
     * @param array<string, ConfiguredValueWriters> $namespaceWriters keyed likewise
     */
    public function __construct(
        public ?BaselineDocument $baselineDocument = null,
        public array $suppressPaths = [],
        public array $suppressNamespaces = [],
        public bool $annotationSuppressionDisabled = false,
        public ?GitScopeRequest $gitScope = null,
        public ?RunCoverage $runCoverage = null,
        public ?RunRuleCoverage $ruleCoverage = null,
        public array $pathWriters = [],
        public array $namespaceWriters = [],
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
            pathWriters: $this->pathWriters,
            namespaceWriters: $this->namespaceWriters,
        );
    }
}
