<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ExternalClassSpellingInterface;

/** Reads an external class identity as authored in the analysed Composer install. */
final readonly class InstalledExternalClassSpelling implements ExternalClassSpellingInterface
{
    public function __construct(private DeclaredSupertypeReader $declarations) {}

    public function declaredSpelling(string $className): ?string
    {
        return $this->declarations->supertypesOf($className)->declaredSpelling;
    }
}
