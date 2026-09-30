<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

use Qualimetrix\Core\Path\AbsolutePath;

interface ComposerManifestReaderInterface
{
    /** One snapshot per canonical root for the current invocation, including failures. */
    public function read(AbsolutePath $root): ComposerManifestFacts;
}
