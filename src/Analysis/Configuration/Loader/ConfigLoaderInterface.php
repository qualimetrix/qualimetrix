<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

interface ConfigLoaderInterface
{
    /**
     * Read the physical document as written for the declared schema; every
     * refusal names `$origin`, importer chain included.
     *
     * @throws ConfigurationRefusal If the document cannot be read as a whole
     */
    public function read(string $physicalPath, ConfigurationOrigin $origin): LoadedDocument;
}
