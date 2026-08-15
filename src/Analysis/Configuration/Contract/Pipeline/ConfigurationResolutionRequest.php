<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Pipeline;

use Qualimetrix\Core\Path\AbsolutePath;

/** Immutable ingress values required to resolve one configuration document. */
final readonly class ConfigurationResolutionRequest
{
    /**
     * @param list<string> $presetNames
     * @param array<string, mixed> $cliValues
     */
    public function __construct(
        public AbsolutePath $workingDirectory,
        public ?string $configFilePath = null,
        public array $presetNames = [],
        public array $cliValues = [],
    ) {}
}
