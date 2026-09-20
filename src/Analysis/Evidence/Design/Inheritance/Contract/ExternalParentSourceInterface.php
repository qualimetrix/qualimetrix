<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract;

/**
 * The parent a class declares, read from the analysed project's own sources.
 *
 * The port belongs to Design because Design is what needs it. Placing a class
 * and reading its declaration are delivery -- a composer layout on one side, a
 * parser on the other -- so the implementation is an Infrastructure adapter and
 * this capability imports neither.
 *
 * Implementations read. They never load: `class_exists($fqcn, true)` includes
 * the file and runs its top-level code, which is what this port exists to stop.
 */
interface ExternalParentSourceInterface
{
    /**
     * Whether this run found an install to read at all.
     */
    public function isConfigured(): bool;

    public function parentOf(string $fqcn): ParentLookup;
}
