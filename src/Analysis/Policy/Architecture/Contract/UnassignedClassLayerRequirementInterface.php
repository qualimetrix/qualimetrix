<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;

/** The layer declaration required by an enabled unassigned-class mode. */
interface UnassignedClassLayerRequirementInterface
{
    /** Validates the completed options and enablement before file discovery. */
    public function assertSatisfied(FindingConfiguration $configuration): void;
}
