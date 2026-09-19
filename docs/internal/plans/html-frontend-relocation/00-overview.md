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

## The decision: one owner for the path, then the move

Moving the directory changes both distances. Editing both literals moves the
hazard instead of removing it — it has already bitten twice in this tree, and
CLAUDE.md names stale `\dirname(__DIR__, N)` depth as a failure that resolves to
a directory above the repository and returns nothing rather than refusing.

So **stage 01 gives the path one owner per language, with a check that bites,
and lands before anything moves.** Then the move is judged by a guard that was
already green — rather than by a guard written in the same breath as the change
it judges.

**Destination: `html-report/` at the repository root**, the whole directory, one
place. The owner's decision is that the viewer leaves the PSR-4 root; keeping
the shipped assets behind in `src/Reporting/` would satisfy the letter and split
one subject across two roots. The name answers "what is this about?" with the
program, not with its technology — `frontend/` would name a role.

**Rejected: `tests/Reporting/HtmlTemplate/Tests/`.** This is not a hypothetical
— `targetPath()` in the test inventory already prescribes it for 10 of the 28
files, and `test-ownership.tsv:162-171` publishes that prescription in 10 rows.
It is wrong twice: it routes a shipped runtime asset's siblings under `tests/`,
and it says nothing about the other 18 files, so the subject would land in two
roots. The prescription is a leftover of the retired test-structure campaign —
its stated reason still cites `04-packages.md`, a file deleted in `4438c105`.
Stage 02 retires it explicitly; disagreeing with it silently would leave the
artifact publishing a destination nothing intends to use.

## What the move costs, measured rather than estimated

17 breakages, 13 of them reproduced by carrying the move out on a real copy of
the tree and running each command. `enumeration/measured-breakage.md` holds the
verbatim first line of every refusal. The shape that matters is the split:

|            | Count  | Why it matters                                              |
| ---------- | ------ | ----------------------------------------------------------- |
| loud       | 16     | a command refuses; these cannot be forgotten                |
| **silent** | **23** | the tree stays green and coverage leaves with the directory |

Three silent ones decide the packaging of this work:

- **`surfaces()['src']` in the rename enumeration.** Measured: 115 occurrences
  of `health.overall` in the `src` column, **93 of them from Template files**.
  Moving without declaring a new surface drops the column by four fifths, and
  CLAUDE.md names exactly this — "a later move reads as a drop in a column
  nobody re-derives".
- **`NON_MANIFEST_TEST_OWNERS['Reporting/HtmlTemplate']` carries `'rows' => 10`**,
  a hardcoded count over a generated artifact. A sweep for `assertCount(` does
  not find it, because it is not an assertion. It was found only by carrying the
  move out.
- **Two `.gitignore` negations and seven `export-ignore` lines become inert**
  without refusing. Measured at the root destination: `frontend/*.json` is then
  caught by a blanket `*.json` rule, and the shipping guard fails *before* it
  measures anything, so a reader sees a red test that checked nothing.

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
| [01](01-path-ownership.md) | One owner per language for the distance to the repository root          |
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
