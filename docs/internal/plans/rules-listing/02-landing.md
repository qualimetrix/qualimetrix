# Landing — packages, the gate, and the order next to `shorthand-scope`

The design this lands is in
[01-one-declaration-two-readers.md](01-one-declaration-two-readers.md); the
review it has been through is disposed of in
[03-review-disposition.md](03-review-disposition.md).

## Packages

**Every package that adds, renames or moves a production class, a test, or an
ADR also runs `composer architecture:generate` and commits the regenerated
`docs/internal/generated/modular-architecture/*`** — `architecture:check`
compares those byte for byte, so a package that adds a file and claims a green
check without regenerating fails its own DoD. A package that adds a cross-owner
import edge also carries the manifest entry for it; the checker rejects an
unlisted exact import even when the coarse qmx graph would permit it.

**P0 and P1 each leave the tree fully green. P2, P3 and P4 do not, and are one
landing unit rather than three packages that may be merged apart.** Naming what
is red in between, because a package whose own DoD is green while the branch is
worse than before and after is a failure this project has hit repeatedly:

| After | Red until | Why it cannot be compensated inside the package                                                    |
| ----- | --------- | -------------------------------------------------------------------------------------------------- |
| P2    | P3        | the `rules` snapshot moved and no delta is declared yet; the gate is red by construction           |
| P2    | P4        | `website/docs/usage/cli-options.md` pastes the old example output, so the published docs are wrong |
| P3    | P4        | the delta is declared, the website still lies                                                      |

Per-commit DoD is therefore `composer check:code`. The **unit's** DoD — the one
that may be cited as evidence — is `composer check` plus a green gate, and it is
claimed for a landing, never for P2 or P3 alone. If P3's measurement forces two
landings, each landing is a whole unit in this sense: it carries its own
documentation and its own green gate, and `main` is never left half-landed.

**P0 — the measurement.**
`docs/internal/plans/rules-listing/` in full, this document included. It is the
baseline later packages diff against, and a baseline produced after the change
it judges is not one.
DoD: all four instruments run from a clean checkout; `check-agreement.php` exits 0.

**P1 — the two values and the refusal reader.**
`src/Analysis/Finding/Contract/Rule/{RuleOptionSurface,RuleOptionAddress,FrameworkOptionKeys}.php` (new),
`src/Analysis/Finding/RuleConfiguration/RuleOptionKeyRecognition.php`,
`src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php`,
`src/Analysis/Finding/Exclusion/ConfiguredSuppression.php`,
`src/Analysis/Finding/README.md` (it currently says the framework keys are
listed beside the walk, which this package makes false),
`tests/Analysis/Finding/.../RuleOptionSurfaceTest.php` (new),
`docs/internal/modular-architecture-manifest.json`,
`docs/internal/generated/modular-architecture/*`.
Depends on P0. DoD: `sweep-refusal.sh` re-run produces a file byte-identical to
the tracked `measurement/runtime-refusal.tsv`; `check-agreement.php` exits 0;
`composer architecture:check` green; every invariant test named above green and
unedited.
Nothing is left uncompensated: no published surface moves, and the listing is
still short until P2.

**P2 — the listing.**
`src/Infrastructure/Console/Command/RulesCommand.php`,
`tests/Infrastructure/Unit/RulesCommandTest.php`,
`tests/Infrastructure/Integration/RulesCommandWiringTest.php`,
the new agreement test, the generated artifacts for the added test file, and
`docs/internal/modular-architecture-manifest.json` — this is the package that
introduces the Infrastructure-to-`Contract\Rule` import edge, so the entry
belongs here and not with P1, which only creates the type.
Depends on P1. DoD: the agreement test (test plan 1) passes against the live
container — the committed `declared-options.tsv` is the denominator a reviewer
reads, never the test's oracle; `composer check:code` green.

**P3 — the gate, and the shape of the landing.**
`finding-gate/declared-delta.tsv`, `finding-gate/declared-delta/*.diff`.
Depends on P2. The `bin/qmx rules` snapshot is one of the gate's compared
surfaces and this change moves it deliberately.

**The landing shape is decided by a measurement, and after P2 that measurement
is the gate's own.** `measure-declared-delta.php` is **pre-implementation
guidance only**: it builds the listing this plan specifies on top of the
listing the product prints today. Once the command prints its options, that
"before" is no longer before — the script would insert the option lines a
second time and report a delta smaller than the real one. It refuses to run in
that state rather than answering wrongly, and the refusal is tested. After P2
exists, the question is put to `--derive-declared-delta`, which either writes
the row or reports `delta-too-large`.

Prediction today, for planning only: **320 for the whole change; 72 + 182 split
spelling-first, 182 + 68 split options-first**, against
`DeclaredDelta::MAX_CHANGED_LINES` = 200. `changedLineCount()` sums both sides
of every hunk and `ANCHOR = 4` keeps short runs of identical lines from
splitting one, so the plain count of added and removed lines — 134 — is not
this number and must never be quoted as it.

**Whatever the outcome, declaring a row for `tree|rules` means running
`composer gate:controls`.** Not only when the constant is touched: the controls
are calibrated against the tracked declaration, and a green controls run is
what shows the gate still bites with this round's row in place.

The derive's verdict names the outcome:

- **It writes — one pull request.** One declared row, derived against the commit
  the branch starts from.
- **`delta-too-large`, and a half fits — two pull requests**, each squash-merged
  onto `main` as its own step. Not two commits in one PR: `main` carries only
  squashed, single-parent commits, so intermediate commits do not survive the
  merge and the tree would be judged base-to-tip. `declared-delta.tsv` also holds
  **one row per surface** and refuses a second, so two rows cannot coexist in one
  branch regardless.
  Spelling-first is the better order: the interim state on `main` then leaves
  only the defect that already exists, whereas options-first prints
  `max-warning` and `max_warning` for one key in one block.
  **Each landing is a whole unit, not half of P2.** It carries its own slice of
  the command change, its own row, its own `gate:controls` run, and its own
  documentation: the first landing updates the pasted `--group=complexity`
  example in `website/docs/usage/cli-options.md` and `.ru.md` to what *it*
  prints, and the second updates it again. P4's DoD is claimed per landing, not
  once for both.
- **`delta-too-large` and no half fits — the constant is the question**, and it
  is the owner's. Three landings are not the answer before it: a third slice
  would have to cut the option lines by rule family, which is slicing one
  coherent change to fit a limit rather than landing two coherent ones.
  Raising `DeclaredDelta::MAX_CHANGED_LINES` weakens a guard standing over every
  future surface, and re-running `composer gate:controls` is **not** by itself
  the check: the `delta-too-large` control is calibrated to overshoot the limit
  by a fixed margin — its docblock records 256 against today's 200 — so a limit
  raised past that magnitude leaves the control passing without biting. That
  outcome therefore includes recalibrating the control, and the recalibrated rig
  is what judges the new constant; the old rig says nothing about it.

Exit 4 from `--derive-declared-delta` means it wrote, which is expected. **Exit 5
means the measurement failed and nothing was written; it fails the package.** The
diff files are derived, but the `reason` column is **not** — a derivation writes
`?` there and loading refuses `?`, so each reason is written by hand afterwards.

DoD: a plain `composer gate -- --reference=<that landing's own predecessor>` is
GREEN for each landing, and `composer gate:controls` is green with the row in
place. `PARTIAL` means the run was narrowed; it is not evidence and does not
close this package.

**P4 — what the product says about itself.**
`CHANGELOG.md` (`Changed`), `src/Infrastructure/README.md`,
`src/Analysis/Finding/Contract/RuleMetadata.php` (its docblock calls `$aliases`
a "canonical option name"; that is false for 34 of 80 and stays false after
this change unless the targets themselves are normalised at registration —
either normalise, or say "as authored"), `website/docs/usage/cli-options.md`
and `.ru.md` — both paste the `--group=complexity` example output, which this
change moves — and an ADR recording that one declaration now feeds both
readers.
Depends on P2. DoD: `composer docs:check` green; EN and RU in one commit;
generated artifacts regenerated for the new ADR.

**P5 — full validation and review.**
`composer check` once, then review, then once more after confirmed fixes.

## Landing next to `shorthand-scope`

The two rounds do not touch one product file in common, and neither names the
other's types. They do share three write sites, so an order is fixed rather
than discovered: **this round lands first**, and `shorthand-scope` rebases onto
it. The shared sites are `CHANGELOG.md`, the ADR catalogue, and
`finding-gate/declared-delta.tsv`.

The last one carries an obligation that has to be handed over by name, or the
next round reads it as its own breakage: **this round's `declared-delta` row
goes stale the moment this round is merged**, because the next step's reference
already contains the change and both sides then agree.

Nothing manual is needed by a round that derives: `--derive-declared-delta`
rewrites the whole file and this round's row simply does not come back. The
handover matters for the round that does **not** derive — it inherits a row
nothing fired, `delta-stale`, and will spend its first gate run hunting a
regression that does not exist. Such a round empties the file itself.

Independently of this round: `shorthand-scope/02-cure.md` P5 reserves
`docs/adr/0059-*.md`, a number already taken by the accepted "Declared-Layer
Policy and Architecture Governance". That plan needs a free number whatever
happens here.

