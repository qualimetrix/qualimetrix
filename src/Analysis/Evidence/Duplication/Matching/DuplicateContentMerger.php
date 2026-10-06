<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Closure;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;

/** Joins verified segments with identical complete token content. */
final class DuplicateContentMerger
{
    /**
     * @param array<int, TokenStream> $streams
     * @param Closure(list<int>, int): ?list<int> $reportableCopies
     */
    public function __construct(
        private readonly array $streams,
        private readonly Closure $reportableCopies,
    ) {}

    public function merge(DuplicateMatchCandidates $segments): DuplicateMatchCandidates
    {
        /** @var list<array{length: int, first: int, copies: list<int>}> $groups */
        $groups = [];
        /** @var array<string, list<int>> $byHash */
        $byHash = [];

        foreach ($segments->matches() as [$length, $copies]) {
            $first = $copies[0];
            $hash = self::contentHash($this->tokensAt($first), PackedPosition::offset($first), $length);
            $matched = $this->matchingGroup($groups, $byHash[$hash] ?? [], $first, $length);
            if ($matched === null) {
                $byHash[$hash][] = \count($groups);
                $groups[] = ['length' => $length, 'first' => $first, 'copies' => $copies];
                continue;
            }
            $group = &$groups[$matched];
            foreach ($copies as $copy) {
                $group['copies'][] = $copy;
            }
            unset($group);
        }

        return $this->reportableGroups($groups);
    }

    /**
     * @param list<array{length: int, first: int, copies: list<int>}> $groups
     * @param list<int> $bucket
     */
    private function matchingGroup(array $groups, array $bucket, int $first, int $length): ?int
    {
        foreach ($bucket as $index) {
            if ($groups[$index]['length'] === $length && $this->sameContent($first, $groups[$index]['first'], $length)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{length: int, first: int, copies: list<int>}> $groups */
    private function reportableGroups(array $groups): DuplicateMatchCandidates
    {
        $merged = new DuplicateMatchCandidates();
        foreach ($groups as $group) {
            $copies = self::sortedUnique($group['copies']);
            $reportable = ($this->reportableCopies)($copies, $group['length']);
            if ($reportable !== null) {
                $merged->add($group['length'], $reportable);
            }
        }

        return $merged;
    }

    /** @param list<int> $copies
     * @return list<int>
     */
    private static function sortedUnique(array $copies): array
    {
        sort($copies, \SORT_NUMERIC);
        $unique = [];
        $previous = null;
        foreach ($copies as $copy) {
            if ($copy !== $previous) {
                $unique[] = $copy;
                $previous = $copy;
            }
        }

        return $unique;
    }

    private function sameContent(int $left, int $right, int $length): bool
    {
        $leftValues = $this->tokensAt($left)->values;
        $rightValues = $this->tokensAt($right)->values;
        $leftOffset = PackedPosition::offset($left);
        $rightOffset = PackedPosition::offset($right);

        for ($index = 0; $index < $length; $index++) {
            if ($leftValues[$leftOffset + $index] !== $rightValues[$rightOffset + $index]) {
                return false;
            }
        }

        return true;
    }

    private function tokensAt(int $packed): TokenStream
    {
        return $this->streams[PackedPosition::fileIndex($packed)];
    }

    public static function contentHash(TokenStream $tokens, int $offset, int $length): string
    {
        $values = \array_slice($tokens->values, $offset, $length);

        return hash('sha256', json_encode(
            ['tokenCount' => $length, 'tokens' => $values],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        ));
    }
}
