<?php

declare(strict_types=1);

/**
 * The defect floor, and the two different questions it answers on the two
 * halves of the before/after pair.
 *
 * The floor is a statement about the CLASSIFIER reading a known pre-cure tree:
 * `01-promise.md` says "the snapshot BEFORE, read through the registry, must
 * call defective position 64, position 66 and the 21 rows of
 * `non-rules-roots.tsv`". Applied to the frozen half it holds that claim, and
 * a classifier that stopped recognising one of those rows reddens.
 *
 * Applied to the LIVE grid the same list says something else entirely, and for
 * one round it said the wrong thing: a successful cure REMOVES a defect, so
 * every row the round repaired became a floor miss and a cured product exited
 * 1. A floor that goes red when the work succeeds is not a floor.
 *
 * So the live grid is held to a different and stronger claim, declared in the
 * `cure` column of `promise-effect/floor.tsv`:
 *
 *   - a row with no `cure` must STILL be a defect — the floor's original job;
 *   - a row with a `cure` must NOT be a defect any more — the round's own
 *     claim about what it repaired, checked rather than asserted.
 *
 * Both directions redden. A row that quietly stops being defective without a
 * declared cure is the silent-success case that used to hide inside a red
 * floor; a row declared cured and still defective is a false claim about the
 * round, which is worse than either.
 */

namespace Qualimetrix\PromiseEffect;

final readonly class FloorRow
{
    public function __construct(
        public string $row,
        public string $verdict,
        public string $source,
        public string $cure,
    ) {}

    public function declaredCured(): bool
    {
        return $this->cure !== '';
    }
}

final class Floor
{
    private const string PATH = 'promise-effect/floor.tsv';

    /** @param list<FloorRow> $rows */
    private function __construct(public readonly array $rows) {}

    public static function load(string $root): self
    {
        $lines = file($root . '/' . self::PATH, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::PATH);
        }

        $rows = [];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, "row\t")) {
                continue;
            }

            $cells = array_pad(explode("\t", $line), 4, '');
            $rows[] = new FloorRow($cells[0], $cells[1], $cells[2], $cells[3]);
        }

        return new self($rows);
    }

    /**
     * The floor as `01-promise.md` states it, over the pre-cure half: every
     * declared row is a defect there, cured or not. The `cure` column is not
     * read here — a row the round repaired was still a defect on the tree the
     * frozen half measures, and reading the cure would make this half agree
     * with the after half by construction.
     *
     * @param list<Cell> $cells
     *
     * @return list<string>
     */
    public function missesOnTheFrozenHalf(array $cells): array
    {
        $misses = [];

        foreach ($this->judge($cells) as [$row, $held, $actual]) {
            if (!$held) {
                $misses[] = $row->row . ': the pre-cure half must call this ' . $row->verdict . ', it reads ' . $actual;
            }
        }

        return $misses;
    }

    /**
     * The live grid against the round's own claim about what it cured.
     *
     * @param list<Cell> $cells
     *
     * @return array{list<string>, list<FloorRow>, list<FloorRow>} misses, the rows still defective, the rows cured as declared
     */
    public function cureMisses(array $cells): array
    {
        $misses = [];
        $standing = [];
        $cured = [];

        foreach ($this->judge($cells) as [$row, $held, $actual]) {
            if ($row->declaredCured()) {
                $held
                    ? $misses[] = $row->row . ': declared cured by ' . $row->cure . ', and the grid still calls it ' . $actual
                    : $cured[] = $row;

                continue;
            }

            $held
                ? $standing[] = $row
                : $misses[] = $row->row . ': left the floor — it reads ' . $actual . ' and no `cure` column claims it was repaired';
        }

        return [$misses, $standing, $cured];
    }

    /**
     * @param list<Cell> $cells
     *
     * @return list<array{FloorRow, bool, string}> the row, whether the floor's expectation holds, and what the grid reads
     */
    private function judge(array $cells): array
    {
        $seen = [];

        foreach ($cells as $cell) {
            $seen[$cell->key] = [$cell->verdict, $cell->defect];
        }

        $judged = [];

        foreach ($this->rows as $row) {
            if (!isset($seen[$row->row])) {
                // A floor row the grid does not carry at all is neither held
                // nor cured: it is a stale declaration, and it says so in the
                // words of whichever half asked.
                $judged[] = [$row, false, 'no such row in the grid'];

                continue;
            }

            [$verdict, $defect] = $seen[$row->row];
            $held = $row->verdict === 'any-defect' ? $defect : $verdict === $row->verdict;
            $judged[] = [$row, $held, $verdict . ($defect ? ' (a defect)' : ' (not a defect)')];
        }

        return $judged;
    }
}
