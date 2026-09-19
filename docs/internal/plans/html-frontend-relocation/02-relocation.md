# Stage 02 — the move, its registration addresses, and the records

Does not begin until stage 01 has landed and `composer test:js` is green with
its single JS hop. Destination is `html-report/` at the repository root, the
whole directory: 29 tracked files today, 28 once P1's deletion of
`collect-metric-keys.mjs` lands.

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

**Execution takes that self-contained variant.** One executor carries P1 and P2
as a single package, so the tree is green but for artifact freshness at the end
of it, and the reviewer sees one coherent change instead of two halves neither
of which runs. P3 runs beside it — its files are disjoint and it touches no
git state. The two plants (P2 items 2 and 3) move to P4: a plant is acceptance,
it needs the whole move in `HEAD` to mean anything, and a `git reset --hard`
inside a package would discard the parallel package's uncommitted work.

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
  say nothing about it: measured on the 28-file tree of the time, the package
  went from 4 files to 28 with **no
  refusal**. The prose counter in the same file's comment (`:23-30`, "34 -> 6,
  up from 33") is repointed with them, and it moves again on its own: deleting
  `collect-metric-keys.mjs` takes the tree to 28 files, so the counter reads
  "33 -> 6" once more — for a different reason than the 33 it already names.
  Spell out which tree each number is measured on.
- The two `.gitignore` negations repoint. Measured at this destination:
  `html-report/package.json` and `package-lock.json` are then swallowed by the
  blanket `*.json` rule at `:61`.
- `scripts/collect-metric-keys.mjs` is **deleted, not moved**. Its only output,
  `finding-gate/enumeration-js-metric-keys.tsv`, was retired; the reasoning is
  under P2 below, and the `references.tsv` row that carries it is 36. The rest
  of the four-hop chain stays: `metric-key-catalog.mjs` calls `fromRoot` three
  times for the live vitest, so `repo-root.mjs` moves with the directory. The
  deletion takes the directory to 28 files, which moves every count form that
  publishes 29.
- CI carries the path twice, in `cache-dependency-path` and an `npm ci` prefix.
- `composer.json` carries it as a literal `cd` three times, in `test:js`,
  `build:js` and `install:js`; `test:js` is inside `check:code`, and
  `install:js` is called by `scripts/init-environment.sh` — which is why the
  path lives here and not in that script.

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
7. No operational reference to the old path survives **in this package's own
   files**: `.gitattributes`, `.github/workflows/qmx.yml`, `.gitignore`,
   `composer.json` and the moved directory. The tree-wide grep is P4's —
   measured, 13 files outside the directory name the old path today (excluding
   `docs/` and `CHANGELOG.md`) and only five are P1's, so a tree-wide gate inside P1 is unpassable while P2 and P3
   are in flight. The figure was 15 until the retirement of
   `enumeration-js-metric-keys.tsv` took that file and the last old-path mention
   in `finding-gate/README.md` out of the set; neither was P1's, so the five
   stands. The first draft checked only `src/` and `composer.json`, which
   left the two CI occurrences unchecked by any package at all.
8. State, as a number, how many rows of each generated artifact this package
   expects to change. P4 compares the prediction.
9. Expected red, named so nobody repairs it from here: `architecture:check`,
   artifact freshness, and the governance controls owned by P2. Stage 01 leaves
   no control of its own behind: it adds none, so nothing here is orphaned.

### P2 — every place that enumerates the tree

**Files.** `scripts/generate-modular-architecture-test-inventory.php`,
`scripts/generate-rename-enumeration.php`,
`scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`,
`governance/DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest.php`,
`governance/.../RuleIdentifierLiteralGuardTest.php`.

`governance/PlanningRecords/PlanningRecordIsolationTest.php` was in this list
and is **not** a file of this package: since #100 it derives its population from
`git ls-files` rather than from a root list, so it follows the move with no
edit. Its plant at the new root stays, as DoD item 3 — that is a measurement,
not a repoint.

`finding-gate/enumeration-js-metric-keys.tsv` was in this set and has since been
retired, so this package no longer carries it. It was a plan-local enumeration
that reached `finding-gate/` by relocation rather than by earning a consumer:
nothing read it back, and the drift it was written to expose is caught
continuously by `metric-key-catalog.test.js`, which derives the catalog from the
PHP sources and never opened the TSV.

Nothing read its *content*, but it was not inert: `surfaces()` in
`generate-rename-enumeration.php` scans `finding-gate/` whole, with no
`excludeFiles`, so its 317 rows were counted as metric-key occurrences and its
272 test-fixture rows inflated that column. Removing it moved counts on 58 rows
of `enumeration-renames.tsv` and reddened `enumeration:renames:check` until the
file was regenerated. Row identity and the 350-row total did not move. Any
package here that adds or removes a file under `finding-gate/` owes the same
regeneration.

That makes its generator, `collect-metric-keys.mjs`, **P1's to delete** rather
than to move: its only output was that artifact. This does not retire the
four-hop chain — `metric-key-catalog.mjs` calls `fromRoot` three times to read
the PHP sources, the live test needs it, so `repo-root.mjs` stays and moves with
the directory. What it does retire is the only `fromRoot` **write**: after the
deletion the viewer reads from the repository root and never writes back to it.
Deleting it gives up an ad-hoc view the test does not reproduce — the `comment`,
`test` and `test-comment` buckets, and the not-in-catalog listing over test
files. That view was investigative, never a guard, and git history holds both
the script and its last output. The directory's file count follows: the "29
files" at `:5`, and "10 of the 29" in the overview and in
`enumeration/README.md`, become 28 and "10 of the 28" when this deletion lands.

`generate-modular-architecture-production-inventory.php` is **not** here: its
`git ls-files` scope is markdown only and names no viewer path. The first draft
claimed both generators carry scan-scope literals; only one does.

**Retiring the prescription — the branch is chosen, and the earlier basis for
choosing it was wrong.** `targetPath()` reaches
`tests/Reporting/HtmlTemplate/Tests/` for these 10 files through its last
branch, so "retire" has three possible mechanisms and the first draft named
none. The second draft chose to drop the three scan-scope literals and with them
`NON_MANIFEST_TEST_OWNERS['Reporting/HtmlTemplate']`, on the grounds that
registering the new root was impossible.

**That ground is refuted.** The draft claimed `actualToolingTestRootsOnDisk()`
globs only `scripts/*/tests` and `tools/*/tests`, so registration refuses
immediately. Measured: `TOOLING_TEST_ROOT_OWNERS` already carries `'governance/'`
— a key that glob never matches — and
`assertToolingTestRootRegistrationIsComplete()` filters exactly that key out of
the comparison at `:1313-1316`. The precedent for a root outside the glob's
shape exists and is in use.

The cost at stake is a live refusal: **today an eleventh test file under the
viewer makes `architecture:check` refuse by name** (`:1502`, actual rows against
declared). So the package **carries the declared-row contract over to the new
root** rather than deleting the only one. Dropping it is permitted only as a
named decision recorded in the ADR with the condition for revisiting — never as
a side effect of a mechanism nobody chose. A silent loss of a live refusal is
the one outcome this package may not produce, and a refuted blocker is not a
reason to accept one.

**What else changes.**

- `surfaces()` gains the new root. Prefer adding it to the **existing `src`
  surface** rather than opening a column: the total then stays 115 in the same
  place and there is no "moved column" to explain. `excludeFiles` also names
  `src/Reporting/Template/package-lock.json` by hand and follows.
- The shipping guard's `TREE`, and the prose counter in its docblock (`:14-17`,
  "all 33 of its entries") and at `:95` (`check-attr` on `Template/src/tree.js`)
  — the last of which no path sweep finds, because it spells the path the other
  way round.
- `RuleIdentifierLiteralGuardTest`'s existence-checked file list (it names
  `dev.html`), and the copy list in `createIsolatedProject()`, which fails
  loudly with PHPUnit exiting 2 inside the scratch project.

**Definition of Done.**

1. Both the test inventory and the rename enumeration exit 0 into a scratch
   directory. The production generator, if run, needs **two** flags —
   `--output-directory=` and `--qmx-output=` — or it renders the config into the
   live tree; the test generator takes the first only and refuses the second.
2. The shipping guard **measures**: plant one extra file at the new path, quote
   the refusal naming that file, restore from a copy taken before the plant.
   **This item can only be taken once P1 is in the tree** — until the
   `export-ignore` lines repoint, the guard reports every held-out viewer file
   as extra and a refusal naming the planted one is indistinguishable from that
   noise. Re-derive the floor on the tree P1 lands on rather than trusting a
   literal here: the viewer carries 29 tracked files now, against the 28 this
   sentence was first written for. Dependency between the packages'
   *acceptance*, not their files.
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
`src/Reporting/README.md`, `finding-gate/README.md`, `CHANGELOG.md`, **`AGENTS.md`
(which `CLAUDE.md` symlinks)** — it names the old path twice and both are
operational, a section heading and a "when modifying X, also run" instruction —
and any other operational document naming the old path.

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
6. The tree-wide sweep, which no single package can take:
   `! git grep -q 'src/Reporting/Template' -- ':!docs/adr/0002*' ':!docs/adr/0012*' ':!CHANGELOG.md' ':!docs/internal/plans/'`.
   The exclusions are the historical records P3 names; every other occurrence is
   operational and belongs to some package.

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
