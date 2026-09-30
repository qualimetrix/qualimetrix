<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

interface ConfigLoaderInterface
{
    /**
     * Read the physical document for the engine beside its folded values;
     * sourceName identifies that document in refusals.
     *
     * @throws ConfigurationRefusal If the document cannot be read as a whole
     */
    public function read(string $physicalPath, string $sourceName): LoadedDocument;

    /**
     * Returns whether this loader supports the given path.
     */
    public function supports(string $path): bool;
}
