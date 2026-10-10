<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

/** Supplies the installed declaration spelling for an external PHP class identity. */
interface ExternalClassSpellingInterface
{
    public function declaredSpelling(string $className): ?string;
}
