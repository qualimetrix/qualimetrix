# Stage 1 — the floor judges a cure that landed after its own snapshot

## Why this is first

`Floor::cureMisses()` reads the `cure` column on BOTH halves of the pair. The
frozen half measures a product older than any cure this round lands, so the first
such cure makes its own floor rows read "declared cured, and the grid still calls
it a defect" — red, on a half that is behaving correctly. `floor.tsv`'s own header
predicts this ("The first cure a round lands AFTER its baseline breaks it") and
offers two ways out.

**Re-taking the snapshot is not one of them for this round.** A snapshot taken at
the start precedes the cure by construction; one taken after the cure makes the
cured rows green on both halves and destroys the witness — which is exactly how
the floor's positive direction fell from 26 rows to 5. So the floor must learn to
say "this cure is newer than this snapshot".

**Git ancestry cannot say it.** The three commits already standing in `cure` are
not ancestors of `main`: the repository squash-merges, so a branch hash is
unknown to a fresh clone. An ancestry check would redden twenty-one standing rows
immediately and fail in CI. Measured in `measurement/observations.md` §5.

## The mechanism

A sentinel value in the existing `cure` column, not a new column: the column
already means "the round claims this row is repaired", and what is added is *when*
relative to the snapshot.

- `cure` empty — unchanged: the row must still be a defect on both halves.
- `cure` = a commit — unchanged: the row must read repaired on both halves. This
  is the shape every already-cured row keeps.
- `cure` = `pending: <text>` — new: the row MUST still be a defect on the frozen
  half and MUST NOT be one on the live grid. The text says what the round claims
  it repaired, in the same words a commit row's text uses.

Both directions of the new shape redden, like the two that exist: a `pending` row
that is already green on the frozen half is a claim that the snapshot postdates
the cure, which would mean the witness is gone; a `pending` row still defective on
the live grid is a false claim about the round.

`withdrawn` is untouched and stays mutually exclusive with `cure`, including with
its sentinel form.

## The second half: the sentinel must not become permanent

A `pending` row is only honest while the frozen half predates the cure. The moment
a later round re-takes the snapshot, every `pending` row silently becomes a lie of
the opposite kind — it would demand a defect on a half that now measures the cured
product. So the retake refuses while any `pending` row stands:
`--freeze-before` exits non-zero and names the rows, telling the operator to
convert each to the commit that carries it. The conversion is the retaking round's
first step, not an optional tidy-up.

This is the same shape as the existing `before-commit` guard: a declaration that
cannot silently defeat the check it exists to make.

## Contracts

```
FloorRow::declaredCured(): bool          // unchanged: cure !== ''
FloorRow::curePending(): bool            // cure carries the sentinel prefix
FloorRow::cureText(): string             // the claim, sentinel stripped
Floor::pendingRows(): list<FloorRow>     // for the retake guard to name them
Floor::cureMisses(array $cells, bool $frozenHalf): array{...}
```

`cureMisses()` gains the one parameter that tells it which half it is judging —
today the same call serves both and cannot tell them apart, which is precisely
why the sentinel cannot be expressed without it. Both existing call sites
(`promise-effect.php`, frozen half and live grid) pass it explicitly.

## Also in this stage

The floor block's docblock in `scripts/promise-effect.php` claims a check on the
cure's commit that `Floor` does not implement. It is corrected to describe what
the code does — a docblock promising an absent guard is the failure mode this
tree has already been bitten by twice.

## Definition of Done

- `composer promise-effect` exits exactly as it does today (5 standing floor
  defects on blocking axes A/D) — this stage changes no verdict, only what the
  floor can express. Measured before and after, both exit codes recorded.
- A unit test over `Floor` covers, at minimum: `pending` green on the live grid
  and defective on the frozen half (passes); `pending` defective on both (misses,
  and the message names the live grid); `pending` green on both (misses, and the
  message says the snapshot postdates the cure); a commit-valued row unchanged in
  both directions; a row carrying both `cure` and `withdrawn` still refused.
- `--freeze-before` refuses while a `pending` row stands, with a message naming
  the rows; covered by a test that does not run the 200-second grid.
- `floor.tsv`'s header documents the sentinel and why ancestry was rejected.
- No row of `floor.tsv` changes disposition in this stage.

## Files

`scripts/promise-effect/Floor.php`, `scripts/promise-effect.php` (floor block and
the `--freeze-before` block), `promise-effect/floor.tsv` (header only), and the
Floor test file. No file under `src/`.

## Test plan

Unit, over `Floor` with synthesised cells — no grid run. The retake guard is
tested by calling the guard, not by freezing. Nothing here needs the product.
