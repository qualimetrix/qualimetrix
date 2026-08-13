<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;

interface ConfigLoaderInterface
{
    /**
     * Loads configuration from the given path.
     *
     *
     * @throws ConfigLoadException If the configuration cannot be loaded
     *
     * @return array<string, mixed>
     */
    public function load(string $path): array;

    /**
     * Returns whether this loader supports the given path.
     */
    public function supports(string $path): bool;
}
