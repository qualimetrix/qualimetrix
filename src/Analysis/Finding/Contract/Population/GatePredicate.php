<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

interface GatePredicate
{
    public function evaluate(GateInput $input): ?string;
}
