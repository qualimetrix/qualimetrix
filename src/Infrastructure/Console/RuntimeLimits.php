<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;

final readonly class RuntimeLimits
{
    public function __construct(public ?string $memoryLimit = null)
    {
        if ($memoryLimit !== null && preg_match('/^(?:-1|[0-9]+[KMG]?)$/i', $memoryLimit) !== 1) {
            throw ConfigurationRefusal::aboutInput(
                ConfigurationOrigin::of(ConfigurationSource::Resolved, ConfigSchema::MEMORY_LIMIT),
                \sprintf('Invalid memory_limit "%s". Expected bytes or a K, M, or G suffix.', $memoryLimit),
            );
        }
    }
}
