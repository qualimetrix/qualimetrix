<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Configuration;

use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;

final readonly class FindingConfiguration
{
    public function __construct(
        public RuleOptionsDocument $ruleOptions,
        public FindingCliOverrides $cliOverrides,
        public RuleSelection $selection,
    ) {}
}
