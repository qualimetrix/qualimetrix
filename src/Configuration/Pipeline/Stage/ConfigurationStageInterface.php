<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline\Stage;

use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationLayer;

interface ConfigurationStageInterface
{
    /**
     * Stage priority. Higher = executed later = higher priority.
     */
    public function priority(): int;

    /**
     * Unique stage name (for diagnostics and plugins).
     */
    public function name(): string;

    /**
     * Applies the stage to the context.
     *
     * @return ConfigurationLayer|null null if the stage is not applicable
     */
    public function apply(ConfigurationContext $context): ?ConfigurationLayer;
}
