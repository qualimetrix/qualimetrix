# Moving the HTML report's viewer out of the PSR-4 root

## The problem, in one sentence

The browser program that renders the HTML report lives at
`src/Reporting/Template/` — inside a tree declared as a PSR-4 autoload root,
which it is not part of — and the two paths that tie it to the rest of the
repository are hardcoded hop counts that no check reads.

## What is actually there

28 tracked files, one `package.json`, one lifecycle. Four of them ship in the
composer package (`report.html`, `report.css`, `dist/report.min.js`,
`dist/d3.min.js`); the other 24 are held out of it by seven `export-ignore`
lines. That split is already correct and was landed by #87 — **this work is not
a packaging fix**, and a plan that re-argues packaging is solving a problem that
no longer exists.

## The tie is in two directions, and neither is a literal

| Direction            | Where                               | Shape                                | Breaks |
| -------------------- | ----------------------------------- | ------------------------------------ | ------ |
| PHP reads the assets | `HtmlFormatter.php:38`              | `\dirname(__DIR__, 2) . '/Template'` | loud   |
| the viewer reads PHP | `scripts/metric-key-catalog.mjs:17` | `resolve(__dirname, '..' × 4)`       | loud   |

The second was not in the brief and is the reason this is not a file move. The
viewer's build derives its metric-key catalog by parsing three PHP files under
`src/Analysis/`; `metric-key-catalog.test.js` calls it under vitest, and
`composer test:js` is part of `check:code`. So the dependency points **both
ways**, and both ends encode a distance rather than a destination.

A third hop of the same shape sits in `scripts/collect-metric-keys.mjs`. Nothing
runs it, so it would stay wrong quietly until the day someone regenerates by
hand.

## The decision: make the distance checkable, then move

Moving the directory changes both distances. Editing the literals when the move
happens moves the hazard instead of removing it — CLAUDE.md names a stale
`\dirname(__DIR__, N)` as a failure that resolves to a directory above the
repository and returns nothing rather than refusing.

So **stage 01 makes both distances checkable and lands before anything moves.**
Then the move is judged by a control that was already green, rather than by one
written in the same breath as the change it judges.

**Stage 01 turned out to be almost nothing, and that is the finding.** Two
review rounds cut it down. It first proposed a PHP value object owning the asset
paths; the shipping guard reads those paths by regex out of the formatter's
source, so the refactor reddened it. It then proposed a governance control; a
new file under `governance/` is itself a row in a generated artifact, and the
control's anti-tautology plant was impossible — measured, the marker walk and
`\dirname(__DIR__, 2)` from a governance file return the same string.

Then the question nobody had asked. **Both live hops are already guarded,
measured by planting:** a wrong PHP depth gives `HtmlFormatterTest` 8 errors, and
a wrong JS root gives `ENOENT` under vitest inside `check:code`. Neither
assertion names a path. What is unguarded is a *third* copy of the JS hop in
`collect-metric-keys.mjs`, which nothing executes and which would have written
its output outside the repository after the move. Stage 01 is therefore one JS
module that collapses the two copies into one, so the unexecuted consumer
inherits the executed one's guarantee — no control, no PHP, no manifest row.

**Destination: `html-report/` at the repository root**, the whole directory, one
place. The owner's decision is that the viewer leaves the PSR-4 root; keeping
the shipped assets behind in `src/Reporting/` would satisfy the letter and split
one subject across two roots.

**Rejected: `tests/Reporting/HtmlTemplate/Tests/`.** Not hypothetical —
`targetPath()` already prescribes it for 10 of the 28 files and
`test-ownership.tsv:162-171` publishes it. The decisive argument is not
tidiness: `.gitattributes:14` carries `/tests/ export-ignore`, so landing there
would drop the four shipped assets out of the composer package and
`--format=html` would die for every consumer. It is also silent about the other
18 files. The prescription is a leftover of the retired test-structure campaign,
and its stated reason still cites `04-packages.md`, deleted in `4438c105`.
Stage 02 retires it, and names the coverage that retirement costs.

## What the move costs, measured rather than estimated

**Two populations, and conflating them was a defect in the first draft.** They
answer different questions and their totals are not comparable:

| Population                                    | Total | Shape                                                  |
| --------------------------------------------- | ----- | ------------------------------------------------------ |
| addresses swept, `enumeration/references.tsv` | 69    | 16 loud, 23 silent, 30 unaffected — read off the code  |
| breakages measured, by carrying the move out  | 17    | 13 reproduced with a verbatim refusal, 10 of them loud |

The swept column is a property of the sweep; the measured column is a property
of the tree. Where they disagree about what stops working, the measurement wins
— the enumeration's own README says so, and the first draft then argued package
boundaries from the swept number anyway.

Four silent addresses decide the packaging of this work:

- **`surfaces()['src']` in the rename enumeration.** Measured with the
  generator's own predicate: 115 whole-identifier occurrences of
  `health.overall` in the `src` surface, **92 of them from the viewer's files**.
  The surface excludes `dist/` and `package-lock.json` by name, which is why a
  casual line count gives 93 — the first draft carried that 93 into its
  acceptance oracle, where it would have refused correct work.
- **`NON_MANIFEST_TEST_OWNERS['Reporting/HtmlTemplate']` carries `'rows' => 10`**,
  compared against the actual count at `:1502`. It is the live control that
  turns a silent scan-scope pathspec into a loud refusal for this owner. Not an
  assertion, so an `assertCount` sweep misses it; found only by carrying the
  move out.
- **`PlanningRecordIsolationTest` scans a root list of its own** — `bin`,
  `governance`, `scripts`, `src`, `tests` — and today reaches 23 of the viewer's
  files by extension. After the move it reaches none, **with no refusal**: a
  control that stays green on a shrunken population. The first draft's
  enumeration closed CLAUDE.md's "and every other control that carries its own
  root list" row by answering only about the control CLAUDE.md names, which is
  the row reading itself as satisfied.
- **Two `.gitignore` negations and seven `export-ignore` lines go inert** without
  refusing. The shipping guard then fails *before* it measures anything and
  reports that nothing was checked — a red test that proves nothing, which reads
  like a caught defect.

## What the enumeration is, and what it cannot see

Two independent passes, deliberately not sharing results:

- `enumeration/references.tsv` — 69 rows, swept by reference channel (import,
  string literal, config, autoconfiguration, frontend, generator, artifact,
  governance, package, prose), with `breaks` and `form` per row.
- `enumeration/measured-breakage.md` — derived the other way: the move was
  performed on a copy and each command run, so it finds breakages that have no
  textual reference to the path.

**They disagreed once, and the disagreement was substantive.** The sweep
reported the manifest as knowing the directory; the experiment found it does not
(0 occurrences), and that only 10 of the 28 files appear in any inventory. The
experiment is right, verified directly. The sweep also missed the
`'rows' => 10` counter entirely. Recorded because one pass filling both the
table and its own check agrees with itself.

**Not covered by either:** untracked and ignored trees; the contents of the
built `dist/report.min.js`; identifiers assembled from fragments; paths resolved
from CI environment or secrets; a CI run; `composer gate` and `gate:controls`;
the Docker image build. **And the whole measurement is for one destination** —
the packaging and ignore-rule findings depend on where the directory lands, so
stage 02 re-derives them at the chosen name rather than carrying these forward.

## Stages

| Stage                      | Subject                                                                 |
| -------------------------- | ----------------------------------------------------------------------- |
| [01](01-path-ownership.md) | Make both distances checkable; collapse the two JS copies into one      |
| [02](02-relocation.md)     | The move, its registration addresses, and the records that authorize it |

Stage 01 lands and is proved on its own; stage 02 does not begin until it has.
That order is the point: it is what turns stage 02's acceptance from "we edited
every literal we found" into "a check that was already refusing stayed silent".

## A record has to be retired, not contradicted

`docs/adr/0012` states at line 109 that the viewer stays at
`src/Reporting/Template`. ADR 0012's substantial/thin direction is already
superseded by ADR 0022, but this specific placement sentence is not, and it is
the only written authority on where the viewer lives. Stage 02 supersedes it
with a new ADR rather than editing it — editing an accepted ADR to agree with
today is how the repository loses its "why".
