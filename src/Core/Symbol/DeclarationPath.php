<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Durable identity of one source declaration.
 *
 * The ordinal discriminates declarations that share a logical identity inside
 * one file. It is a rank, not a position, so editing text above a declaration
 * leaves its identity alone. There is no default: dropping the argument during
 * a migration must not silently mean "the first one".
 */
final readonly class DeclarationPath
{
    private function __construct(
        public SymbolPath $logical,
        public RelativePath $file,
        public DeclarationOrdinal $ordinal,
    ) {
        if (!\in_array($logical->getType(), [SymbolType::Class_, SymbolType::Method, SymbolType::Function_], true)) {
            throw new InvalidArgumentException('Declaration logical symbol must identify a class, method, or function');
        }
    }

    public static function of(SymbolPath $logical, RelativePath $file, DeclarationOrdinal $ordinal): self
    {
        return new self($logical, $file, $ordinal);
    }

    public function toCanonical(): string
    {
        $canonical = \sprintf(
            'declaration:%s@%s',
            $this->logical->toCanonical(),
            $this->file->value(),
        );

        return $this->ordinal->isFirst() ? $canonical : $canonical . '#' . $this->ordinal->value;
    }
}
