<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;

/** What one reporting projection invocation asks the finding pipeline to do. */
final readonly class FindingProjectionOptions
{
    /**
     * @param list<string> $suppressPaths
     * @param list<string> $suppressNamespaces
     */
    public function __construct(
        public ?string $baselinePath = null,
        public array $suppressPaths = [],
        public array $suppressNamespaces = [],
        public bool $annotationSuppressionDisabled = false,
        public ?GitScopeRequest $gitScope = null,
    ) {}
}
