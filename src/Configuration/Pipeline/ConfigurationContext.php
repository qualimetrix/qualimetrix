<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Context for stages - pipeline input data.
 */
final readonly class ConfigurationContext
{
    public function __construct(
        public InputInterface $input,
        public string $workingDirectory,
    ) {}
}
