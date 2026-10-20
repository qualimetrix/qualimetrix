<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

use Qualimetrix\Core\Symbol\MixedSpelling;

/** Graph plus the spelling conflicts observed while its identities were folded. */
final readonly class DependencyGraphBuild
{
    /** @param list<MixedSpelling> $mixedSpellings */
    public function __construct(
        public DependencyGraphInterface $graph,
        public array $mixedSpellings,
    ) {}
}
