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
has one copy of the hop that nothing exercises, and that copy would have written
its output outside the repository after the move.

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

**Node is still this stage's business.** `scripts/init-environment.sh` installs
neither node nor npm — measured, 0 mentions — so `composer test:js` does not run
in the web environment, and the guarantee above is exactly what does not hold
there. CI is fine (`qmx.yml:135-142` sets up node 22). The install goes into
that script here, because this stage is where the guarantee starts being relied
on. Stage 02 explicitly does not touch it.

## Definition of Done

Negative checks are written as refusals: `grep -c` exits 0 when it finds the
forbidden string and 1 when the file is clean, so a gate phrased "returns 0" is
green on a dirty tree — measured. And `git grep -E` does not honour `\b` here,
returning nothing where `grep` returns matches; use `-P` or `-wE`.

1. Exactly one JS hop chain remains, and it is the new module's:
   `git grep -lP "\.\.'\s*,\s*'\.\." -- '*.mjs' '*.js' ':!src/Reporting/Template/scripts/repo-root.mjs'`
   prints nothing. Measured: today it prints exactly the two files this stage
   collapses. The pattern tolerates absent whitespace — the current call spells
   it `'..', '..'` and nothing enforces that spelling.
2. **The surviving hop is guarded, proved by planting:** change the new module's
   hop, confirm `composer test:js` refuses, quote it, restore from a copy taken
   before the plant.
3. **The formerly unguarded consumer is now covered, proved the same way:**
   with the module's hop wrong, `collect-metric-keys.mjs` must also fail rather
   than write outside the repository. This is the item that states what the
   stage bought.
4. No PHP changes: `git diff --name-only -- '*.php'` is empty.
5. No new file outside the viewer: `git diff --name-only --diff-filter=A` names
   only `src/Reporting/Template/scripts/repo-root.mjs`. In particular nothing
   under `governance/` or `src/`, so no manifest declaration and no generated
   artifact row — the trap both earlier drafts fell into.
6. `composer check` green from a clean clone with copied `vendor`,
   `website/.venv` and `node_modules`, on a machine with node; and the same
   clone with `scripts/init-environment.sh` run from scratch reaches a node that
   satisfies items 2 and 3.

## Test plan

No new tests and no new control. The two plants in items 2 and 3 are the
evidence, and they exercise tests that already exist. Adding a governance
control here would cost a manifest row, an artifact regeneration and a
`testSuitePrefixTable()` entry to assert something two running tests already
assert — which is how both earlier drafts of this stage grew.
