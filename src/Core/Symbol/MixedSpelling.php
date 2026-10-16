<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

final readonly class MixedSpelling
{
    private const array KINDS = [
        'namespace' => true,
        'class' => true,
        'external' => true,
    ];

    /**
     * @param 'namespace'|'class'|'external' $kind
     * @param non-empty-list<string> $spellings
     */
    public function __construct(
        public string $kind,
        public array $spellings,
        public string $canonical,
    ) {
        if (!isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('Mixed spelling kind must be namespace, class, or external');
        }
        if (\count(array_unique($spellings)) < 2) {
            throw new InvalidArgumentException('Mixed spelling evidence requires at least two distinct spellings');
        }

        $folded = array_unique(array_map(ClassNameSpelling::fold(...), $spellings));
        if (\count($folded) !== 1) {
            throw new InvalidArgumentException('Mixed spellings must identify the same PHP class name');
        }
        if (ClassNameSpelling::fold($canonical) !== $folded[0]) {
            throw new InvalidArgumentException('Mixed spelling canonical name must identify the same PHP class name');
        }
        if ($kind !== 'external' && $canonical !== ClassNameSpelling::canonical($spellings)) {
            throw new InvalidArgumentException('Mixed spelling canonical name must be the byte-minimum spelling');
        }
    }
}
