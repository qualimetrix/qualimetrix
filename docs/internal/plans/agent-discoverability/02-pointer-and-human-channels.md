# Stage 02 — The canonical value, and every human-readable channel

Carries its own documentation, CHANGELOG entry and gate declaration, because a
stage that leaves those to a later stage is not green on its own.

## Contract

New: `src/Core/ProductIdentity.php`. Signatures and the reasoning behind
`pointerText()` returning plain text and `identity()` omitting `timestamp` are in
the overview; they are not restated here.

## Packages, in sequence

The manifest forces sequence. Measured: the checker refuses a declared consumer
that does not import (`unused contract consumer entry`, exit 1), so consumers
cannot be pre-declared in the first package. Every package below adds its own
imports, its own manifest consumer rows and regenerates the architecture
artifacts. They therefore run one after another, and the first package cannot defer its manifest rows.

### First package — the value together with its first consumer

A package that only creates the type is impossible: a `contract` declaration with
no consumers is refused by name (`contract declaration … must publish at least
one used consumer`), and a declared consumer that does not import is refused too.
So the value and its first consumer are one package.

- Files: `src/Core/ProductIdentity.php`, `src/Infrastructure/Console/Application.php`,
  `src/Infrastructure/Console/Refusal/RefusalPresenter.php`, the manifest entry
  with its consumer rows, the regenerated architecture artifacts, `qmx.yaml`, and
  the regenerated test inventories if this package adds a test.
- `Application::getHelp()` is overridden to return the stock long version plus the
  pointer. Symfony's `TextDescriptor` renders `getHelp()` as the header of bare
  `qmx`, `list` and `--help`; `--version` calls `getLongVersion()` directly and is
  untouched. This seam was measured in `vendor/symfony/console`.
- `RefusalPresenter`: the free-text stderr shape gains the pointer. The JSON
  envelope stays closed at two keys.
- **The `--quiet` rule does not apply to refusals.** `RefusalPresenter` writes at
  `VERBOSITY_QUIET` deliberately, because a refusal must reach a reader who asked
  for silence. The pointer on a refusal is therefore printed under `--quiet` as
  well, and the cross-cutting rule in the overview governs reports, not refusals.
  The first version of this plan stated a rule that contradicted this contract.

### Reporting's human formatters

- Files: `Summary/HintRenderer.php`, `TextFormatter.php`,
  `Health/HealthTextFormatter.php`, plus manifest rows.
- In `summary` the pointer is its own line below `Hints:`. `Hints:` lists actions;
  an address is not an action, and merging them buries both.
- **`text` has more than one branch, and the trailing line lives in only one.**
  The `Technical debt:` tail this plan cited as precedent is not printed on every
  path, so `text --detail` would carry no pointer, and `text-verbose` — which
  delegates with `--detail` forced on — would inherit the branch without it. The
  package places the pointer where every `text` path reaches it. The earlier
  claim that `text-verbose` merely "inherits whatever `text` prints" was true in
  form and wrong in effect.

### Baseline commands

- Files: the five `Baseline*Command.php`, plus manifest rows.
- Each gains the pointer in its tail and in its `Help:`.

### Remaining commands and the rules listing

- Files: the three `Hook*Command.php`, `CheckCommand`, `GraphExportCommand`,
  `DirectivesCommand`, `Debug/LayerAssignmentCommand`, `RulesCommand`, the rules
  listing presenter, plus manifest rows.
- Tails: hooks, `rules` inside its existing `Usage:` block, `directives` text
  branch only. `graph:export` gets `Help:` but no tail.
- **`debug:layer-assignment` needs a place that does not exist yet.** Its
  `Diagnostic hint:` block sits below two early returns, so it prints only for a
  class that is both matched and shadowed. But there is no single "end of output"
  either: the text renderer returns `void` with three exits, and the one common
  point in `execute()` is shared with the JSON branch, where a plain-text line
  would corrupt the document. The package therefore adds the pointer at the end of
  the **text** branch specifically, on each of its exits or at a single point it
  introduces for them — and the JSON branch gets the `meta` block in the next
  stage instead. Whichever shape the executor picks, the DoD is that every
  text-mode invocation prints it and no JSON-mode invocation does.

### The invariant's guard

- Files: one new test under `governance/ConsoleComposition/`.
- Asserts that every command carries the pointer in its `Help:`. The invariant is
  what makes this enforceable; an eight-of-thirteen exception list drifts the
  moment a fourteenth command lands. Without this package the invariant is a
  sentence in a plan, which is the gap a reviewer named.
- **The population is not 13.** Symfony contributes `help`, `list`, `completion`
  and `_complete` at runtime, so "every command" over the live application is 17.
  The guard asserts over the commands this repository registers and states, in
  one line, that the four framework commands are out of population — an
  unexplained exclusion is how an invariant quietly becomes a list.

### Last package — documentation, CHANGELOG and the gate declaration

- Files: the website pages this stage's output changes, `src/Core/README.md`,
  `src/Reporting/README.md`, `src/Infrastructure/README.md`, `CHANGELOG.md`,
  `finding-gate/declared-delta.tsv` and its derived diffs.
- **This package does not know in advance which surfaces moved, and does not
  guess.** It runs `composer gate --reference=<the commit this stage started
  from>`, reads the surfaces the gate itself reports, and declares those. The
  first version of this plan named three surfaces; a reviewer measured seven,
  including `show-suppressed`, the `rules` snapshot and 32 `baseline:explain`
  surfaces. A forecast here is worth nothing and costs a review round.
- The declaration is derived, never hand-written: `--derive-declared-delta`
  measures and writes both the index row and the diff. Only `reason` is supplied
  by hand, and a re-derivation resets it to `?`, which the loader refuses, so it
  is re-supplied after every re-derivation.

## A constraint on every package in this stage

`PlanningRecords/PlanningRecordIsolationTest` matches package-identifier shapes
(`P2`, `P1.1`, `X17`) anywhere in tracked sources and refuses them. So no package
identifier from this plan may appear in a code comment, a test name or a commit
body's source excerpt. Refer to the work, not to its label in this document.

## Edge cases

- **`--quiet` / `--silent`.** The pointer must not print.
- **`--no-ansi`.** No markup. `getHelp()`'s stock return carries `<info>`.
- **`-o FILE`.** `summary` and `text` written to a file go through the output
  path in `ResultPresenter`, not straight to stdout. The pointer must appear in
  the file too, and a package that only checks stdout has not checked this form.
- **`text` and its deprecated delegate.** `text-verbose` delegates to `text`, so
  it inherits the pointer whether or not anyone intends it. That is acceptable,
  but it is inheritance, not the "no change" the first version of this plan
  claimed.
- **Exit codes unchanged.**

## Test plan

- Presence for all 15 line channels, including through `-o FILE`.
- The `Help:` invariant over every registered command, with the framework four
  named as out of population.
- Absence under `--quiet` and `--silent`; no markup under `--no-ansi`.
- No literal `qualimetrix.dev` in `src/` outside `ProductIdentity` — with the one
  known exception, `SarifRuleCollector`, which stage 03 folds in. Until then the
  assertion names it explicitly rather than failing.
