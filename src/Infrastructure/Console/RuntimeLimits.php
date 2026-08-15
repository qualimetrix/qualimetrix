<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;

final readonly class RuntimeLimits
{
    public function __construct(public ?string $memoryLimit = null)
    {
        if ($memoryLimit !== null && preg_match('/^(?:-1|[0-9]+[KMG]?)$/i', $memoryLimit) !== 1) {
            throw new InvalidArgumentException(
                \sprintf('Invalid memory_limit "%s". Expected bytes or a K, M, or G suffix.', $memoryLimit),
            );
        }
    }
}
