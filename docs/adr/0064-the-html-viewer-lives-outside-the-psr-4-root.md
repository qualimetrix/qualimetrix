# 0064. The HTML Viewer Lives Outside the PSR-4 Root

**Date:** 2026-09-20
**Status:** Accepted
**Supersedes:** the HTML-viewer placement prescription in
[ADR 0012](0012-hybrid-architectural-direction.md) — that record's
substantial/thin hybrid direction was already superseded by
[ADR 0022](0022-capability-oriented-modular-monolith.md), but its placement
sentence was not, and it was the only written authority on where the viewer
lives.
**Related:** [0002 — Interactive HTML Report](0002-html-report.md),
[0016 — Subject Cohesion](0016-subject-cohesion.md),
[0022 — Capability-Oriented Modular Monolith](0022-capability-oriented-modular-monolith.md)

## Context

The browser program that renders `--format=html` is an npm project: its own
`package.json` and lockfile, its own `node_modules`, vite as a bundler, vitest
as a test runner, and a `dist/` of built bundles committed to git so that a
consumer needs no Node.js at run time. It lived at `src/Reporting/Template/`.

`src/` is not a neutral directory. It is a contract with composer's autoloader:
a PSR-4 root whose every path is expected to be a namespace and whose every
file is expected to be a class the autoloader can resolve. The viewer's 29
tracked files were none of those things. What sat inside that root was a foreign
lifecycle — installed by `npm ci`, built by vite, tested by vitest, released by
committing a bundle — inside a tree owned end to end by a PHP tool.

ADR 0012 examined this placement once and kept it, on the question it was asking
at the time: whether HTML Report was complex enough to warrant formalizing as a
vertical slice. It concluded not, and recorded as an operational consequence
that the viewer "stays in its current `src/Reporting/Template/` layout". That
answer is still correct about the question it answered — the viewer is a
single-stage renderer with no configuration loader of its own, and it should not
grow a `{Domain, Configuration, Processing, Rules}` skeleton. It is the wrong
answer to a question ADR 0012 never asked: whether an npm project belongs inside
a PSR-4 autoload root at all.

Two further things were true and unrecorded.

**The viewer's placement was held by two hardcoded distances, not by a name.**
`HtmlFormatter` resolved the asset directory by walking a fixed number of
parents from its own file, and the viewer's build resolved the repository root
the same way in the other direction. Neither encoded a destination. Stage 01 of
this work (#97) collapsed the viewer's two copies of that walk into a single
module, `scripts/repo-root.mjs`, so that the move would be judged by a check
that was already passing rather than by one written in the same breath as the
change it judges.

**A prescription already existed to move the viewer somewhere else.** The test
inventory's `targetPath()` prescribed `tests/Reporting/HtmlTemplate/Tests/` for
10 of the viewer's files and published that prescription in
`test-ownership.tsv`. It was a leftover of the retired test-structure campaign,
and its stated reason cited a planning document that has since been deleted.

## Decision

**The viewer moves out of the PSR-4 root, whole, to `html-report/` at the
repository root.**

### Why it leaves `src/`

Because `src/` means "PHP this tool autoloads", and the viewer is not that. Its
presence there made the root's meaning a half-truth that every reader and every
tree-walking control had to learn as an exception. The alternative — leaving it
and documenting the exception better — keeps a foreign lifecycle inside a
tool-owned tree so that no path has to change; that is paying a permanent
comprehension cost to avoid a one-time move.

### Why the whole directory, not a split

A split was available: leave `report.html`, `report.css` and `dist/` behind in
`src/Reporting/` because PHP reads them at run time, and move only the sources,
tests and build tooling. **Rejected.** It satisfies the letter of "no npm
project inside `src/`" while splitting one subject across two roots, so that
editing the CSS and editing the JS that styles against it become edits in two
places with no name in common. ADR 0016's co-change test refuses that directly:
a change to one subject should touch one directory. The four shipped files are
the viewer's build output and its public surface; they are not a second subject.

### Why `html-report/` and not `frontend/` or `assets/`

Subject cohesion. `frontend/` and `assets/` name the technology and the role —
"the directory of things written in JavaScript", "the directory of files that
are not code". Under ADR 0016's naming test, "this directory is about ___" can
only be completed for either with a technical role, which is the definition of a
role bucket. `html-report/` completes it with the artifact the directory
produces: this is about the HTML report. It stays the right name if the bundler
changes, if D3 is replaced, or if the CSS is generated rather than written.

It joins `website/`, `benchmarks/`, `finding-gate/` and the other root-level
subjects that are deliberately not PSR-4 roots. That class already exists and is
not small; this adds a member rather than inventing a shape.

### Why `tests/Reporting/HtmlTemplate/Tests/` was rejected

This was not hypothetical — it was the prescription in force, published in a
generated artifact. It is rejected on three grounds, the first of which is
decisive and by itself sufficient.

1. **It would stop the report shipping.** `.gitattributes` carries an
   `export-ignore` rule on the repository-root `tests/` directory. Landing the
   viewer under `tests/` would place the four run-time assets behind that rule,
   they would leave the composer dist package, and `--format=html` would fail
   for every consumer — silently at build time, loudly and only at the
   consumer's run time. This argument was absent from the first draft of the
   plan that proposed the move, which is itself worth recording: the destination
   was nearly chosen on tidiness grounds by a process that had not asked what
   ships.
2. **It is a test root, and the viewer is not a test.** The prescription
   addressed ten files — those that happen to be tests or test configuration —
   and was silent about the other nineteen, which are production sources, build
   configuration and the shipped assets themselves.
3. **The prescription's own reason had expired.** It cited a planning document
   deleted in `4438c105`.

### What this decision deletes, and the coverage that costs

`scripts/collect-metric-keys.mjs` is deleted rather than moved. Its only output
was `finding-gate/enumeration-js-metric-keys.tsv`, retired in #98 because
nothing read it back: the live test that appeared in its `consumer` column,
`metric-key-catalog.test.js`, calls `loadCatalog()` and re-derives the catalog
from the PHP sources on every run — it never opened the file.

**Something is lost, and it is not nothing.** The script produced an ad-hoc
investigative view that no test reproduces: family-shaped metric-key literals
separated into `code`, `comment`, `test` and `test-comment` buckets, plus a
listing of literals not in the catalog across test files as well as sources.
The live test covers the `code` bucket only, and covers it more strongly —
semantic membership re-derived per run rather than byte equality against a
stored copy. The other three buckets and the test-file listing are given up.

They were never a guard: nothing executed the script, no composer script
regenerated or verified its output, and a freshness control over it would have
reddened on every test edit touching a metric literal, since 272 of its 317 rows
were test fixtures including a deliberate non-member. Git history holds both the
script and its last output, so reviving the view is a checkout away. The
judgement is that an unexecuted investigative script is worth less than the
absence of a file that looks like a guard and is not one — but the loss is
recorded here rather than implied to be zero.

The four-hop chain the script shared is **not** retired. `metric-key-catalog.mjs`
calls `fromRoot` three times to read `MetricName.php`, `AggregationStrategy.php`
and `HealthDecompositionCatalog.php`, the live vitest needs it, and
`repo-root.mjs` moves with the directory. What the deletion does retire is the
only `fromRoot` **write**: after it, the viewer reads from the repository root
and never writes back to it.

### How the prescription is retired, and the refusal that dies with it

The viewer's 10 test files are **registered in `TOOLING_TEST_ROOT_OWNERS`** —
`html-report/tests/`, plus `html-report/package.json` and
`html-report/vite.config.js` as file keys, because those two sit beside `tests/`
rather than under it. `targetPath()` then returns each file's own path, the
inventory publishes `Retain at the materialized subject-owned path.`, and the
prescription toward a `tests/` destination is gone rather than renamed.

**Registration is possible, and the claim that it was not is refuted.** An
earlier draft held that the new root could not be registered, because the
tooling-root completeness check globs only `scripts/*/tests` and `tools/*/tests`.
`TOOLING_TEST_ROOT_OWNERS` already carries `'governance/'`, a key that glob never
matches, and `assertToolingTestRootRegistrationIsComplete()` filters exactly that
key out of the comparison by name. The precedent for a registered root outside
the glob's shape exists and is in use — and it is what makes the retirement
reachable at all. Everything below follows from taking it.

**`NON_MANIFEST_TEST_OWNERS['HtmlReport']` is dropped, and it could not have been
kept.** Not a preference: the two constants are mutually exclusive here.
`assertTestOwnersAreManifestOwners()` skips every row whose `current_path` and
`target_path` both fail to start with `tests/`, and that is precisely what
registration produces — the rows are retained in place, so all 10 `continue`
before reaching the counter. The declared count would sit at 10 against an
`$allowed` that nothing can increment, and the entry would refuse
unconditionally, whatever number was written in it. That same skip is why every
other registered tooling root is absent from the constant; the viewer is not an
exception to the rule, it has joined it.

The entry's own stated reason had also expired. It cited `04-packages.md`, the
planning document deleted in `4438c105`: the guard was holding a seat for an
unresolved relocation, and this decision resolves it.

**A live refusal is given up, and naming it is the point.** Until now an
eleventh test file under the viewer made `composer architecture:check` refuse by
name, the actual count disagreeing with the declared 10. It no longer does: an
eleventh test file lands, is inventoried, and nothing says anything. Nothing
else in the tree replaces that signal — it was the one control that noticed the
viewer's test population changing size.

This ADR holds that a live refusal lost as a *side effect of a mechanism nobody
chose* is the one outcome this work may not produce, and that standard is met by
recording the loss, not by the loss being small. What was nearly produced here
is the failure that standard exists for: carrying the contract over mechanically
repointed its destination to `tests/HtmlReport/Tests/` — a target this record
rejects on three grounds a few paragraphs above — and the generated artifact was
*fresh and wrong at the same time*, with `architecture:check` green over it. A
guard renamed is not a guard retired.

**The condition for revisiting:** if the viewer's test files ever become mapped
to a manifest owner, or otherwise re-enter the population
`assertTestOwnersAreManifestOwners()` counts — anything that puts `tests/` at
the head of their `current_path` or `target_path` again. Then a declared row
count is enforceable once more and the reason for dropping it has lapsed. A
count re-declared while the rows stay outside that population would refuse
unconditionally, so it must not be re-added on the strength of wanting the
signal back.

One shape worth flagging for the next such root: registering the viewer needed
two **file**-shaped keys beside the directory key, in a map named for test
roots. `package.json` and `vite.config.js` are test configuration that lives at
the project's root rather than inside its test directory. Any further
root-level, non-PSR-4 project will meet the same thing, and the map will stretch
the same way — until enough of them accumulate that it is worth asking whether
the map is about roots or about paths.

### `html-report/README.md` is an unowned document, accepted

AGENTS.md asks each directory for a `README.md`, and the new root has one. It
falls outside `documentationInventory`'s pathspec, which is the root `README.md`,
`AGENTS.md`, `CLAUDE.md`, `CHANGELOG.md`, `docs/**/*.md`, `website/docs/**/*.md`
and `src/**/README.md`. So it is a documentation file that
`documentation-ownership.tsv` does not judge.

This creates no new silent class; it enlarges an established one. The pathspec
reaches component READMEs only under `src/`, so every root-level subject's README
is already outside it — `benchmarks/`, `directive-audit/`, `finding-gate/`,
`input-doors/` and `promise-effect/`. Of the seven root-level directory READMEs
in the tree, only `docs/README.md` is judged, and it is judged by the
`docs/**/*.md` glob rather than by being a component README. The viewer's is the
sixth unjudged one.

Accepted rather than fixed. Widening the pathspec to `*/README.md` enrols every
root-level directory including vendored and generated ones; naming the subjects
by hand is a literal list that goes stale in the same silence it is meant to
remove. That the class already holds five members is itself the argument: a gap
this size is a property of the pathspec's shape, and closing it for one new
member would leave the shape untouched.

**The condition for revisiting:** when one of these READMEs starts carrying a
claim some control elsewhere depends on — a threshold, a count, a declared
owner. Until then these documents are read by people, and a stale sentence in
one costs a reader a minute rather than costing a check its verdict.

## Consequences

- `src/` is again exactly what it claims to be: one PSR-4 root of PHP this tool
  autoloads, with no exception a reader has to learn.
- The dependency between PHP and the viewer remains in two directions, and
  neither is a cycle at run time. **At run time** `HtmlFormatter` reads four
  files from `html-report/` — `report.html`, `report.css`, `dist/report.min.js`
  and `dist/d3.min.js`. That is the viewer's entire public surface; the
  `export-ignore` rows hold the other 25 files out of the composer package.
  **At build time** the viewer parses three PHP files under `src/Analysis/` to
  derive its metric-key catalog, through the single hop in `repo-root.mjs`. The
  build-time direction never runs at a consumer's site, and the run-time
  direction never reads a source file.
- A consumer who resolves the shipped assets by path sees them move. The paths
  inside the composer package change; the four file names, and the fact that
  these four and no others ship, do not.
- `composer test:js`, `composer build:js` and `composer install:js` are where a
  contributor meets the path, and they hold it so that nobody types it. They are
  not its only address: the packaging and ignore rules and the CI workflow each
  carry it too, and a rename of this root is an edit in every one of them.
- The prescription in `test-ownership.tsv` toward
  `tests/Reporting/HtmlTemplate/Tests/` is retired: the viewer's 10 test files
  are retained at their own paths. The declared-row refusal that counted them
  does not survive — an eleventh test file under the viewer is now inventoried
  in silence.
- ADR 0012's placement sentence is superseded, not edited. Its reasoning about
  vertical-slice formalization still stands and is untouched: this decision says
  nothing about whether HTML Report should grow a slice skeleton, and the answer
  to that remains no.
