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
 *
 * There is a THIRD way a row can leave the floor, and it says nothing about
 * the product at all: the probe that produced the pre-cure verdict was itself
 * wrong, and the corrected probe cannot see the row. That is the `withdrawn`
 * column. It is kept apart from `cure` on purpose — a cure is a claim about
 * the product and is evidence of repair; a withdrawal is a claim about the
 * STAND and is evidence of nothing. Writing one as the other would be the
 * worse lie of the two, because it would read as proof.
 */

namespace Qualimetrix\PromiseEffect;

final readonly class FloorRow
{
    public function __construct(
        public string $row,
        public string $verdict,
        public string $source,
        public string $cure,
        public string $withdrawn = '',
    ) {}

    public function declaredCured(): bool
    {
        return $this->cure !== '';
    }

    public function declaredWithdrawn(): bool
    {
        return $this->withdrawn !== '';
    }

    /**
     * The text the grid's `decided_by` must carry for the withdrawal to be the
     * one declared: everything before the first `: ` of the column. A
     * withdrawal that only said "not observable any more" would be satisfied
     * by ANY blindness, including one that arrives later for a different
     * reason — which is how a disposition becomes a silencer.
     */
    public function withdrawalEvidence(): string
    {
        return trim(explode(': ', $this->withdrawn, 2)[0]);
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

            $cells = array_pad(explode("\t", $line), 5, '');
            $row = new FloorRow($cells[0], $cells[1], $cells[2], $cells[3], $cells[4]);

            if ($row->declaredCured() && $row->declaredWithdrawn()) {
                throw new LedgerError('floor.tsv: ' . $row->row . ' is declared both cured and withdrawn, and those are claims about different things');
            }

            $rows[] = $row;
        }

        return new self($rows);
    }

    /**
     * The live grid against the round's own claim about what it cured.
     *
     * @param list<Cell> $cells
     *
     * @return array{list<string>, list<FloorRow>, list<FloorRow>, list<FloorRow>} misses, the rows still defective, the rows cured as declared, the rows withdrawn as declared
     */
    public function cureMisses(array $cells): array
    {
        $misses = [];
        $standing = [];
        $cured = [];
        $withdrawn = [];

        foreach ($this->judge($cells) as [$row, $held, $actual, $decidedBy]) {
            if ($row->declaredWithdrawn()) {
                // A withdrawal claims the STAND lost the row, so the cell must
                // read NOT OBSERVABLE and for the declared reason. Still a
                // defect refutes it outright; anything the stand DOES observe
                // refutes it too, or `withdrawn` would be a licence to remove
                // any row from the floor.
                $evidence = $row->withdrawalEvidence();

                if ($held) {
                    $misses[] = $row->row . ': declared withdrawn (' . $row->withdrawn . '), and the grid still calls it ' . $actual;
                } elseif (!str_starts_with($actual, Verdict::NOT_OBSERVABLE)) {
                    $misses[] = $row->row . ': declared withdrawn, which claims the stand can no longer see it, and the grid reads ' . $actual;
                } elseif ($evidence !== '' && !str_contains($decidedBy, $evidence)) {
                    $misses[] = $row->row . ': declared withdrawn for `' . $evidence . '`, and the grid is blind for another reason: ' . $decidedBy;
                } else {
                    $withdrawn[] = $row;
                }

                continue;
            }

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

        return [$misses, $standing, $cured, $withdrawn];
    }

    /**
     * @param list<Cell> $cells
     *
     * @return list<array{FloorRow, bool, string, string}> the row, whether the floor's expectation holds, what the grid reads, and why it decided so
     */
    private function judge(array $cells): array
    {
        $seen = [];

        foreach ($cells as $cell) {
            $seen[$cell->key] = [$cell->verdict, $cell->defect, $cell->decidedBy];
        }

        $judged = [];

        foreach ($this->rows as $row) {
            if (!isset($seen[$row->row])) {
                // A floor row the grid does not carry at all is neither held
                // nor cured: it is a stale declaration, and it says so in the
                // words of whichever half asked.
                $judged[] = [$row, false, 'no such row in the grid', ''];

                continue;
            }

            [$verdict, $defect, $decidedBy] = $seen[$row->row];
            $held = $row->verdict === 'any-defect' ? $defect : $verdict === $row->verdict;
            $judged[] = [$row, $held, $verdict . ($defect ? ' (a defect)' : ' (not a defect)'), $decidedBy];
        }

        return $judged;
    }
}
