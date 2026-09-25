<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

/**
 * The port through which the owner of a configuration root declares it: the
 * root's canonical key and the schema of its value. The engine recognises,
 * shapes and merges the section by that declaration; the owner reads the
 * result from the resolved document.
 */
interface DocumentSectionSchemaInterface
{
    /** Canonical root key: lowercase words joined by `_` or `-`. */
    public function key(): string;

    public function schema(): NodeSchema;
}
