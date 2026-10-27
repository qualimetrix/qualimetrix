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
        return $this->ordinalOf(DeclarationKey::forLogical(self::callablePath($namespace, $class, $member, $kind)), $startFilePos);
    }

    /**
     * The logical path every projection of a callable is read from: its
     * numbering key, its stored declaration, its wire subject and the key of
     * the per-visitor metric maps.
     *
     * Only a named member belongs to its class. An anonymous callable stays a
     * function-shaped path even when it is declared inside a class: its
     * enclosing names travel in its minted member, so its identity says where
     * it is without making it a member of anything.
     */
    public static function callablePath(?string $namespace, ?string $class, string $member, CallableKind $kind): SymbolPath
    {
        return $class !== null && \in_array($kind, [CallableKind::Method, CallableKind::PropertyHook], true)
            ? SymbolPath::forMethod($namespace ?? '', $class, $member)
            : SymbolPath::forGlobalFunction($namespace ?? '', $member);
    }

    private function ordinalOf(DeclarationKey $key, int $startFilePos): DeclarationOrdinal
    {
        $index = $this->index
            ?? throw new LogicException('Declaration numbering requires the file declaration index of the current traversal');

        return $index->ordinalOf($key, $startFilePos);
    }
}
