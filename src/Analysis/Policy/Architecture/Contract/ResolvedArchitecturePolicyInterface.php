<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

/** Opaque Architecture-owned result of pure policy configuration resolution. */
interface ResolvedArchitecturePolicyInterface
{
    /** @return list<ArchitectureConfigurationWarning> */
    public function warnings(): array;
}
