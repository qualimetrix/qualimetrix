<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

final readonly class GitScopeResult
{
    /**
     * @param list<string> $paths
     * @param list<string> $namespaces
     */
    public function __construct(public array $paths, public array $namespaces) {}
}
