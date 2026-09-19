# Stage 02 — the move, its registration addresses, and the records

Does not begin until stage 01 has landed and its control is green. Destination
is `html-report/` at the repository root, the whole directory, 28 files.

## Packages

P1 and P2 touch disjoint files and run in parallel. **Neither leaves the tree
green on its own** — the move refuses in 16 places and they are split across
both — so they are accepted together, not one after the other. A package here
passes its own Definition of Done while `composer check` is still red by
construction; saying so up front is what stops an executor from "fixing" it by
reaching into the other package's files.

P3 runs alongside. P4 is the orchestrator's and starts when P1, P2 and P3 are in.

### P1 — the move, and everything that refuses to run without it

**Files.** The directory itself; the PHP asset owner and the JS root module from
stage 01; `composer.json`, `.github/workflows/qmx.yml`, `.githooks/pre-commit`,
`scripts/init-environment.sh`, `Dockerfile`, `.dockerignore`, `.gitattributes`,
`.gitignore`.

**Move with `git mv` and commit it.** The shipping guard reads `git archive
HEAD`; a plain `mv` leaves HEAD untouched, the guard measures the old tree and
stays green. Measured.

**What changes.**

- The directory moves. The two owners' single constants follow it — that is all
  the path arithmetic there now is.
- The seven `export-ignore` lines repoint. They are inert at the wrong path and
  say nothing about it: measured, the package went from 4 files to 28 **without
  any refusal**.
- The two `.gitignore` negations repoint, and their new form is checked with
  `git check-ignore -v`, not by reading the file. Measured at this destination:
  a blanket `*.json` rule swallows the moved `package.json` once the negation
  stops matching.
- `.dockerignore` and `Dockerfile` follow. The root `/dist/` rule in both is
  anchored to the repository root and does **not** apply to `html-report/dist/`
  today — confirm that with `git check-ignore` at the new path rather than by
  reading, because if it ever does apply the shipped bundle leaves the image
  silently.
- `composer.json`'s `test:js` and `build:js` carry the path as a literal `cd`.
  `test:js` is inside `check:code`.
- CI carries it twice, in `cache-dependency-path` and an `npm ci` prefix.
- `scripts/init-environment.sh` installs no node at all — measured, so
  `composer test:js` does not work in the web environment today. **Do not fix
  that here**; note it and leave it. It is a real gap and it is not this move.

**Definition of Done.**

1. `bin/qmx check --format=html` exits 0 and its output is **byte-identical** to
   the same command's output on the base commit. Take the size and hash before
   moving anything; the report is the product, and equality is the strongest
   statement this package can make.
2. The composer package carries the same four files, at the new path and no
   others: `git archive HEAD html-report | tar -tf -`, compared against the
   base's four by name, not by count.
3. `git check-ignore -v` on the moved `package.json`, `package-lock.json` and on
   `html-report/dist/report.min.js` — each says what the base said for its old
   path. This is the check for the two silent ignore-rule breakages.
4. `composer test:js` and `composer build:js` exit 0.
5. No refusal text anywhere still advises the old path:
   `! git grep -q 'src/Reporting/Template'` over `src/` and `composer.json`.
6. Expected red, named so nobody repairs it from here: `architecture:check`,
   the generated artifacts' freshness, and the governance controls owned by P2.

### P2 — every place that enumerates the tree

**Files.** `scripts/generate-modular-architecture-test-inventory.php`,
`scripts/generate-modular-architecture-production-inventory.php`,
`scripts/generate-rename-enumeration.php`,
`scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`,
`governance/DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest.php`, and the
governance control that existence-checks `dev.html`.

**What changes, and the two that no sweep finds.**

- **`NON_MANIFEST_TEST_OWNERS['Reporting/HtmlTemplate']` carries `'rows' => 10`.**
  A hardcoded count over a generated artifact, and not an `assertCount`, so a
  sweep for assertions misses it. Its `reason` text also cites `04-packages.md`,
  deleted in `4438c105` — the row is stale in two ways.
- **`targetPath()` prescribes `tests/Reporting/HtmlTemplate/Tests/`** for those
  same 10 files, and `test-ownership.tsv:162-171` publishes it. This package
  **retires the prescription**, it does not merely repoint it: the overview says
  why that destination is rejected. Leaving it would publish a destination
  nothing intends to use.
- **`surfaces()` in the rename enumeration** gains the new root. This is the
  measured four-fifths coverage drop; its check is in P4, because it is a
  property of the regenerated artifact, not of this file.
- The scan-scope literals in both generators — the `git ls-files` path lists.
  These are the CLAUDE.md rows that fail **silently**.
- The copy list in `createIsolatedProject()` — this one fails loudly, with
  PHPUnit exiting 2 inside the scratch project.
- The shipping guard's `TREE` constant. Measured: with `TREE` stale the guard
  fails *before* it measures anything and reports that nothing was checked — a
  red test that proves nothing, which reads like a caught defect.

**Definition of Done.**

1. Both generators exit 0 into a scratch directory. The production generator
   needs **two** flags — `--output-directory=` and `--qmx-output=` — or it
   renders the config into the live tree; the test generator takes the first
   only and refuses the second.
2. The shipping guard **measures**: plant one extra file at the new path, quote
   its refusal naming that file, restore from a copy taken before the plant. A
   guard that refuses before measuring is the failure this item exists to
   exclude.
3. `! git grep -q 'Reporting/Template\|Reporting/HtmlTemplate'` over `scripts/`
   and `governance/`, with the retired prescription gone rather than repointed.
4. State, as a number, how many rows of each generated artifact this package
   expects to change. P4 compares the prediction; an unpredicted row is a defect.
5. Expected red until P4 regenerates: artifact freshness and anything reading a
   published artifact.

### P3 — the records

**Files.** A new ADR under `docs/adr/`, `docs/adr/README.md`,
`src/Reporting/README.md`, `finding-gate/README.md`, `CHANGELOG.md`, and the
remaining documents naming the old path.

- The ADR **supersedes** `docs/adr/0012`'s line 109, which is the only written
  authority on where the viewer lives. It records why the viewer leaves the
  PSR-4 root, why the whole directory moves rather than the source half, and why
  `tests/Reporting/HtmlTemplate/Tests/` was rejected.
- `finding-gate/README.md:52` is **already wrong before this work**: it calls
  `metric-key-catalog.test.js` a check of a TSV file, but the test does not read
  that file — it rebuilds the catalog from PHP sources, and no composer script
  regenerates or checks the TSV. Fix the sentence; do not extend the move to fix
  the gap it describes.
- `CHANGELOG.md` gets a `Changed` entry only if a consumer can observe this. The
  four shipped assets move inside the package, so a consumer resolving them by
  path does see it; a consumer using the CLI does not.

**Definition of Done.** No document names the old path; the ADR is indexed; the
`docs:check` strict build is green.

### P4 — regeneration and acceptance

1. Regenerate and publish the artifacts.
2. **The coverage oracle, which is the one the tree cannot fail on its own.**
   Re-derive the measurement that found the silent drop: count `health.overall`
   in the rename enumeration's `src`-equivalent columns before and after. The
   base is 115 with 93 from the viewer's files. After the move, the same 93 must
   be present under the new surface. A total that fell is the drop; a total that
   held **but moved column** is the intended outcome and must be stated as such.
3. Compare the artifact diff **by column**, against P1's and P2's predicted row
   counts. Not by total line count.
4. `composer architecture:check`, then the full `composer check` **from a clean
   clone** with copied `vendor`, `website/.venv` and `node_modules`. A green run
   in the working copy proves less; leftovers there have produced false green.
5. The report's byte-identity from P1's item 1, re-taken after everything has
   landed — P1 proved it mid-flight, and P2's generator changes cannot affect it,
   so a difference here is a defect rather than an expected move.

## Test plan

No new tests beyond stage 01's control. Every address above is already guarded
by something, or is guarded by nothing and is guarded by nothing for a reason
named in its row of `enumeration/references.tsv`.

The one thing worth adding is deliberately **not** added: a control asserting
"no file names the old path". It would be green forever after this work and
would never refuse again, which is the check-that-cannot-fail shape. The
existence-of-assets control from stage 01 already covers the case that matters —
an asset the formatter cannot find.

## Re-derive before executing

Every number above was measured for a root `frontend/` destination, on
`4a701bb0`, and the packaging and ignore-rule findings **depend on the
destination name**. The chosen name is `html-report/`. Re-run the ignore-rule
and package measurements at that name before P1 states its expectations — a
carried-forward number is the inherited table this repository keeps being bitten
by.
