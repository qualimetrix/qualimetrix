# Stage 1 — the floor judges a cure that landed after its own snapshot

## Why this is first

`Floor::cureMisses()` reads the `cure` column on BOTH halves of the pair. The
frozen half measures a product older than any cure this round lands, so the first
such cure makes its own floor rows read "declared cured, and the grid still calls
it a defect" — red, on a half that is behaving correctly. `floor.tsv`'s header
predicts this and offers two ways out.

**Re-taking the snapshot is not one of them.** A snapshot taken at the start of a
round precedes its cures by construction; one taken after them makes the cured
rows green on both halves and destroys the witness — which is how the floor's
positive direction fell from 26 rows to 5.

**Git ancestry cannot decide it either.** The three commits already standing in
`cure` are not ancestors of `main`: the repository squash-merges, so a branch hash
is unknown to a fresh clone. An ancestry check would redden twenty-one standing
rows immediately and fail in CI. Measured in `measurement/observations.md` §7.

## Three holes this stage closes, two of them pre-existing

### 1. A cure landed after the snapshot cannot be expressed

A sentinel in the existing `cure` column, not a new column:

- `cure` empty — unchanged: the row must still be a defect on both halves.
- `cure` = a commit — unchanged: the row must read repaired on both halves.
- `cure` = `pending: <text>` — new: the row MUST still be a defect on the frozen
  half and MUST NOT be one on the live grid.

Both directions of the new shape redden. A `pending` row already green on the
frozen half claims the snapshot postdates the cure, which means the witness is
gone; a `pending` row still defective on the live grid is a false claim about the
round.

**What checks the commit a `pending` row is later converted to: nothing, and this
stage says so out loud instead of implying otherwise.** The column's commit is
documentation. It cannot be verified here, because the only hash that will exist
after the round is the squash commit `main` receives at merge — which does not
exist while the round is open, and which the pre-squash hashes already in the file
are not. The floor checks the DISPOSITION, which is a property of the two halves
and is fully checkable; `floor.tsv`'s header states the limit rather than leaving
a reader to infer a guarantee. The docblock in `scripts/promise-effect.php` that
today claims "a cure that names a commit the snapshot does not contain is the lie
this catches" is corrected in this stage: no such check exists and none can be
written from inside a round.

### 2. A row that left the grid is counted as CURED

Pre-existing, found by both reviewers, and it would swallow the sentinel whole.
`Floor::judge()` returns `held = false` for a row the grid does not carry, with
the text `no such row in the grid`; `cureMisses()` then treats `!$held` on a row
with a `cure` as success. A row that VANISHED from the population reads as proof
of repair. `judge()`'s own docblock says the opposite ("neither held nor cured: it
is a stale declaration"), so docblock and code disagree.

**A row absent from the grid is a miss under every disposition** — empty `cure`,
a commit, `pending`, and `withdrawn` alike. Absence is never evidence about the
product. The trigger is not hypothetical: stage 2 regenerates the grid artifact
and touches ledger rows for the same four coordinates, and a coordinate that
changed key on both sides at once would otherwise pass silently.

### 3. A narrowed freeze can retire an axis without saying so

`--axis=` narrows every run, `--freeze-before` included, so a snapshot can be
taken over a subset of axes; `shot.txt` records the subset, and `--before` then
iterates the full canonical list without ever comparing the two. Combined with
hole 2, an axis silently dropped from a snapshot turns its whole floor into
"cured".

Two guards, because they fail differently:

- `--freeze-before` refuses when `--axis` narrows the run. A snapshot is of the
  whole grid or it is not a snapshot.
- `--before` refuses when `shot.txt`'s `axes` does not cover the canonical list,
  which also catches a snapshot taken before an axis existed.

### 4. The sentinel must not become permanent

A `pending` row is honest only while the frozen half predates the cure. The moment
a later round re-takes the snapshot, every `pending` row becomes a lie of the
opposite kind. So `--freeze-before` refuses while any `pending` row stands, names
them, and says that converting them is the retaking round's first step. This is
the same shape as the existing `before-commit` guard: a declaration that cannot
silently defeat the check it exists to make.

## Contracts

```
FloorRow::curePending(): bool            // cure carries the sentinel prefix
FloorRow::cureText(): string             // the claim, sentinel stripped
Floor::pendingRows(): list<FloorRow>     // for the retake guard to name them
Floor::cureMisses(array $cells, bool $frozenHalf): array{...}
```

`cureMisses()` gains the parameter that tells it which half it judges — today one
call serves both and cannot tell them apart, which is why the sentinel cannot be
expressed without it. Both existing call sites pass it explicitly.

## Definition of Done

- `composer promise-effect` reports the same verdicts and the same exit code as
  before this stage (baseline: 135 defects, floor "5 still defective, 21 cured as
  declared", exit 1). This stage changes what the floor can express, not what the
  grid says. Both runs recorded side by side.
- Unit tests over `Floor`, with synthesised cells, covering at minimum:
  `pending` defective on frozen + clean on live (passes); `pending` defective on
  both (miss, message names the live grid); `pending` clean on both (miss, message
  says the snapshot postdates the cure); a row absent from the grid under EACH of
  the four dispositions (miss every time — this is the pre-existing hole, and a
  test is planted against the old behaviour to prove it bites); a commit-valued
  row unchanged in both directions; a row with both `cure` and `withdrawn` still
  refused.
- `--freeze-before` refuses with `--axis`, and refuses while a `pending` row
  stands, naming the rows. `--before` refuses a snapshot whose `axes` do not cover
  the canonical list. Each guard has a test that does not run the 200-second grid.
- `floor.tsv`'s header documents the sentinel, why ancestry was rejected, and that
  the commit in the column is documentation rather than a checked fact.
- No row of `floor.tsv` changes disposition in this stage.

## Files

`scripts/promise-effect/Floor.php`, `scripts/promise-effect.php` (the floor block,
the `--before` block and the `--freeze-before` block), `promise-effect/floor.tsv`
(header only), and the Floor test file. No file under `src/`.
