<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

interface GitScopeQueryInterface
{
    public function resolve(GitScopeRequest $request): GitScopeResult;
}
