<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface CouplingConfiguratorInterface
{
    public function resolve(ConfigurationDocument $document): FrameworkNamespaces;

    public function replace(FrameworkNamespaces $frameworkNamespaces): void;
}
