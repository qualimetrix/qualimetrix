# Stage 01 — the field

Three packages. P1 and P2 touch disjoint files and run in parallel; P3 is the
orchestrator's and starts when both are accepted.

## Capture the "before" side first — the generators destroy it

P1 and P2 both run a generator to satisfy their own Definition of Done, and a
generator writes into `docs/internal/generated/modular-architecture/` — the same
directory P3's oracle needs in its pre-change state. Run the packages first and
the "before" side no longer exists; compare "after" with "after" and the oracle
agrees with itself.

**So the capture is step zero of the stage, before any package starts**, into a
directory outside the repository.

It cannot be taken with `git archive`: `.gitattributes` carries
`/docs/ export-ignore`, so `git archive HEAD docs/…` yields an empty archive —
measured, and `--worktree-attributes` does not change it. An empty "before"
compared against an empty set is a green run that proves nothing. Take it with
`git show <baseline>:<path>` per file, or copy the directory before editing.

## The published prose goes with the column

Two artifacts publish the concept as sentences, not only as a column:

- `documentation-ownership.tsv` — 93 rows ending in a sentence naming a
  migration package;
- `test-ownership.tsv` — 30 rows naming a closure package.

Both are `disposition` values, and `disposition` survives this work. So these
rows **move in a surviving column**, which is exactly what the oracle refuses by
default. The package that changes them states the expected number of moving rows
in its report *before* P3 runs; a flag predicted to the row is evidence, a flag
nobody predicted is a defect. They are produced in P1's file and P2's file
respectively — which is what assigns them.

Both generators also emit disposition strings that mention the concept without
publishing it in any current row (`P8: retain until consumer proof…`,
`Split by file owner and closure package.`). Measured: `test-fixture-directories.tsv`
carries two disposition values today, 105 + 16, and neither is one of these. They
are reachable code for inputs this tree does not currently have. **They are
rewritten too** — a branch that would print a retired concept if it ever fired is
the same defect as one that prints it now, only quieter.

## P1 — manifest, schema, production generator, version controls

**Files.** `docs/internal/modular-architecture-manifest.json`,
`docs/internal/modular-architecture-manifest.schema.json`,
`scripts/generate-modular-architecture-production-inventory.php`,
`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`,
`governance/RepositoryEntrypoints/MemoryCeilingManifestTest.php`.

Manifest, schema and generator cannot be split: the generator reads the field at
`:824`, so a tree with one edited and not the other does not run. Observed shape,
for recognition — 955 × `Undefined array key "closure_package"`, then
`TypeError: {closure:tsv():649}(): Argument #1 ($field) must be of type string,
null given`.

The two governance files join because `version` goes `2` → `3` and one of them
pins the literal `2`. Leaving them out makes P1 green and `composer check` red.

**What changes.**

- Every declaration loses the field; `version` goes `2` → `3`; the schema drops
  the property, its `required` entry, and follows the version in `const`.
  `additionalProperties: false` then turns a leftover field into a refusal rather
  than a silent pass.
- The schema's dead definitions go: `internalGrant`, `p4Target` and
  `p4TargetDeclaration` are unreachable by `$ref` from the root. `closes_in`
  **stays** — it is stage 02's subject, and touching it here turns a data change
  into a behaviour change.
- `validateP4Target()` is deleted. It is unreachable and reads a manifest key
  that does not exist.
- The generator stops reading, carrying and printing the value. Tuples that
  assign one collapse to the owner:

  ```
  // documentationDisposition(): the slot leaves the tuple
  'AGENTS.md' => ['Architecture.Governance', 'P2'],   // before
  'AGENTS.md' => 'Architecture.Governance',           // after
  // ... implementation details: ~80 such rows, the P0 early return, the
  // 'shared' and '…documentation' rows, and the $closure variable that
  // carries the value to tsv()
  ```

**Definition of Done.**

1. `php scripts/generate-modular-architecture-production-inventory.php` exits 0.
2. No value of the slot's domain survives as a literal in the generator. The
   check is written against the domain, not against the P-shape — the list is
   the `value:` rows of `enumeration.tsv`, and it includes `shared`,
   `Run documentation` and `Finding documentation`.
3. `grep -c 'closure_package'` returns `0` for the generator **and the manifest
   and the schema** — all three files, named explicitly, because the schema is
   the one a two-file habit skips.
4. `vendor/bin/phpstan analyse` (level 8) clean on the generator.
5. `validateP4Target` has no occurrence left in the tree; the three dead schema
   definitions have none either.
6. Both governance tests pass with the manifest at version 3.
7. The number of `disposition` rows whose text changes is stated in the report.

## P2 — test generator

**Files.** `scripts/generate-modular-architecture-test-inventory.php`.

**What changes.**

- `classifyOwner()` stops deriving a value and returns the owner alone; callers
  stop unpacking a pair. This is where `permanent` lives — 622 of 933 rows —
  so a check phrased "no P-label remains" would pass over the majority of it.
- `TOOLING_TEST_ROOT_OWNERS` maps each root to an owner rather than to a pair.
- `fixtureDirectoryRows()` loses the `packages` accumulator and the
  `closure_packages` column; the plural is that aggregate and nothing else, so
  it cannot outlive the singular. Its disposition strings are rewritten per the
  section above.
- `inventorySummary()` loses `closure_package_counts`, computed and read by
  nobody. With the field gone `array_column()` returns `[]` rather than failing,
  so this one disappears quietly if missed — DoD item 2 is what catches it.
- `--classification-probe=` stops printing the value.

**Definition of Done.** Items 1–4 and 7 of P1, against this file.

## P3 — re-derivation and acceptance

Orchestrator's; starts when P1 and P2 are both accepted, and uses the "before"
capture taken at step zero.

1. `php scripts/generate-modular-architecture.php` to re-derive.
2. `python3 oracle.py <before> docs/internal/generated/modular-architecture closure_package closure_packages`.
   Exit 0 is acceptance. Exit 1 names the file and the column that moved;
   the only movement accepted without investigation is the `disposition` row
   count P1 and P2 predicted. Take the exit code without a pipe — `| tail`
   replaces it.
3. `composer architecture:check`, then the full `composer check` from a clean
   clone with copied `vendor`, `website/.venv` and
   `src/Reporting/Template/node_modules`. A green run in the working copy proves
   less: leftovers there have produced false green before.

### What the oracle is, and why it is stronger than a column audit

It cuts the named columns out of the "before" copy and compares the result with
"after". Equality is the proof: movement in a surviving column cannot cancel out,
because the cut "before" is exactly what "after" must be. It compares **bytes**,
so a trailing-newline or line-ending change is a refusal rather than a pass, and
it covers every file in the directory — the two `.txt` siblings are where a
dropped column would hide from a TSV-only sweep.

It was proven to bite before being trusted, on seven planted breakages: column
not removed (names the header mismatch), clean removal (exit 0), removal plus a
tampered neighbouring column (names `proposed_owner` and the row), removal plus a
lost row (with the row count), removal plus a tampered `.txt` (by line), a
trailing newline stripped (by row count), and a file missing from the set.

An eighth attempt planted into a column absent from the target file, and the
oracle stayed green — recorded because a green run on a failed plant is the shape
that gets mistaken for evidence.

## Test plan

No new tests. This removes a field with no reader; the controls that exist are
what must stay green, and P3 names them.

Worth stating plainly: `governance/` has no control asserting anything about
`closure_package`, so nothing turns red by name when it goes. That is why the
domain check sits in each package's Definition of Done rather than being left to
the suite — and it is also a reason not to add one, since the field is leaving.
