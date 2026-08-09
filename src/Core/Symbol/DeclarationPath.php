<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Durable identity of one source declaration.
 *
 * The optional ordinal is assigned only within a same-logical, same-file,
 * same-start-position collision group.
 */
final readonly class DeclarationPath
{
    public function __construct(
        public SymbolPath $logical,
        public RelativePath $file,
        public int $startFilePos,
        public ?int $ordinal = null,
    ) {
        if (!\in_array($logical->getType(), [SymbolType::Class_, SymbolType::Method, SymbolType::Function_], true)) {
            throw new InvalidArgumentException('Declaration logical symbol must identify a class, method, or function');
        }

        if ($startFilePos < 0) {
            throw new InvalidArgumentException('Declaration start file position must not be negative');
        }

        if ($ordinal !== null && $ordinal < 0) {
            throw new InvalidArgumentException('Declaration collision ordinal must not be negative');
        }
    }

    public function toCanonical(): string
    {
        $canonical = \sprintf(
            'declaration:%s@%s:%d',
            $this->logical->toCanonical(),
            $this->file->value(),
            $this->startFilePos,
        );

        return $this->ordinal === null ? $canonical : $canonical . '#' . $this->ordinal;
    }
}
