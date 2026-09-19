# How this enumeration was obtained, and what it cannot see

Two passes, run in parallel and deliberately not shown each other's results. One
agent filling both a table and its own check agrees with itself; that is the
failure this split exists to avoid.

| File                   | Pass             | Rows |
| ---------------------- | ---------------- | ---- |
| `references.tsv`       | swept by channel | 69   |
| `measured-breakage.md` | move carried out | 17   |

## `references.tsv` — swept by reference channel

Columns: `channel`, `file`, `where`, `form`, `breaks`, `no`.

Channels swept: php-runtime, string-literal, config (build, vcs, static,
container, ci, hooks), di-autoconfig, frontend, generator, artifact, manifest,
selfcheck, governance, dist-package, docs-prose.

**Tools.** `git grep` by path; `git grep -wE` on the bare word, which found a
fragment form (`str_contains($relativePath, '/Template/')`) that a full-path
sweep misses; a separate sweep by *file name* (`report.css`, `dist/report.min.js`,
`collect-metric-keys`), because the computed path contains no literal; a sweep
for `assertCount(` and for "N rows" prose, the hardcoded-count form; a sweep for
`npm|node|cd src/`, the command-string form; a sweep for `\.\./|__dirname|
fileURLToPath|resolve\(` over `*.js` and `*.mjs`; `git archive | tar -tf` for
what actually ships.

**Cannot see, as found by review rather than declared up front.** The sweep
answered CLAUDE.md's row "`ScratchPathsCarryRealEntropyTest` — `ROOTS`, **and
every other control that carries its own root list**" by checking only the
control CLAUDE.md names. It therefore missed `PlanningRecordIsolationTest`,
whose own root list includes `src` and which reaches 23 of the viewer's files by
extension today and none after the move, without refusing. A row that names an
open-ended class cannot be closed by answering its example; the sweep read its
own answer as satisfying the row.

Two rows of that table were also walked but not recorded, and both are N/A:
`testSuitePrefixTable()` and `currentSuite()` in the test inventory name the
viewer nowhere. Recorded now, because a row-by-row claim with two rows missing
is a claim about a set that was not fully enumerated.

Review re-swept that class and found no further member: `BaselineCountPublication`
filters `.md` (the viewer has none), the rule-option and channel controls filter
`.php`, and `promise-effect` analyses `src` for PHP only.

**Also cannot see.** Untracked and ignored trees (`node_modules/`, local
settings); the contents of the generated `dist/report.min.js`, which matters
because the build writes those tracked files and nothing here proves the bundle
rebuilds byte-for-byte; identifiers assembled from fragments; paths resolved
from CI environment or secrets. Every `breaks` verdict here is read off the code
rather than executed, except rows marked MEASURED.

**A contradiction this file used to carry.** The tools paragraph above credits a
bare-word sweep with finding the fragment form `str_contains($relativePath,
'/Template/')`, while this section used to claim the fragment forms `'Template'`
and `'/dist/'` were never swept separately. Both could not be true, and the gap
between them is exactly where `PlanningRecordIsolationTest` — which matches on
`'/dist/'` — went missing. The bare-word sweep ran; it was not run against the
governance controls' own root lists.

**A defect in the method, found and closed during the sweep:** the first pass
over the JS metric-key enumeration ran with an include list that excluded
`*.js` and `*.mjs`, which hid the main finding.

## `measured-breakage.md` — derived by carrying the move out

A real copy of the tree (`rsync -a`, with a genuine `vendor/` rather than a
symlink — a symlinked `vendor` resolves PSR-4 back into the original tree and
produces false green), the directory moved with `git mv` **and committed**,
then each command run and its verbatim first refusal recorded.

Committing matters: the shipping guard reads `git archive HEAD`, so an
uncommitted move leaves it measuring the untouched HEAD and staying green.

13 of 17 breakages are reproduced this way; 4 are derived from code and say so.

**Not covered:** a CI run; `docs:check`; `composer gate` and `gate:controls`;
the benchmark, health and controls stands; the Docker image build. **And all of
it was measured for a root `frontend/` destination** — the packaging and
ignore-rule findings depend on the name, so stage 02 re-derives them.

## Where the two passes disagreed

Once, and substantively.

- **The manifest.** The sweep reported it as knowing the directory. It does not:
  0 occurrences of either spelling. Only 10 of the 28 files appear in any
  inventory, via `test-ownership.tsv:162-171`. The experiment is right, verified
  directly against the manifest.
- **The hardcoded count.** The sweep's `assertCount(` pass found five counters
  and none of them about this subject — including `assertCount(28, …)`, whose
  28 coincides with the file count and is about a different artifact entirely.
  The real counter is `'rows' => 10` in `NON_MANIFEST_TEST_OWNERS`, which is not
  an assertion and which only the experiment surfaced.

Both point the same way: a sweep answers "where is this named", and the question
that decides this work is "what stops working". They are not the same question.
