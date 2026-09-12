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
 *
 * `cure` also carries a fourth shape: `pending: <text>`, for a cure that lands
 * AFTER the frozen half was taken. The bare two-shape rule above cannot
 * express it — the frozen half predates the cure, so the row is correctly
 * still a defect there and correctly repaired on the live grid, and a claim
 * that has to be true on one half and false on the other cannot live in a
 * column that means the same thing on both. `pending` says which half is
 * which instead of picking one: still a defect on the frozen half, not a
 * defect on the live grid. Both directions redden — a `pending` row already
 * green on the frozen half claims the snapshot postdates the cure (the
 * witness this floor exists to keep is then gone), and a `pending` row still
 * defective on the live grid is a false claim about the round, the same
 * failure a plain `cure` reddens for.
 *
 * The commit `pending` is later rewritten to is documentation, not a checked
 * fact — see `cureMisses()`.
 *
 * A row absent from the grid entirely is a miss under EVERY disposition —
 * empty `cure`, a commit, `pending`, `withdrawn` alike. Absence is never
 * evidence about the product: a coordinate that vanished cannot be read as
 * "no longer a defect", and a disposition that read `!held` as success on an
 * absent row would let a row vanish its way to CURED.
 *
 * A row present but read `NOT OBSERVABLE` is the same trap wearing a verdict
 * instead of a gap: the classifier hands that verdict a `defect = false` bit
 * unconditionally, whether the product got fixed or the probe just stopped
 * watching (a side stopped writing, two magnitudes stopped differing). `cure`
 * and `pending` never claimed the second thing, so reading it off the same
 * bit as the first would let a broken probe cure a row for free — the same
 * failure `withdrawn` already refuses to accept without a named reason.
 */

namespace Qualimetrix\PromiseEffect;

final readonly class FloorRow
{
    private const string PENDING_PREFIX = 'pending: ';

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

    /** The sentinel shape of `cure`: a claim about a repair the frozen half predates. */
    public function curePending(): bool
    {
        return str_starts_with($this->cure, self::PENDING_PREFIX);
    }

    /** The claim itself, sentinel prefix stripped. Equal to `cure` when the row is not `pending`. */
    public function cureText(): string
    {
        return $this->curePending() ? substr($this->cure, \strlen(self::PENDING_PREFIX)) : $this->cure;
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

            if ($row->curePending() && trim($row->cureText()) === '') {
                throw new LedgerError('floor.tsv: ' . $row->row . ' is declared `pending:` with no claim after it');
            }

            $rows[] = $row;
        }

        return new self($rows);
    }

    /** @return list<FloorRow> the rows whose cure has not landed yet, for the retake guard to name */
    public function pendingRows(): array
    {
        return array_values(array_filter($this->rows, static fn(FloorRow $row): bool => $row->curePending()));
    }

    /**
     * The live grid — or the frozen half — against the round's own claim
     * about what it cured.
     *
     * @param list<Cell> $cells
     * @param bool $frozenHalf which half is judged: `pending` is the only
     *                         disposition that answers differently on the two, because it is the
     *                         only claim that is true on one and false on the other by design. No
     *                         default: every caller must name which half its cells came from, so a
     *                         new call site cannot silently inherit the wrong half's semantics for a
     *                         disposition that means opposite things on the two.
     *
     * @return array{list<string>, list<FloorRow>, list<FloorRow>, list<FloorRow>, list<FloorRow>} misses, the rows still defective, the rows cured as declared, the rows withdrawn as declared, the rows pending as declared
     */
    public function cureMisses(array $cells, bool $frozenHalf): array
    {
        $misses = [];
        $standing = [];
        $cured = [];
        $withdrawn = [];
        $pending = [];

        foreach ($this->judge($cells) as [$row, $held, $observed, $actual, $decidedBy, $defect]) {
            if (!$observed) {
                // A row the grid does not carry at all is never evidence
                // about the product, under any disposition — including a
                // `cure` (plain or `pending`), which used to read the same
                // `!held` this absence produces as proof of repair.
                $misses[] = $row->row . ': absent from the grid — an absent row is evidence of nothing, under every disposition';

                continue;
            }

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

            if ($row->curePending()) {
                // Honest only while the frozen half predates the cure: still
                // a defect there, and not a defect on the live grid it is
                // checked against.
                //
                // "Still a defect" (the frozen half) keeps comparing by NAME —
                // the row asserts a specific pre-cure defect, and that
                // assertion is what the frozen half exists to hold. "No
                // longer a defect" (the live grid) reads the `defect` BIT
                // alone: a row that swapped one defect for another still
                // fails `$held` (the name moved), which used to read as CURED
                // — the same "wrongness is the bit, not the word" the round's
                // own comment on `judge()` already states, applied here in
                // the direction that protects a cure claim instead of a
                // reproduction claim.
                if ($frozenHalf) {
                    $held
                        ? $pending[] = $row
                        : $misses[] = $row->row . ': declared pending (' . $row->cureText() . '), and the frozen half already reads it repaired — the snapshot postdates the cure, and the witness is gone';
                } elseif ($defect) {
                    $misses[] = $row->row . ': declared pending (' . $row->cureText() . '), and the grid still calls it ' . $actual;
                } elseif (str_starts_with($actual, Verdict::NOT_OBSERVABLE)) {
                    // "Not a defect" and "the probe stopped seeing it" are
                    // different claims, and a repair claim must not be read
                    // off the second one. NOT_OBSERVABLE carries `defect =
                    // false` unconditionally — the same bit a fixed product
                    // would also produce — so a probe regression (a side
                    // stopped writing, two magnitudes stopped differing) reads
                    // exactly like a cure unless this is asked separately.
                    $misses[] = $row->row . ': declared pending (' . $row->cureText() . '), and the grid can no longer observe it (' . $actual . ') — a stand that stopped watching is not a cure';
                } else {
                    $pending[] = $row;
                }

                continue;
            }

            if ($row->declaredCured()) {
                // Same bit-not-word rule as the `pending` branch above: a
                // plain cure only claims the defect is gone, not which name
                // the grid now uses for the cell.
                if ($defect) {
                    $misses[] = $row->row . ': declared cured by ' . $row->cure . ', and the grid still calls it ' . $actual;
                } elseif (str_starts_with($actual, Verdict::NOT_OBSERVABLE)) {
                    // Same distinction as the `pending` branch above: losing
                    // observability under a repair claim is a miss, not a
                    // cure, and the `withdrawn` branch already treats
                    // NOT_OBSERVABLE as a claim that needs a named reason —
                    // `cure` never made that claim, so it cannot be read off
                    // the same verdict for free.
                    $misses[] = $row->row . ': declared cured by ' . $row->cure . ', and the grid can no longer observe it (' . $actual . ') — a stand that stopped watching is not a cure';
                } else {
                    $cured[] = $row;
                }

                continue;
            }

            $held
                ? $standing[] = $row
                : $misses[] = $row->row . ': left the floor — it reads ' . $actual . ' and no `cure` column claims it was repaired';
        }

        return [$misses, $standing, $cured, $withdrawn, $pending];
    }

    /**
     * @param list<Cell> $cells
     *
     * @return list<array{FloorRow, bool, bool, string, string, bool}> the row, whether the floor's
     *                                                                 NAMED expectation holds (verdict AND defect bit both match — the check a row with no
     *                                                                 `cure` needs, because it asserts a specific defect), whether the grid carries the row
     *                                                                 at all, what the grid reads, why it decided so, and the raw `defect` bit alone — the
     *                                                                 check a `cure` or `pending:` row needs for "no longer a defect", because that claim is
     *                                                                 about the bit and does not name what the cell must read instead
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
                // words of whichever half asked. `cureMisses()` reads the
                // `false` observed flag before it ever asks `$held`.
                $judged[] = [$row, false, false, 'no such row in the grid', '', false];

                continue;
            }

            [$verdict, $defect, $decidedBy] = $seen[$row->row];
            // A named verdict has to be a DEFECT too, not merely wear the
            // label. The two came apart in this round: four axis-C rows kept
            // reading LOST_SIBLING after the ledger decided such a loss keeps
            // the promise, so `defect` went false while the label stayed put --
            // and a floor comparing labels alone would have gone on reporting
            // them as reproduced. The floor exists to say "this row must still
            // be wrong", and wrongness is the bit, not the word.
            $held = $row->verdict === 'any-defect' ? $defect : ($verdict === $row->verdict && $defect);
            $judged[] = [$row, $held, true, $verdict . ($defect ? ' (a defect)' : ' (not a defect)'), $decidedBy, $defect];
        }

        return $judged;
    }

    /**
     * The whole grid or none of it: `--freeze-before` refuses a snapshot
     * narrowed by `--axis`, because a partial snapshot combined with the
     * absent-row rule above would let a dropped axis read as CURED rather
     * than as unmeasured.
     *
     * @param list<string> $axes the run's own axis list, narrowed or not
     * @param list<string> $canonicalAxes the declared whole list
     */
    public static function narrowedFreezeProblem(array $axes, array $canonicalAxes): ?string
    {
        // A SET comparison, matching `incompleteSnapshotProblem()` below: a
        // count match alone lets a duplicated axis stand in for a missing
        // one (`A,B,C,D,D` against canonical `A,B,C,D,E` counts 5 against 5
        // and would pass), so the axis a duplicate displaces is never in
        // the snapshot the guard is meant to keep whole.
        $missing = array_diff($canonicalAxes, $axes);
        $unexpected = array_diff($axes, $canonicalAxes);
        $duplicated = array_diff_assoc($axes, array_unique($axes));

        if ($missing === [] && $unexpected === [] && $duplicated === []) {
            return null;
        }

        return '--freeze-before refuses --axis=… — a snapshot is of the whole grid or it is not a snapshot (axes: '
            . implode(',', $axes) . ', canonical: ' . implode(',', $canonicalAxes) . ')';
    }

    /**
     * `--before` refuses a snapshot whose own `shot.txt` does not declare
     * every canonical axis — the companion guard to `narrowedFreezeProblem()`,
     * catching a snapshot taken before an axis existed as well as one taken
     * under a narrowed `--axis`.
     *
     * @param list<string> $shotAxes the `axes` line of `shot.txt`, split on `,`
     * @param list<string> $canonicalAxes the declared whole list
     */
    public static function incompleteSnapshotProblem(array $shotAxes, array $canonicalAxes): ?string
    {
        $missing = array_values(array_diff($canonicalAxes, $shotAxes));

        if ($missing === []) {
            return null;
        }

        return '--before refuses this snapshot — its `axes` (' . implode(',', $shotAxes)
            . ') does not cover the canonical list (' . implode(',', $canonicalAxes)
            . '); missing: ' . implode(',', $missing);
    }
}
