<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion\Contract;

interface LcomCollectionConfigurationStoreInterface
{
    public function replace(LcomCollectionConfiguration $configuration): void;
    public function current(): LcomCollectionConfiguration;
    public function reset(): void;
}
