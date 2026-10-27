<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;

/**
 * Captured run policy used by every zone of one walk, and the survey the walk
 * adds the removed code's namespaces to.
 */
final readonly class WalkRequest
{
    public function __construct(
        public RunConfiguration $run,
        public RemovedNamespaceSurvey $removedNamespaces = new RemovedNamespaceSurvey(),
    ) {}
}
