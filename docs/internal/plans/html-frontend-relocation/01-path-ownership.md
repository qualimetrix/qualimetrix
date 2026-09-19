# Stage 01 — the third copy of the hop gets the guarantee the other two have

Lands before anything moves. It adds no control, no PHP, and no file outside the
viewer's own `scripts/` directory. Two review rounds cut it down to this, and
the cutting is the finding.

## What the two rounds established

**Round 1 killed a PHP value object.** The shipping guard derives its
expectation by regex over the formatter's source
(`HtmlReportShipsOnlyWhatItReadsTest.php:78`) and refuses on an empty result, so
removing the concatenation reddens it — and that guard's file belongs to stage
02, which could then never start. Separately, every production declaration is
pinned in the manifest by name, so a new class is also a manifest change and an
artifact regeneration.

**Round 2 killed the control that replaced it.** Three findings, each measured:

- A new file under `governance/` is itself a row in `test-ownership.tsv` — the
  scan scope includes `governance/`, which carries 142 rows today. So the
  control could not have left the artifacts untouched either. Same defect as
  round 1's, one inventory over.
- Its anti-tautology plant was impossible. Walking up to the directory holding
  `composer.json` beside `.gitattributes`, and `\dirname(__DIR__, 2)` from a
  governance file, return **the same string** — measured. The plant could never
  produce the outcome it claimed, so two of four plants tested one thing.
- Pinning the control's PHP assertion to `src/Reporting/Template` made it a
  control the move must edit — destroying the very property the stage exists
  for.

**Then the question nobody had asked: is either distance actually unguarded?**
Measured, by planting rather than by reading:

| Distance                    | Plant                      | Result                                |
| --------------------------- | -------------------------- | ------------------------------------- |
| `HtmlFormatter.php:38`      | depth 2 → 3                | `HtmlFormatterTest`: **8 errors**     |
| `metric-key-catalog.mjs:17` | move to a root destination | `ENOENT` under vitest in `check:code` |
| `collect-metric-keys.mjs`   | —                          | **nothing executes it**               |

Both live hops are already guarded, by tests that already run, without a literal
path anywhere in the assertion. The repository does not need a new control. It
has one copy of the hop that nothing exercises.

## What changes

The two JS modules stop computing hops; one module beside them owns it.

```
// src/Reporting/Template/scripts/repo-root.mjs
export const REPO_ROOT;            // resolved once, from this module's own place
export function fromRoot(...parts);
```

Its location is fixed here rather than left to the executor: beside its two
consumers, so stage 02 moves it with them and the hop count stays one.

That is the whole change. `collect-metric-keys.mjs` then inherits the guarantee
`metric-key-catalog.mjs` already has — the module it imports is the one a
running test exercises, so a wrong root reddens `composer test:js` whichever
consumer is executed.

**Node is still this stage's business.** `scripts/init-environment.sh` installed
neither node nor npm — measured, 0 mentions — so `composer test:js` could not run
in the web environment, and the guarantee above was exactly what did not hold
there. CI was already fine (`qmx.yml:135-142` sets up node 22). The install goes
into that script here, because this stage is where the guarantee starts being
relied on.

The path stays out of that script. It installs node and then calls
`composer install:js`, so the only `src/Reporting/Template` literal is the one
in `composer.json` beside `test:js` and `build:js` — a file stage 02 already
edits. Writing `cd src/Reporting/Template` into the shell script instead would
hand stage 02 an address its enumeration records as absent. For the same reason
the "already installed" guard lives in `install:js` rather than in the script:
the script cannot test for `node_modules` without naming the path.

## Definition of Done

Negative checks are written as refusals: `grep -c` exits 0 when it finds the
forbidden string and 1 when the file is clean, so a gate phrased "returns 0" is
green on a dirty tree — measured. And `git grep -E` does not honour `\b` here,
returning nothing where `grep` returns matches; use `-P` or `-wE`.

Every `git diff` below names `main...HEAD`. A rangeless `git diff` compares the
worktree, so on a committed branch it is empty whatever the branch contains —
an item phrased that way passes by being run late.

1. One JS hop chain of this spelling remains, and it is the new module's:
   `git grep -lP "\.\.'\s*,\s*'\.\." -- '*.mjs' '*.js' ':!src/Reporting/Template/scripts/repo-root.mjs'`
   prints nothing, while the same command *without* the exclusion prints
   `repo-root.mjs` — run both, because a pattern that matches nowhere also
   prints nothing. Measured: today the unexcluded form prints exactly the two
   files this stage collapses. The claim is bounded by the pattern: it reads
   adjacent single-quoted `'..'` segments and would not see `dirname(dirname(…))`
   or a double-quoted spelling. `git grep` reads tracked content, so stage the
   new file before running it.
2. **The surviving hop is guarded, proved by planting:** change the new module's
   hop, confirm `composer test:js` refuses, quote it, restore from a copy taken
   before the plant. Items 1 and 2 together are the guarantee this stage
   delivers — one hop, and a running test exercises it.
3. No PHP declaration is added or changed. Read the hunks, not the file list:
   `git diff main...HEAD -- '*.php'` may touch comments only. The oracle is
   `composer architecture:check` staying green — that is what actually proves no
   manifest row and no artifact regeneration, and "no PHP files changed" was
   only ever a proxy for it. `composer cs-check` too, once a PHP file is in the
   diff at all.
4. No new file outside the viewer:
   `git diff --name-only --diff-filter=A main...HEAD` names only
   `src/Reporting/Template/scripts/repo-root.mjs`. In particular nothing under
   `governance/` or `src/`, so no manifest declaration and no generated artifact
   row — the trap both earlier drafts fell into. This item cannot catch a new
   *line* in an existing file, so it does not stand in for item 5.
5. Stage 02's records still describe the tree. A stage whose purpose is to
   leave stage 02 a correct map does not get to leave it a stale one. **Derive
   the list of records to check; do not work from the list below.** Adding one
   file to the viewer moved a count in five documents and shifted six line
   anchors in `references.tsv` itself, and the first pass over this item found
   only the first four — a closed list is how the rest were missed.

   Derive it: for every file this branch touches, re-read every `references.tsv`
   row anchored at it, because a row's `where` field is a line number that the
   branch may have moved; and sweep the plan directory and the code-side records
   for count-form prose, which no assertion reads —

   ```
   git diff --name-only main...HEAD            # rows anchored here may have shifted
   grep -rnE '\b(2[0-9]|3[0-9]|6[0-9]|7[0-9]|9[0-9])\b' docs/internal/plans/html-frontend-relocation/ .gitattributes
   ```

   Judge each hit by subject rather than by the number matching — most hits are
   line numbers and unrelated totals. Re-derive every survivor with a command,
   and stamp it with the tree it was taken on, the way `measured-breakage.md`
   does: a stamped number goes stale visibly, an unstamped one does not.
6. `composer check` green from a clean clone with copied `vendor`,
   `website/.venv` and `node_modules`, on a machine with node; and the same
   clone with `scripts/init-environment.sh` run from scratch reaches a node that
   satisfies item 2.

   **Run the script with `CLAUDE_CODE_REMOTE=true`.** Without it the script
   returns at line 43 with "Script running locally", and every run below is a
   no-op that passes this item having tested nothing.

   **Start from a container that already carries an *older* node**, not from one
   with none: the install is gated on the major version, and a run starting from
   an empty PATH exercises the absent case only. Run 1 must replace it and
   report the required major.

   **Then run the script a second time.** It must report node as already present
   and `install:js` must say it skipped, in those words — the script only sees an
   exit code, so the distinction has to come from `install:js` itself or this
   half of the item cannot fail. Plant a sentinel file under `node_modules/`
   before the second run and confirm it survives: `npm ci` deletes the tree
   before fetching, so an unguarded install turns a flaky registry into a broken
   workspace that still logs success.

## What execution established

- **The plan's stated hazard was wrong, and the DoD inherited the error.** This
  stage was justified by the third copy writing "outside the repository after
  the move". Measured: a wrong hop makes `writeFileSync` target a directory that
  does not exist, so it refuses with `ENOENT` and writes nothing. An item asking
  the consumer to "fail rather than write outside the repository" is therefore
  satisfied before and after the change and distinguishes nothing.
- **What does distinguish them, measured on two revisions.** Before: breaking
  only `collect-metric-keys.mjs`'s own hop left `composer test:js` green, 150
  tests passed, exit 0. After: breaking the single hop makes it exit 1. That
  pair is the evidence the stage bought something; it needs two revisions, which
  is why it is recorded here rather than as a DoD item.
- **The real cost of the third copy** is not a stray write but silence: the copy
  can be wrong for as long as nobody runs it, and nothing runs it.

## Test plan

No new tests and no new control. The plant in item 2 and the two-revision pair
recorded above are the evidence, and they exercise tests that already exist. Adding a governance
control here would cost a manifest row, an artifact regeneration and a
`testSuitePrefixTable()` entry to assert something two running tests already
assert — which is how both earlier drafts of this stage grew.
