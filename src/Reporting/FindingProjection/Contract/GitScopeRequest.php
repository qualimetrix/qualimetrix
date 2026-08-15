<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

use Qualimetrix\Core\Path\AbsolutePath;

final readonly class GitScopeRequest
{
    public function __construct(public string $reference, public AbsolutePath $projectRoot, public bool $includeParentNamespaces) {}
}
