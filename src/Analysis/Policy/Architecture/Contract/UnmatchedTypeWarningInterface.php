<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;

/** Explains why this run could not judge its unresolved layer type names. */
interface UnmatchedTypeWarningInterface
{
    public function notJudgedWarning(ProjectScopeJudgement $scope): ?string;
}
