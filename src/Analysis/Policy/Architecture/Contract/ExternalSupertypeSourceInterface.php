<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

/**
 * Reads class-like declaration facts from the analysed project's Composer install.
 *
 * Implementations parse source files as data and never load them.
 */
interface ExternalSupertypeSourceInterface
{
    public function isConfigured(): bool;

    public function supertypesOf(string $fqcn): ExternalSupertypes;
}
