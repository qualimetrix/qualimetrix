# How the stage-05 population was derived, and what the method cannot see

`defect-ledger.tsv` records 276 defects against 219 paths as they were at
`585b7c72`. Stages 01-04 moved much of that tree. This re-derives where each
path is now. **The ledger's paths are not the population; these tables are.**

The ledger has **two** path columns and both are derived:

| Table                                  | Column derived | Entries | Rows behind them |
| -------------------------------------- | -------------- | ------: | ---------------: |
| [`population.tsv`](population.tsv)     | `file`         | 219     | 276              |
| [`counterparts.tsv`](counterparts.tsv) | `counterpart`  | 60      | 104              |

Deriving only `file` was the first draft's defect. A `dupe` row is resolved by
editing *a pair*, so a row whose counterpart nobody owns is a row nobody can
close. **20 of the 60 counterparts are not in the ledger's `file` column at
all**, and 23 rows depend on them; `05-packages.md` names them.

## Two witnesses, deliberately independent

| Witness | How                                                                                                    | Answers                          |
| ------- | ------------------------------------------------------------------------------------------------------ | -------------------------------- |
| W1      | transitive closure over `git log --find-renames=40% --diff-filter=R` since `585b7c72`, **no pathspec** | where git says the file went     |
| W2      | unique basename match against `git ls-files`                                                           | where a file of that name is now |

They are compared rather than combined. Agreement is not proof; a disagreement
is always a fact worth a person's attention, and it is the only signal that
finds the failure below.

## Result

| Status               | `file` | rows | `counterpart` |
| -------------------- | -----: | ---: | ------------: |
| `at-path`            | 126    | 154  | 42            |
| `moved-agreed`       | 84     | 112  | 18            |
| `ambiguous-heirs`    | 5      | 6    | 0             |
| `moved-rename-only`  | 2      | 2    | 0             |
| `WITNESSES-DISAGREE` | 1      | 1    | 0             |
| `UNRESOLVED`         | 1      | 1    | 0             |

**91 of 219 ledger paths (42%) no longer exist**, carrying 120 of 276 rows. Nine
files need a person; the other 210, and all 60 counterparts, are mechanical.

## What the method cannot see

- **A split is invisible to W1.** When a stage divided a file, one heir inherits
  the rename edge and the other is recorded as a plain addition that reuses the
  name. W1 then names one heir with full confidence and never mentions the
  other. Measured: `ModularArchitectureGovernanceIntegrationTest` became
  `scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`
  by rename chain, while `governance/ModularOwnership/` holds a second file under
  the original name with no edge pointing at it. **This is why W2 exists: the
  disagreement is the detector.** A single-witness derivation would have been
  clean, confident and wrong.
- **A pathspec silently truncates W1.** Built with `-- tests/ governance/` the
  same closure resolved 79 of 91 rather than 90: files that left `tests/` for
  `scripts/*/tests/` lost their edge. The map is built with no pathspec.
- **Neither witness reads content**, so neither can tell that a row's defect was
  already fixed in passing by stages 01-04. Every row is re-confirmed against the
  current body before it is worked.
- **Row-level drift is not modelled.** The `line` column is from `585b7c72` and
  is stale wherever a body moved. Rows are located by class and method name —
  which makes the method name part of a row's identity, and nothing here
  derives method names.
- **Neither witness sees a reference by name.** Sweeping every hand-written
  tracked file outside `docs/` for the FQCN of a population heir finds **33
  heirs named from somewhere else**, including product code:
  `src/Analysis/Finding/Contract/Finding.php` names a governance test class.
  Worse for the packaging, `governance/TestSuiteHygiene/ResidualLimitationsCoverageTest.php`
  pins 13 **class-plus-method pairs** and reaches them through reflection, so a
  method rename this stage performs breaks a file in another package. **This
  channel is a stage-level sweep, not a per-package one** — for most of the 33
  the referring file is owned by a different package, and a per-package sweep
  cannot see it by construction.

## The 40% threshold, and the reason it is not lowered

The first draft justified keeping 40% by saying a lower threshold would buy one
row "at the cost of false pairs elsewhere". **That was itself an unmeasured claim
about a set, in the document written to prevent them.** Measured:

```
threshold 50%: 239 pairs
threshold 40%: 240 pairs
threshold 30%: 241 pairs
threshold 20%: 241 pairs
```

Lowering 40% → 30% adds **exactly one** pair — the one under discussion,
`DocumentationConsistencyTest → governance/RuleDeclaration/DocumentationRuleSurfaceTest.php`
— and nothing else; 20% adds nothing at all. There is no measured cost on this
range. The real reason to leave it unresolved is narrower: at 39% similarity the
instrument cannot distinguish that edge from a coincidence, and one edge is
cheap for a person to settle. 40% itself is not a principled figure either — 50%
loses one true pair.

## Commands, so the tables can be re-derived rather than trusted

The pinned-path figure quoted by the stage counts `tests/` literals in
`scripts/generate-modular-architecture-test-inventory.php`, **excluding the bare
`'tests/'` root literal**, which is a prefix and not a path: 246 with it, 245
without. State which one you mean; the stage means 245.
