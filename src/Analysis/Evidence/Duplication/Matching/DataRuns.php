<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Token\TokenStream;

/**
 * Maximal runs of data-declaration tokens per stream, built on first use.
 *
 * Scanning the mask once per member and match length made data suppression
 * cubic on one large literal table; a run lookup is logarithmic in the
 * stream's declaration count.
 */
final class DataRuns
{
    /** @var array<int, array{list<int>, list<int>}> fileIdx → run starts and exclusive ends */
    private array $runs = [];

    /**
     * @param array<int, TokenStream> $streams
     */
    public function __construct(private readonly array $streams) {}

    /**
     * First token at or after the position that is not data: the position
     * itself when its token is executable code.
     */
    public function endAt(int $packed): int
    {
        $fileIndex = PackedPosition::fileIndex($packed);
        $offset = PackedPosition::offset($packed);
        [$starts, $ends] = $this->runs[$fileIndex] ??= self::scan($this->streams[$fileIndex]->dataMask);

        $low = 0;
        $high = \count($starts) - 1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            if ($starts[$middle] <= $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $high >= 0 && $offset < $ends[$high] ? $ends[$high] : $offset;
    }

    /** @return array{list<int>, list<int>} */
    private static function scan(string $mask): array
    {
        $starts = [];
        $ends = [];
        $length = \strlen($mask);
        $position = strcspn($mask, '1');
        while ($position < $length) {
            $run = strspn($mask, '1', $position);
            $starts[] = $position;
            $ends[] = $position + $run;
            $position += $run;
            if ($position < $length) {
                $position += strcspn($mask, '1', $position);
            }
        }

        return [$starts, $ends];
    }
}
