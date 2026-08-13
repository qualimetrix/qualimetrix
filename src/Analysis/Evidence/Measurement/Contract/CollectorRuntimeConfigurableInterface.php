<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

interface CollectorRuntimeConfigurableInterface
{
    public function applyRuntimeConfiguration(CollectorRuntimeConfiguration $configuration): void;
}
