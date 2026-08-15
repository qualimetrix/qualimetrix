<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion\Contract;

interface LcomCollectionConfigurableInterface
{
    public function applyLcomCollectionConfiguration(LcomCollectionConfiguration $configuration): void;
}
