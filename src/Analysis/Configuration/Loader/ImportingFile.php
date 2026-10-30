<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;

/**
 * The file whose `imports` list is being resolved: how it is named in a
 * refusal and which directories its entries are read from and named by.
 */
final readonly class ImportingFile
{
    public function __construct(
        public ConfigurationOrigin $origin,
        /** Where its entries are read from: the directory of the file itself, through a symlink to it. */
        public string $realDirectory,
        /** What its entries are named from: the directory of its own name, `.` for a bare name. */
        public string $displayDirectory,
        /** The identity key of the file, see {@see ImportTarget::$key}. */
        public string $key,
    ) {}
}
