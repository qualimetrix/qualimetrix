# Review disposition — `rules` listing round

Companion to [01-one-declaration-two-readers.md](01-one-declaration-two-readers.md) and
[02-landing.md](02-landing.md).
It lives beside the plan rather than inside it so the plan stays readable at the
length this project holds a plan to; it is part of the plan for every purpose,
including `scripts/check-review-disposition.sh`, which is run against this file:

```bash
scripts/check-review-disposition.sh \
  docs/internal/plans/rules-listing/03-review-disposition.md review-rules-listing/findings
```

Three rounds, 32 findings, none refuted. `scripts/check-review-disposition.sh`
cross-checks this section against the reviewers' files; the ids are spelled in
full because an abbreviated list defeats that check.

**Revision 1, reviewed by `claude` (13) and `codex` (9).** All closed by the
rewrite to revision 2; grouped by the mechanism each belongs to rather than
one line each, because six of them are one defect seen from six sides.

- *Measurement was not reproducible* — `codex-03`, `claude-02`, `claude-10`.
  All four instruments now run from a clean checkout and are committed; the
  authored alias spelling is a column, so "34 of 80" is checkable from the
  table instead of from prose.
- *The gate package rested on the wrong number and the wrong semantics* —
  `claude-01`, `claude-03`, `codex-05`, `codex-06`. Measured with the gate's own
  `ExactDiff` (320, not 134); exit 5 is a failure; the `reason` column is typed;
  the re-reference fallback is gone.
- *Packages were not self-sufficient in generated artifacts* — `codex-04`,
  `claude-13`. Regeneration is a standing rule over every package, and the
  manifest entry moved to the package that introduces the import edge.
- *The contract was named after its readers' output shapes* — `codex-01`,
  `codex-02`, `claude-05`, `claude-06`. Subject renamed to addressing, split
  into three types, framework keys composed rather than absorbed, `locate()`
  takes the raw target.
- *Consumer enumeration missed the tests* — `codex-07`, `claude-04`. The
  invariant tests are named and must stay green unedited.
- *Oracles and DoD contradicted each other* — `claude-07`, `claude-11`. The
  independent witness is the runtime sweep in P1's DoD; P2's DoD no longer uses
  the committed table as an oracle.
- *Shared write sites and stale documentation* — `codex-08`, `claude-09`,
  `codex-09`, `claude-12`. Order with `shorthand-scope` fixed, Finding README in
  P1, `RuleMetadata`'s docblock in P4.
- *`claude-08`* — the landing unit is now named, with the red interim states in
  a table.

**Revision 2, reviewed by `claude` (10).** The round-1 repair for `claude-01`
introduced two defects of its own, which is why there was a second round.

- `claude-r2-01`, `claude-r2-02` — two commits in one pull request could not
  work: `main` carries only squashed single-parent commits, and
  `declared-delta.tsv` refuses a second row for one surface. The landing shape
  is now decided by measurement and, when a split is needed, it is two pull
  requests rather than two commits.
- `claude-r2-03` — the emptying obligation is handed to the following round by
  name, above.
- `claude-r2-04` — "one fact in one place" is now earned: P1 migrates
  `RuleOptionsFactory`'s copy as well, and the different subject that stays is
  named with its reason.
- `claude-r2-05` — `locate()` returning null for an answered-by-the-class key
  is documented as deliberate rather than given a third state no reader wants.
- `claude-r2-06` — the enumeration says what it actually is: a grep over `src/`
  and `tests/`, with its blind spot named.
- `claude-r2-07` — every operation in the contract now has a named reader in
  the consumers table.
- `claude-r2-08` — a slot always prints its line; only depth 1 may be silent.
- `claude-r2-09` — this section replaces the path into a `.gitignore`d
  directory.
- `claude-r2-10` — raising the constant now carries `composer gate:controls`
  with it, and revision 3's round sharpened what that run is worth
  (`claude-r3-03` below).

**Revision 3, reviewed by `claude` (6).** The revision-2 repair again introduced
defects of its own — the third round in a row where the landing section, not the
design, was the thing that moved.

- `claude-r3-01` — `measure-declared-delta.php` would have measured a doubled
  listing once P2 landed, reporting a smaller delta than the change has. It is
  now declared pre-implementation guidance, it refuses to run against a listing
  that already prints options, and the refusal is tested. After P2 the gate's
  own derive is the measurement.
- `claude-r3-02` — `composer gate:controls` is required by every outcome that
  declares a row for `tree|rules`, not only by the one that touches the constant.
- `claude-r3-03` — re-running the controls is not itself the check when the
  constant is raised: the `delta-too-large` control overshoots the limit by a
  fixed margin (256 against today's 200), so a limit raised past that leaves it
  passing without biting. That outcome now includes recalibrating the control.
- `claude-r3-04` — the two-landing outcome now maps onto packages: each landing
  is a whole unit with its own row, its own controls run and its own
  documentation, and P4's DoD is claimed per landing.
- `claude-r3-05` — the branch this plan skipped is named and rejected with its
  reason: a third slice would cut one coherent change by rule family to fit a
  limit.
- `claude-r3-06` — the instruction to empty the predecessor's row before
  deriving was inert, because the derive rewrites the file. What is real — a
  round that does not derive inherits a stale row — is stated instead.
