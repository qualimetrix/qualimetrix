# How `population.tsv` was derived, and what the method cannot see

`defect-ledger.tsv` records 276 defects against 219 paths as they were at
`585b7c72`. Stages 01-04 moved much of that tree. This re-derives where each
ledger path is now. **The ledger's paths are not the population; this table is.**

## Two witnesses, deliberately independent

| Witness | How                                                                                                | Answers                          |
| ------- | -------------------------------------------------------------------------------------------------- | -------------------------------- |
| W1      | transitive closure over `git log --find-renames=40% --diff-filter=R` since `585b7c72`, no pathspec | where git says the file went     |
| W2      | unique basename match against `git ls-files`                                                       | where a file of that name is now |

They are compared rather than combined. Agreement is not proof, but a
disagreement is always a fact worth a person's attention, and it is the only
signal that finds the failure below.

## Result

| Status               | Files | Rows | Meaning                                             |
| -------------------- | ----: | ---: | --------------------------------------------------- |
| `at-path`            | 126   | 154  | still where the ledger says                         |
| `moved-agreed`       | 84    | 112  | both witnesses name the same heir                   |
| `ambiguous-heirs`    | 5     | 6    | basename now matches several tracked files          |
| `moved-rename-only`  | 2     | 2    | only W1 answered                                    |
| `WITNESSES-DISAGREE` | 1     | 1    | W1 and W2 name different files, both of which exist |
| `UNRESOLVED`         | 1     | 1    | neither witness answered                            |

**91 of 219 ledger paths (42%) no longer exist**, carrying 120 of 276 rows.
Nine files — the last four rows of the table — need a person; the rest are
mechanical.

## What the method cannot see

- **A split is invisible to W1.** When a stage divided a file, one heir inherits
  the rename edge and the other is recorded as a plain addition that reuses the
  name. W1 then names one heir with full confidence and never mentions the
  other. Measured: `ModularArchitectureGovernanceIntegrationTest` became
  `scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`
  by rename chain, while `governance/ModularOwnership/` holds a second file
  under the original name with no edge pointing at it. **This is why W2 exists:
  the disagreement is the detector.** A single-witness derivation would have
  been clean, confident and wrong.
- **The rename threshold is a parameter, not a constant.** At 40% one file has
  no edge; at 30% it resolves to `governance/RuleDeclaration/DocumentationRuleSurfaceTest.php`.
  Lowering the threshold globally buys that one row at the cost of false pairs
  elsewhere, so it is left unresolved here and adjudicated by hand instead.
- **A pathspec silently truncates W1.** Built with `-- tests/ governance/` the
  same closure resolved 79 of 91 rather than 90: files that left `tests/` for
  `scripts/*/tests/` lost their edge. The map must be built with no pathspec.
- **Neither witness reads content**, so neither can tell that a row's defect was
  already fixed in passing by stages 01-04. Every row needs re-confirmation
  against the current body before it is worked; the ledger states a defect as of
  `585b7c72`, not as of today.
- **Row-level drift is not modelled.** The `line` column is from `585b7c72` and
  is stale wherever the body moved. Rows are located by class and method name,
  never by line.

## Channels a reference to these files can travel, and coverage

Direct path in the ledger (covered), git rename history (covered), basename in
the tree (covered). **Not covered and left to the stage:** references to these
test classes by FQCN in prose and docblocks, `--filter` literals in scripts and
CI, and the hardcoded path lists inside
`scripts/generate-modular-architecture-test-inventory.php`. Those are sweeps the
stage performs per package, not properties of this table.
