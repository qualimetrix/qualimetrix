<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;

interface ConfigurationStageInterface
{
    public function priority(): int;

    public function name(): string;

    public function apply(ConfigurationContext $context): ?ConfigurationLayer;
}
