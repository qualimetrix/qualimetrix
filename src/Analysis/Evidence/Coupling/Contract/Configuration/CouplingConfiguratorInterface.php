<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Pattern\NamespacePattern;

interface CouplingConfiguratorInterface
{
    /** @return list<NamespacePattern> */
    public function resolve(ConfigurationDocument $document): array;

    /** @param list<NamespacePattern> $frameworkNamespaces */
    public function replace(array $frameworkNamespaces): void;
}
