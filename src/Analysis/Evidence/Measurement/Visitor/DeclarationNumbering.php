<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use LogicException;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationKey;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Turns where the traversal stands into the key a declaration is numbered by.
 *
 * The numbering itself belongs to the index the traversal owner handed over;
 * what lives here is the one rule that says which key a class, an unnamed
 * class-like declaration or a callable is grouped under. Keeping that rule
 * apart from the lexical stacks is what stops "how we number" and "where we
 * are" from being edited as one thing.
 */
final class DeclarationNumbering
{
    private ?FileDeclarationIndex $index = null;

    public function useIndex(FileDeclarationIndex $index): void
    {
        $this->index = $index;
    }

    public function forClass(?string $namespace, string $name, int $startFilePos): DeclarationOrdinal
    {
        return $this->ordinalOf(DeclarationKey::forLogical(SymbolPath::forClass($namespace ?? '', $name)), $startFilePos);
    }

    /**
     * An unnamed class-like declaration cannot be grouped by its own name: the
     * name is minted from the number being asked for.
     */
    public function forUnnamedClassLike(int $startFilePos): DeclarationOrdinal
    {
        return $this->ordinalOf(DeclarationKey::forUnnamedClassLike(), $startFilePos);
    }

    public function forCallable(?string $namespace, ?string $class, string $member, CallableKind $kind, int $startFilePos): DeclarationOrdinal
    {
        $logical = $class !== null && \in_array($kind, [CallableKind::Method, CallableKind::PropertyHook], true)
            ? SymbolPath::forMethod($namespace ?? '', $class, $member)
            : SymbolPath::forGlobalFunction($namespace ?? '', $member);

        return $this->ordinalOf(DeclarationKey::forLogical($logical), $startFilePos);
    }

    private function ordinalOf(DeclarationKey $key, int $startFilePos): DeclarationOrdinal
    {
        $index = $this->index
            ?? throw new LogicException('Declaration numbering requires the file declaration index of the current traversal');

        return $index->ordinalOf($key, $startFilePos);
    }
}
