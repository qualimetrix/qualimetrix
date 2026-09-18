# Stage 01 — the removal

Three packages. P1 and P2 touch disjoint files and run in parallel; P3 is the
orchestrator's and starts when both land.

## The one decision this stage must make before editing

`fixtureDirectoryRows()` in the test generator emits two disposition strings
that name the concept in prose:

- `P8: retain until consumer proof; delete only if confirmed orphan.`
- `Split by file owner and closure package.`

They are values of the `disposition` column, which survives this work. Either
answer is defensible; the stage must pick one **in writing** before P2 starts,
because the acceptance oracle's expectation differs:

- **Leave them.** `disposition` stays byte-identical and the oracle expects no
  movement in it. The closing `grep` still returns `0` — the pattern
  `'P[0-8](-[A-E])?'` requires the closing quote and does not match `P8:` inside
  a sentence. Residue: two sentences naming a concept the tree no longer has.
- **Reword them.** `disposition` moves, the oracle **must** flag it in
  `test-fixture-directories.tsv`, and P2's report states the expected number of
  moving rows in advance. A flag nobody predicted is a defect; a flag predicted
  to the row is evidence.

Undeclared is the only wrong answer: an unannounced move in a surviving column
is exactly what the oracle exists to catch, and it cannot tell intent from
accident.

**Default, if the stage does not decide otherwise:** leave them, and record in
`00-overview.md` that the prose is knowingly retained. The second disposition
string is a real instruction to a reader about splitting fixture directories;
rewriting it is a separate subject from retiring a field.

## P1 — manifest, schema, production generator

**Files.** `docs/internal/modular-architecture-manifest.json`,
`docs/internal/modular-architecture-manifest.schema.json`,
`scripts/generate-modular-architecture-production-inventory.php`.

These cannot be split: the generator reads the manifest field at `:824`, so a
tree with one edited and not the other does not run. Observed shape of that
failure, for recognition — 955 × `Undefined array key "closure_package"`, then
`TypeError: {closure:tsv():649}(): Argument #1 ($field) must be of type string,
null given`.

**What changes.**

- Every declaration loses the field; `version` goes `2` → `3`.
- The schema drops the property and its `required` entry; `const` follows the
  version. `additionalProperties: false` then makes a leftover field a refusal
  rather than a silent pass — which is why manifest and schema move together.
- The generator stops reading, carrying and printing the label. The tuples that
  assign one collapse to the owner:

  ```
  // documentationDisposition(): a pair becomes a single value
  'AGENTS.md' => ['Architecture.Governance', 'P2'],   // before
  'AGENTS.md' => 'Architecture.Governance',           // after
  // ... implementation details: 80 such rows, plus the P0 early return
  ```

  and the `tsv()` header/row pairs drop the column. The `$closure` variable in
  `documentationDispositionRows()` goes with them.
- `validateP4Target()` is deleted outright. It is unreachable and references a
  manifest key that does not exist; keeping a dead validator for a retired field
  is the fossil this plan is about.

**Definition of Done.**

1. `php scripts/generate-modular-architecture-production-inventory.php` exits 0.
2. `grep -cE "'P[0-8](-[A-E])?'"` over the file returns `0`.
3. `grep -c 'closure_package' ` over the file and the manifest returns `0`.
4. `vendor/bin/phpstan analyse` (level 8, as `phpstan.neon` configures it) is
   clean on the file.
5. `validateP4Target` has no occurrence left in the tree.

## P2 — test generator

**Files.** `scripts/generate-modular-architecture-test-inventory.php`.

**What changes.**

- `classifyOwner()` stops deriving a label and returns the owner alone; its
  callers stop unpacking a pair.
- `TOOLING_TEST_ROOT_OWNERS` maps each root to an owner rather than to a pair.
- `fixtureDirectoryRows()` loses the `packages` accumulator and the
  `closure_packages` column — the plural is this aggregate and nothing else, so
  it cannot outlive the singular.
- `inventorySummary()` loses `closure_package_counts`, which is computed and
  read by nobody. Note for whoever edits it: with the field gone,
  `array_column()` returns `[]` rather than failing, so this one disappears
  quietly if missed — the `grep` in the DoD is what catches it.
- `--classification-probe=` stops printing the label. It has no consumer in the
  tree; the option itself is out of scope here.

**Definition of Done.** Items 1–4 of P1, against this file, plus: the count of
rows whose `disposition` changes is **stated in the report before P3 runs** —
`0` under the default decision above.

## P3 — re-derivation and acceptance

Orchestrator's; starts when P1 and P2 are both accepted.

1. Capture the current artifacts to a reference directory **before**
   regenerating: `docs/internal/generated/modular-architecture/` as it stands on
   `main`. The oracle's "before" side cannot be reconstructed afterwards.
2. `php scripts/generate-modular-architecture.php` to re-derive.
3. `python3 oracle.py <before> docs/internal/generated/modular-architecture closure_package closure_packages`
   — exit 0 is the acceptance. Exit 1 names the file and the column that moved;
   anything other than a movement predicted by P2 is a defect, not a surprise to
   be accepted. Take the exit code without a pipe: `| tail` replaces it.
4. `composer architecture:check`, then the full `composer check` from a clean
   clone with copied `vendor`, `website/.venv` and
   `src/Reporting/Template/node_modules` — a green run in this working copy
   proves less, because leftovers here have produced false green before.

### What the oracle is, and why it is stronger than a column audit

It cuts the named columns out of the "before" copy and compares the result
byte-for-byte with "after". Equality is the proof: any movement in a surviving
column cannot cancel out, because the cut "before" is exactly what "after" must
be. It covers every file in the directory, not only the TSVs — the two `.txt`
siblings are where a dropped column would hide from a TSV-only sweep — and
non-carriers must come out byte-identical.

It was proven to bite before being trusted, on five planted breakages: the
column not removed (exit 1, names the header mismatch), a clean removal
(exit 0), a removal plus a tampered neighbouring column (exit 1, names
`proposed_owner` and the row), a removal plus a lost row (exit 1, with the row
count), and a removal plus a tampered `.txt` (exit 1, by line). A sixth attempt
planted into a column that does not exist in the target file and the oracle
correctly stayed green — recorded because a green run on a failed plant is the
shape that gets mistaken for evidence.

## Test plan

No new tests. This removes a field with no reader; the controls that already
exist are what must stay green, and they are named in P3.

The one thing worth checking deliberately: `governance/` currently has no
control asserting anything about `closure_package`, so nothing turns red by
name. That is expected — and it is also why the `grep` counts sit in each
package's Definition of Done rather than being left to the suite.
