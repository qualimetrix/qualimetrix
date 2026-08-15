<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface CouplingConfiguratorInterface
{
    /** @return list<string> */
    public function resolve(ConfigurationDocument $document): array;

    /** @param list<string> $frameworkNamespaces */
    public function replace(array $frameworkNamespaces): void;
}
