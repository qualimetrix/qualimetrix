<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

/**
 * What {@see FileDeclarationIndex} groups positions by.
 *
 * An unnamed class-like declaration cannot be grouped by its own name: the name
 * is minted from the ordinal being asked for. It is grouped by a synthetic
 * file-wide key instead, so its rank is its order among the unnamed class-like
 * declarations of the file.
 */
final readonly class DeclarationKey
{
    private const string UNNAMED_CLASS_LIKE = 'unnamed-class-like:';

    private function __construct(public string $value) {}

    public static function forLogical(SymbolPath $logical): self
    {
        return new self($logical->toCanonical());
    }

    public static function forUnnamedClassLike(): self
    {
        return new self(self::UNNAMED_CLASS_LIKE);
    }
}
