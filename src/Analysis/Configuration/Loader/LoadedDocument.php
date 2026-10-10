<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;

/** One configuration file as written, before owner declarations judge it. */
final readonly class LoadedDocument
{
    public function __construct(public AuthoredNode $authored) {}
}
