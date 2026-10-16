<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Core\Symbol\MixedSpelling;

/** Canonical logical identities and the spelling conflicts observed while folding them. */
final readonly class CanonicalGraphInput
{
    /**
     * @param list<Dependency> $dependencies
     * @param list<ClassLikeDeclaration> $declarations
     * @param list<MixedSpelling> $mixedSpellings
     */
    public function __construct(
        public array $dependencies,
        public array $declarations,
        public array $mixedSpellings,
    ) {}
}
