<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

interface CollectorRuntimeConfigurationStoreInterface
{
    public function current(): CollectorRuntimeConfiguration;

    public function replace(CollectorRuntimeConfiguration $configuration): void;

    public function reset(): void;
}
