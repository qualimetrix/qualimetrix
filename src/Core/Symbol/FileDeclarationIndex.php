<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

/**
 * Sole owner of declaration numbering for one file of one traversal.
 *
 * The rank of a position never changes when a later position is registered, so
 * a producer may ask while the traversal is still running and gets the same
 * answer it would get afterwards.
 *
 * An unregistered pair is answered, not rejected: reaching it means the asking
 * producer and the registrar tracked lexical context differently, which costs
 * one misattributed measurement today and must not escalate to killing the run
 * on a legal user file. Answering registers the pair, which cannot disturb what
 * another producer is told: a position always comes from the node itself, so a
 * producer whose lexical context diverged asks under a different *key*, and a
 * key the registrar did see already holds every position of that file.
 */
final class FileDeclarationIndex
{
    /** @var array<string, array<int, true>> */
    private array $positions = [];

    public function register(DeclarationKey $key, int $startFilePos): void
    {
        if ($startFilePos < 0) {
            throw new InvalidArgumentException('Declaration start file position must not be negative');
        }

        $this->positions[$key->value][$startFilePos] = true;
    }

    public function ordinalOf(DeclarationKey $key, int $startFilePos): DeclarationOrdinal
    {
        $this->register($key, $startFilePos);

        $earlier = array_filter(
            array_keys($this->positions[$key->value]),
            static fn(int $position): bool => $position < $startFilePos,
        );

        return DeclarationOrdinal::fromRank(\count($earlier));
    }
}
