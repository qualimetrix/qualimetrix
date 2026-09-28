<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

interface ConfigLoaderInterface
{
    /**
     * The document as written, for the engine, beside its folded values.
     *
     * @throws ConfigurationRefusal If the document cannot be read as a whole
     */
    public function read(string $path): LoadedDocument;

    /**
     * Returns whether this loader supports the given path.
     */
    public function supports(string $path): bool;
}
