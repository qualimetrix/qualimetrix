<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ExternalClassSpellingInterface;

/** Used until composition supplies installed package declarations. */
final class UnplacedExternalClassSpelling implements ExternalClassSpellingInterface
{
    public function declaredSpelling(string $className): ?string
    {
        return null;
    }
}
