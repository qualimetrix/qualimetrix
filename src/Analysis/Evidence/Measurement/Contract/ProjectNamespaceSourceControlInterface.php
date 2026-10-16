<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts;

/** Bind namespace attribution to the current analysed project's source facts. */
interface ProjectNamespaceSourceControlInterface
{
    public function bind(ComposerManifestFacts $facts): void;
}
