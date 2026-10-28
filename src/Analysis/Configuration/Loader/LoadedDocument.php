<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Core\FileTarget\FileIdentity;

/**
 * One configuration file as written, before owner declarations judge it.
 *
 * `file` is the identity of the file read; null when it could not be stat'ed
 * after the read, so nothing can be told to be the same file as it.
 */
final readonly class LoadedDocument
{
    public function __construct(
        public AuthoredNode $authored,
        public ?FileIdentity $file = null,
    ) {}
}
