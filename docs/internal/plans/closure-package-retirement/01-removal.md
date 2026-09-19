# Stage 01 — the field, the prose, and the config comments

Three packages. P1 and P2 touch disjoint files and run in parallel; P3 is the
orchestrator's and starts when both are accepted.

## Capture the "before" side without touching the tree

The snapshot is taken by **rendering the base tree into a scratch directory**,
not by copying the published one and hoping nothing overwrites it in the
meantime. Both generators accept `--output-directory=`, and the repository's own
driver already stages into a temporary directory before publishing.

**`--output-directory=` alone does not capture `qmx.yaml`, and a snapshot taken
without the second flag writes into the tree it is supposed to leave alone.**
The production generator carries the config on a separate flag, `--qmx-output=`,
which defaults to the live `qmx.yaml`. So a bare `--output-directory=<scratch>`
renders twenty artifacts into the scratch directory and the config into the
repository root — overwriting one of the very files stage 01 edits. It also
leaves the "before" side one file short of the "after" side, and the oracle then
reports `FILE SET CHANGED` and never reaches the comparison that matters. Every
snapshot call carries both:

```
php scripts/generate-modular-architecture-production-inventory.php \
  --output-directory=<scratch> --qmx-output=<scratch>/qmx.yaml
php scripts/generate-modular-architecture-test-inventory.php \
  --output-directory=<scratch>
```

Twenty-one files is the correct count: twenty artifacts plus `qmx.yaml`. The
driver renders the config alongside the artifacts and asserts it matches, so a
snapshot without it would miss a change there entirely.

Three further traps, all measured:

- A bare generator call publishes into
  `docs/internal/generated/modular-architecture/` — the very directory the
  oracle needs unchanged. Run a package first and "before" is gone; comparing
  "after" with "after" is a green run that proves nothing.
- `git archive` cannot take the snapshot either: `.gitattributes` carries
  `/docs/ export-ignore`, so `git archive HEAD docs/…` yields an empty archive,
  and `--worktree-attributes` does not change it. An empty "before" against an
  empty "after" also passes.
- A snapshot rendered from a tree a package has already edited is not a
  "before". Render it from a clean worktree at the base commit
  (`git worktree add --detach <scratch>/base <base-sha>`), or re-derive it there
  and compare, if anything may have touched the scratch directory since.

## The concept is published as prose, in columns that survive

Three groups, 129 rows, all of them values of `disposition` — a column this work
keeps:

| Artifact                      | Rows | What the row says                |
| ----------------------------- | ---- | -------------------------------- |
| `documentation-ownership.tsv` | 94   | names a migration package        |
| `documentation-ownership.tsv` | 5    | `P0 governance documentation; …` |
| `test-ownership.tsv`          | 30   | names a closure package          |

The 5-row group is the one two drafts missed, and it is the dangerous shape: it
carries a **P-token inside the prose of a surviving column**. An acceptance that
watches only the column would report success with the concept still published.

The first group is 94 and not the 93 measured at planning time: the tree moved
under this work, and a merge added one ADR that the generator dispositions with
the same sentence. That is the ordinary shape of this group, not a surprise —
the number is a property of the tree the work lands on, so **re-derive it at the
base the branch actually sits on** rather than carrying it forward.

Because these rows move in a surviving column, the oracle flags them by design.
The package that changes them states the expected count **before** P3 runs: a
flag predicted to the row is evidence, an unpredicted one is a defect.

Both generators also emit disposition strings mentioning the concept that no
current row produces (`P8: retain until consumer proof…`, `Split by file owner
and closure package.`). They are rewritten too — a branch that would print a
retired concept if it fired is the same defect, only quieter.

## The config comments

`qmx.yaml` names `P4` in two comments (lines 1538 and 1543), explaining a layer
boundary in terms of a migration package. They are rewritten to describe the
boundary by its owner. No check would ever have caught these; they are in the
enumeration because a reviewer's finding about artifact prose prompted looking
one directory up.

## P1 — manifest, schema, production generator, version control, config

**Files.** `docs/internal/modular-architecture-manifest.json`,
`docs/internal/modular-architecture-manifest.schema.json`,
`scripts/generate-modular-architecture-production-inventory.php`,
`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`,
`qmx.yaml`.

Manifest, schema and generator cannot be split: the generator reads the field at
`:824`, so a tree with one edited and not the other does not run. Observed shape,
for recognition — 955 × `Undefined array key "closure_package"`, then
`TypeError: {closure:tsv():649}(): Argument #1 ($field) must be of type string,
null given`. The governance test joins because it pins `version` at `2`.

**What changes.**

- Every declaration loses the field; `version` goes `2` → `3`; the schema drops
  the property, its `required` entry, and follows the version in `const`.
  `additionalProperties: false` then turns a leftover field into a refusal.
- The schema's dead definitions go: `internalGrant`, `p4Target`,
  `p4TargetDeclaration` — unreachable by `$ref` from the root. `closes_in`
  **stays**: it is stage 02's subject, and it is published data, so moving it
  here would change artifacts this stage's acceptance is not built to judge.
- `validateP4Target()` is deleted.
- The generator stops carrying the value; tuples collapse to the owner:

  ```
  // documentationDisposition(): the slot leaves the tuple
  'AGENTS.md' => ['Architecture.Governance', 'P2'],   // before
  'AGENTS.md' => 'Architecture.Governance',           // after
  // ... implementation details: ~80 such rows, the P0 early return and its
  // 5 prose rows, the 'shared' and '…documentation' rows, the $closure
  // variable, and the tsv() header/row pairs
  ```

**Definition of Done.** One check per home, because one grep cannot cover them.

**Write every negative check as a refusal, not as a printed count.** `grep -c`
inverts: it exits `0` when it found the forbidden string and `1` when the file
is clean, so "`grep -c …` returns `0`" run as a shell gate is **green on a dirty
tree and red on a clean one** — measured on this artifact, 94 hits exit 0 and a
clean file exits 1. The form below (`! grep -q`) exits non-zero exactly when the
check fails. And do not transpose these patterns into `git grep -E`: its POSIX
matcher does not honour `\b` here and returns nothing where `grep` returns 94,
which is the same defect wearing a different hat. Use `git grep -P` or `-wE` if
a check must run against a revision.

1. The generator, carrying **both** snapshot flags
   (`--output-directory=<scratch> --qmx-output=<scratch>/qmx.yaml`), exits 0.
2. **The column's home:** no value of the slot's domain survives as a quoted
   literal. **Derive the domain by command, do not read it off a list.**
   `enumeration.tsv` was hand-snapped and its `value:` rows are missing `P6-E`,
   which lived in two manifest declarations and reached two artifacts — a
   surviving `'P6-E'` would have passed a check built from that list. Derive it
   instead from the base commit:

   ```
   git show <base>:docs/internal/generated/modular-architecture/documentation-ownership.tsv \
     | cut -f3 | tail -n +2 | sort -u          # and the same for test-ownership.tsv, column 10
   ```

   Match as a quoted literal, not as a substring: `shared` and `permanent` are
   ordinary English words and a substring grep would redden on unrelated code.
3. **The name's home:** `! grep -q 'closure_package' <file>` for the generator,
   the manifest **and the schema** — three files named explicitly. A list of
   three files is not a sweep: close with `git grep -l closure_package` over the
   tree as well, so a fourth home cannot hide behind the named three.
4. **The prose's home, and it needs two checks, not one.** The rendered
   `documentation-ownership.tsv` must carry no `P`-token
   (`! grep -qE '\bP[0-8](-[A-E])?\b' <file>`), which covers the column and the
   5 rows inside `disposition`. That is not enough on its own: the 93 rows end
   in `…migration package."` and carry **no P-token at all**, so a token check
   passes over every one of them. The second check is for the wording —
   `migration package` and `closure package` must both be absent. Measured to
   make the point: of `test-ownership.tsv`'s 310 P-tokens, all 310 are column
   values and its 30 prose rows contain none.
5. **The config's home:** `qmx.yaml` carries no `P`-token.
6. `vendor/bin/phpstan analyse` clean (it needs `--memory-limit=512M`, as
   `composer phpstan` passes); `validateP4Target` and the three dead `$defs`
   have no occurrence left; the governance test passes at version 3.
7. The number of `disposition` rows whose text changes is stated in the report.

**Expected red until P3 publishes.** `ModularArchitectureGovernanceIntegrationTest`
runs `generate-modular-architecture.php --check` first, which compares the
published artifacts against a fresh render. That test and `composer
architecture:check` are red by construction in P1's and P2's trees. Publishing
from a package to make them green is wrong — it ships artifacts missing the
other package's changes.

## P2 — test generator

**Files.** `scripts/generate-modular-architecture-test-inventory.php`.

**What changes.**

- `classifyOwner()` stops deriving a value and returns the owner alone. This is
  where `permanent` lives — 622 of the artifact's 932 data rows, the majority
  value and not a P-label at all.
- `TOOLING_TEST_ROOT_OWNERS` maps each root to an owner rather than a pair.
- `fixtureDirectoryRows()` loses the `packages` accumulator and the
  `closure_packages` column; the plural is that aggregate and nothing else.
- `inventorySummary()` loses `closure_package_counts`, computed and read by
  nobody. With the field gone `array_column()` returns `[]` rather than failing,
  so it vanishes quietly if missed — DoD item 2 catches it.
- `--classification-probe=` stops printing the value.
- The 30 prose rows of `test-ownership.tsv` are rewritten.

**Definition of Done.** Items 2, 3, 6 and 7 of P1 against this file, plus both
prose checks of item 4 against the rendered `test-ownership.tsv`: no `P`-token,
and no occurrence of `closure package`. The second is the one that matters here
— its 30 prose rows carry no token, so the token check alone passes over all of
them. Check the rendered `test-fixture-directories.tsv` too: it is the other
carrier, and it loses the plural column.

Item 1 is **not** inherited as written: this generator takes
`--output-directory=` only and refuses `--qmx-output=` as an unknown argument.
Its snapshot call is the flag alone, and it renders seven artifacts, not four.

## P3 — re-derivation and acceptance

1. `php scripts/generate-modular-architecture.php` to publish.
2. `python3 oracle.py <before> <after> closure_package closure_packages`, where
   `<before>` is the scratch snapshot and `<after>` the published directory plus
   `qmx.yaml`. Exit 0 is acceptance **only together with** the predicted
   disposition count from P1 and P2: the oracle cannot tell an intended prose
   rewrite from an accidental one, so the prediction is what makes exit 1
   readable. Take the exit code without a pipe — `| tail` replaces it.
3. `composer architecture:check`, then the full `composer check` from a clean
   clone with copied `vendor`, `website/.venv` and
   `html-report/node_modules`. A green run in the working copy proves
   less: leftovers there have produced false green before.

### What the oracle proves, and what it does not

It cuts the named columns from "before" and compares the result with "after" on
bytes. Equality is the proof: movement in a surviving column cannot cancel out.
It covers every file it is given — the `.txt` siblings and `qmx.yaml` included.

**Its limit, stated because stage 02 depends on it:** it compares *columns*. A
key removed from inside a JSON cell of a surviving column — which is exactly
what `closes_in` is in `production-ownership.tsv` — moves that cell and is
reported as a column change, with no way to say which key moved. For stage 01
that limit does not bind; for stage 02 it does, and that stage needs its own
comparison.

It was proven to bite on seven planted breakages: column not removed, clean
removal, a tampered neighbouring column, a lost row, a tampered `.txt`, a
stripped trailing newline, and a file missing from the set. An eighth attempt
planted into a column absent from the target file and the oracle stayed green —
recorded because a green run on a failed plant is the shape mistaken for
evidence.

## Test plan

No new tests. `governance/` has no control asserting anything about
`closure_package`, so nothing turns red by name when it goes — which is why the
checks sit in each package's Definition of Done, and why adding a control for a
field that is leaving would be wrong.
