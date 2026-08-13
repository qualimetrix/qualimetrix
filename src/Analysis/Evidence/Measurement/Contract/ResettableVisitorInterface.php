<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

/**
 * Interface for visitors that need to reset state between files.
 *
 * @qmx-threshold coupling.cbo 23 -- Measurement visitors intentionally share this lifecycle promise; current raw CBO 22 gets one-edge headroom.
 */
interface ResettableVisitorInterface
{
    /**
     * Resets visitor state for processing a new file.
     */
    public function reset(): void;
}
