# Stage 02 — the move, its registration addresses, and the records

Does not begin until stage 01 has landed and its control is green. Destination
is `html-report/` at the repository root, the whole directory, 28 files.

## Packages

P1 and P2 touch disjoint files and run in parallel; P3 runs alongside; P4 is the
orchestrator's.

**Neither P1 nor P2 leaves the tree green on its own, and that is a choice, not
a property of the move.** A self-contained first package is reachable: move the
three one-line literals P2 owns — the shipping guard's `TREE`,
`RuleIdentifierLiteralGuardTest`'s `EXISTENCE_CHECKED_FILES`, and
`createIsolatedProject()`'s copy list — plus the scan scope into P1, and only
artifact freshness stays red, which regeneration closes anyway. The split below
is taken for parallelism; its price is that neither half is green and neither
can be reviewed against a green tree. Say it out loud so no executor "fixes" a
red that belongs to the other half.

### P1 — the move, and everything that refuses to run without it

**Files.** The directory itself; `composer.json`;
`.github/workflows/qmx.yml`; `.gitattributes`; `.gitignore`.

Three files the first draft listed here are **not** in this package, because
measurement says they need no edit: `Dockerfile` (`COPY . .`, names no path),
`.dockerignore` (only `**/tests/`, `**/node_modules/` and a root-anchored
`dist/`, all of which follow or do not apply), and `scripts/init-environment.sh`
(names the path nowhere — its node gap is stage 01's, already handled there).
Listing a file that needs no edit invites one.

**Move with `git mv` and commit it.** The shipping guard reads
`git archive HEAD`; a plain `mv` leaves HEAD untouched, so the guard measures
the old tree and stays green. Measured — the guard's own docblock records a
probe that planted an untracked file and stayed green.

**What changes.**

- The directory moves, the JS root module from stage 01 with it — one hop, still
  one hop.
- `HtmlFormatter.php:38`'s `'/Template'` and its refusal text at `:83`, which
  advises `cd src/Reporting/Template && npm run build`. The formatter's
  `$templateDir` variable and its concatenations **stay in that shape**: the
  shipping guard reads them by regex, and stage 01 exists partly because
  changing that shape reddens the guard.
- The seven `export-ignore` lines repoint. They are inert at the wrong path and
  say nothing about it: measured, the package went from 4 files to 28 with **no
  refusal**. The prose counter in the same file's comment (`:23-28`, "33 -> 6")
  is repointed with them.
- The two `.gitignore` negations repoint. Measured at this destination:
  `html-report/package.json` and `package-lock.json` are then swallowed by the
  blanket `*.json` rule at `:61`.
- CI carries the path twice, in `cache-dependency-path` and an `npm ci` prefix.
- `composer.json`'s `test:js` and `build:js` carry it as a literal `cd`;
  `test:js` is inside `check:code`.

**Definition of Done.**

1. **The report is unchanged except for its timestamp.**
   `bin/qmx check <fixed target> --format=html` exits as before and its output,
   with the `generatedAt` value normalized to a constant, is byte-identical to
   the same command's normalized output on the base commit. Plain byte-identity
   is **unachievable** and the first draft demanded it: measured, two runs of
   the same command on the same tree differ at the timestamp
   (`HtmlTreeBuilder.php:339`, `gmdate('c')`, embedded in the page). Take the
   base's normalized hash before moving anything.
2. Item 1 is taken **after** `composer build:js`, not before. The build writes
   the tracked `dist/report.min.js` and `dist/d3.min.js`, and the formatter
   inlines them, so building changes item 1's input. Whether the bundle rebuilds
   byte-for-byte is not known — it is a declared blind spot — so the package
   also reports `git diff --stat -- html-report/dist` rather than assuming it is
   empty.
3. The composer package carries the same four files, at the new path and no
   others: `git archive HEAD html-report | tar -tf -`, compared against the
   base's four **by name**.
4. `git check-ignore -v --no-index` on the moved `package.json`,
   `package-lock.json` and `html-report/dist/report.min.js` — each says what the
   base said for its old path. **`--no-index` is the whole point:** without it
   `git check-ignore` says nothing at all about a tracked file, so the check
   passes identically on a broken tree. Measured both ways today.
5. The `.dockerignore` question is answered with a **non-git oracle**, because
   `git check-ignore` does not read `.dockerignore`: build the image and list
   what landed, or list the build context. The question is whether the
   root-anchored `dist/` rule starts matching `html-report/dist/`; if it ever
   does, the shipped bundle leaves the image silently.
6. `composer test:js` and `composer build:js` exit 0.
7. No operational reference to the old path survives:
   `! git grep -q 'src/Reporting/Template' -- ':!docs/' ':!CHANGELOG.md'`. The
   first draft checked only `src/` and `composer.json`, which left the two CI
   occurrences unchecked by any package — they would have surfaced in the pull
   request rather than in the package.
8. State, as a number, how many rows of each generated artifact this package
   expects to change. P4 compares the prediction.
9. Expected red, named so nobody repairs it from here: `architecture:check`,
   artifact freshness, and the governance controls owned by P2.

### P2 — every place that enumerates the tree

**Files.** `scripts/generate-modular-architecture-test-inventory.php`,
`scripts/generate-rename-enumeration.php`,
`scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`,
`governance/DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest.php`,
`governance/PlanningRecords/PlanningRecordIsolationTest.php`,
`governance/.../RuleIdentifierLiteralGuardTest.php`,
`finding-gate/enumeration-js-metric-keys.tsv` and the generator that writes it.

`generate-modular-architecture-production-inventory.php` is **not** here: its
`git ls-files` scope is markdown only and names no viewer path. The first draft
claimed both generators carry scan-scope literals; only one does.

**Retiring the prescription — the branch is chosen, and its price is named.**
`targetPath()` reaches `tests/Reporting/HtmlTemplate/Tests/` for these 10 files
through its last branch, so "retire" has three possible mechanisms and the first
draft named none. The choice is **to drop the three scan-scope literals** and
with them `NON_MANIFEST_TEST_OWNERS['Reporting/HtmlTemplate']`.

Its cost, which is the real fork and not "retire versus repoint": **today an
eleventh test file under the viewer makes `architecture:check` refuse by name**
(`:1502`, actual rows versus declared). After this, no artifact mentions any of
the 28 files and that refusal is gone. Registering `html-report/` as a tooling
root instead is blocked — `actualToolingTestRootsOnDisk()` globs only
`scripts/*/tests` and `tools/*/tests`, so registration refuses immediately.

So the package **replaces the contract rather than deleting it**: the new root
is declared wherever it can be, and if no existing mechanism accepts it, the
package says so explicitly and the loss is recorded in the ADR as accepted debt
with the condition for revisiting. A silent loss of a live refusal is the one
outcome this package may not produce.

**What else changes.**

- `surfaces()` gains the new root. Prefer adding it to the **existing `src`
  surface** rather than opening a column: the total then stays 115 in the same
  place and there is no "moved column" to explain. `excludeFiles` also names
  `src/Reporting/Template/package-lock.json` by hand and follows.
- `PlanningRecordIsolationTest`'s own root list. Today it reaches 23 of the
  viewer's files; after the move, none, **without refusing**.
- The shipping guard's `TREE`, and the prose counter in its docblock (`:14-17`,
  "all 33 of its entries") and at `:95` (`check-attr` on `Template/src/tree.js`)
  — the last of which no path sweep finds, because it spells the path the other
  way round.
- `RuleIdentifierLiteralGuardTest`'s existence-checked file list (it names
  `dev.html`), and the copy list in `createIsolatedProject()`, which fails
  loudly with PHPUnit exiting 2 inside the scratch project.
- `finding-gate/enumeration-js-metric-keys.tsv` carries the old path in its
  header, written by `collect-metric-keys.mjs`. Nothing regenerates or checks
  it; it is here because it is an unowned file, not because a check will catch it.

**Definition of Done.**

1. Both the test inventory and the rename enumeration exit 0 into a scratch
   directory. The production generator, if run, needs **two** flags —
   `--output-directory=` and `--qmx-output=` — or it renders the config into the
   live tree; the test generator takes the first only and refuses the second.
2. The shipping guard **measures**: plant one extra file at the new path, quote
   the refusal naming that file, restore from a copy taken before the plant.
   **This item can only be taken once P1 is in the tree** — until the
   `export-ignore` lines repoint, the guard reports 28 extra files and a
   refusal naming the planted one is indistinguishable from the noise. Dependency
   between the packages' *acceptance*, not their files.
3. `PlanningRecordIsolationTest` **measures at the new root**: plant a file under
   `html-report/src/` carrying a planning-record reference, confirm the control
   refuses, restore. Without this the fix is a line edit nothing tests.
4. `! git grep -q 'Reporting/Template\|Reporting/HtmlTemplate'` over `scripts/`
   and `governance/`, with the prescription retired rather than repointed.
5. State, as a number, how many rows of each generated artifact this package
   expects to change — including the rows leaving `test-ownership.tsv`.
6. Expected red until P4 regenerates: artifact freshness and anything reading a
   published artifact.

### P3 — the records

**Files.** A new ADR under `docs/adr/`, `docs/adr/README.md`,
`src/Reporting/README.md`, `finding-gate/README.md`, `CHANGELOG.md`, and any
operational document naming the old path.

- The ADR **supersedes** `docs/adr/0012`'s line 109, the only written authority
  on where the viewer lives. It records why the viewer leaves the PSR-4 root,
  why the whole directory moves, why `tests/Reporting/HtmlTemplate/Tests/` was
  rejected — the `/tests/ export-ignore` argument, which is decisive and which
  the first draft left out — and what coverage P2's retirement costs.
- `finding-gate/README.md:52` is **already wrong before this work**: it calls
  `metric-key-catalog.test.js` a check of a TSV file, but the test rebuilds the
  catalog from PHP sources and reads no TSV. Fix the sentence; do not extend the
  move to close the gap it describes.
- Decide whether the new root gets a `README.md`. AGENTS.md asks for one per
  directory, and if it appears it falls outside `documentationInventory`'s
  `src/**/README.md` glob — an unowned document, the same silent class as the
  rest. Decide it here rather than discovering it in P4.
- `CHANGELOG.md` gets a `Changed` entry only if a consumer can observe this. The
  four shipped assets move inside the package, so a consumer resolving them by
  path does.

**Definition of Done.** **No *operational* document names the old path** — a
document that advises a command, a location, or a build step. Historical records
keep it and are listed by name: `docs/adr/0002`, `docs/adr/0012` (which this
work supersedes rather than edits), released `CHANGELOG.md` entries, and every
plan directory including this one. The first draft's "no document names the old
path" was unachievable and, taken literally, ordered history rewritten.
The ADR is indexed; `composer docs:check` is green.

### P4 — regeneration and acceptance

1. Regenerate and publish the artifacts.
2. **The coverage oracle.** Re-derive with the generator's own predicate, not by
   grepping lines: the `src` surface holds 115 whole-identifier occurrences of
   `health.overall`, **92** of them from the viewer's files. The surface excludes
   `dist/` and `package-lock.json`, which is why a line count says 93. After the
   move the same 92 must still be counted. If P2 added the root to the existing
   surface, the total stays 115 in the same column and that is the expected
   result; a total that fell is the drop.
3. Compare the artifact diff **by column**, against P1's and P2's predicted row
   counts. Not by total line count.
4. `composer architecture:check`, then the full `composer check` **from a clean
   clone** with copied `vendor`, `website/.venv` and `node_modules`, on a machine
   with node. A green run in the working copy proves less.
5. The report's normalized identity from P1's item 1, re-taken after everything
   has landed.

## Test plan

No new tests. Two existing controls gain a plant that proves they still bite
(P2 items 2 and 3); everything else is already guarded, or is guarded by nothing
for a reason recorded in its row of `enumeration/references.tsv`.

Deliberately **not** added: a control asserting "no file names the old path". It
would be green forever after this work and never refuse again — the
check-that-cannot-fail shape. Stage 01's control covers the case that matters.

## Re-derive before executing

The packaging and ignore-rule findings were measured for a root `frontend/` and
re-checked at `html-report/` during review; they hold. Everything else was
measured on `4a701bb0`. Re-take the numbers on the branch's actual base before
P1 states its expectations — a carried-forward number is the inherited table
this repository keeps being bitten by, and this plan has already been bitten by
one of its own.
