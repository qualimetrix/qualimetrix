<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Occurrence-preserving order measurements shared by judgment and derivation. */
final class RankingOrder
{
    /**
     * Among maximum-length subsequences, choose the lexicographically smallest
     * sequence of (referenceIndex, candidateIndex) pairs. The suffix table and
     * monotone reference scan use O(p*q) time and space; equal labels retain multiplicity.
     *
     * @param list<string> $reference
     * @param list<string> $candidate
     *
     * @return array{lcs:list<array{referenceIndex:int,candidateIndex:int}>,moved:list<array{label:string,referencePosition:int,candidatePosition:int,occurrence:int}>}
     */
    public static function measure(array $reference, array $candidate): array
    {
        $p = \count($reference);
        $q = \count($candidate);
        $lengths = array_fill(0, $p + 1, array_fill(0, $q + 1, 0));
        for ($i = $p - 1; $i >= 0; --$i) {
            for ($j = $q - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $reference[$i] === $candidate[$j]
                    ? 1 + $lengths[$i + 1][$j + 1]
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $remaining = $lengths[0][0];
        $lcs = [];
        $i = 0;
        $j = 0;
        while ($remaining > 0) {
            $found = false;
            for (; $i < $p; ++$i) {
                for ($at = $j; $at < $q; ++$at) {
                    if ($reference[$i] === $candidate[$at] && $lengths[$i + 1][$at + 1] === $remaining - 1) {
                        $lcs[] = ['referenceIndex' => $i, 'candidateIndex' => $at];
                        ++$i;
                        $j = $at + 1;
                        --$remaining;
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                throw new GateError('The ranked subsequence table has no selected continuation.');
            }
        }
        $left = $reference;
        $right = $candidate;
        foreach ($lcs as $pair) {
            unset($left[$pair['referenceIndex']], $right[$pair['candidateIndex']]);
        }
        $moved = [];
        $occurrences = [];
        foreach ($left as $index => $label) {
            $at = array_search($label, $right, true);
            if ($at === false) {
                throw new GateError('Ranked order measurements require equal occurrence populations.');
            }
            $occurrences[$label] = ($occurrences[$label] ?? 0) + 1;
            $moved[] = ['label' => $label, 'referencePosition' => $index + 1, 'candidatePosition' => $at + 1, 'occurrence' => $occurrences[$label]];
            unset($right[$at]);
        }
        if ($right !== []) {
            throw new GateError('Ranked order measurements require equal occurrence populations.');
        }
        return ['lcs' => $lcs, 'moved' => $moved];
    }
}
