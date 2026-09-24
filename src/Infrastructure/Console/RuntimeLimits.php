<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

final readonly class RuntimeLimits
{
    public function __construct(public ?string $memoryLimit = null)
    {
        // Zero has the shape of a size and PHP refuses it at `ini_set()`, where
        // the refusal no longer knows which value it is about. A leading zero
        // is read as octal by PHP (`010M` is 8 MB), so it is not a size either.
        if ($memoryLimit !== null && preg_match('/^(?:-1|[1-9][0-9]*[KMG]?)$/i', $memoryLimit) !== 1) {
            throw ConfigurationRefusal::aboutResolvedInput(
                \sprintf(
                    'Invalid memory_limit "%s". Expected a positive size in bytes without leading zeros, optionally with a K, M, or G suffix, or -1 for no limit.',
                    $memoryLimit,
                ),
                ConfigSchema::MEMORY_LIMIT,
            );
        }
    }
}
