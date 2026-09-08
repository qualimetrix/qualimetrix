# 03 — The seven alias removals (package П3.2)

Split out of `03-refusal-at-every-depth.md` because that file passed 400 lines;
the two are one stage and land in one branch, П3.2 after П3.1.

## What it removes

Pairs #2, #3, #7, #8, #12, #13 of `02-declarations-per-capability.md` — the
`warningThreshold`/`errorThreshold` entry condition of the three complexity
wrappers — and #22, `projectNamespaces` on `coupling.distance`. Seven undeclared
legacy aliases of keys that are declared and do the same thing; CLAUDE.md's
*Backward Compatibility Policy* prefers a removed option to an alias.

Four files, the `П3.2` row group of `measurement/packages.tsv`:
`src/Analysis/Evidence/Complexity/ComplexityOptions.php`,
`CognitiveComplexityOptions.php`, `NpathComplexityOptions.php` and
`src/Analysis/Evidence/Coupling/DistanceOptions.php`. The set is disjoint from
П3.1's, machine-checked.

## Why it lands after П3.1 and not beside it

The four files are four of the twenty that carry
`implements ShorthandOptionKeysInterface` / `AdditionalOptionKeysInterface`, so
"parallel with disjoint file sets" was not true of them. **П3.2 owns those four
files' interface cleanup as well as the alias removal**, which makes the sets
disjoint; sequencing makes the intermediate state defensible.

Order matters in one direction only. With П3.1 first, the seven aliases are
already refused at the intermediate commit — stage 02's declarations omit them —
so a user who lands there gets exit 3 and a sentence. The reverse order would
leave a window in which the alias silently does nothing and only a warning says
so, and `-q` silences a warning (enumeration row 51). That window is the very
defect class this plan exists to close, so the plan does not create one.

## The removal is two edits per complexity wrapper

Narrowing the branch's entry condition at `ComplexityOptions.php:43` to
`threshold` alone leaves the parser call one line below it intact:

    ThresholdParser::parse(..., legacyKeys: [
        'warning' => ['warningThreshold'], 'error' => ['errorThreshold'],
    ])

The AST reader of `scripts/enumerate-rule-option-keys.php` collects candidate
keys out of `legacyKeys`, so both spellings would still be reported as read
after the condition changed, and stage 04's guard would redden on keys nothing
can reach. **`legacyKeys:` goes with the condition.**

`DistanceOptions.php:55-58` is the simpler shape: two of the four `??` arms
(`project_namespaces`, `projectNamespaces`) go, the two `include_namespaces`
arms stay.

## What it does not change

- Enumeration row 66 — a top-level `threshold` disabling the `class` level —
  stays as it is, named out of scope in the overview. Removing the two legacy
  names narrows the branch's entry, not its body.
- The mode-conflict assertion of `ThresholdParser` ("threshold mixed with
  warning/error") keeps a live witness: it is unreachable at the wrapper's top
  level once #4/#5/#9/#10/#14/#15 are refused, and remains reachable and tested
  inside each slot and at the top level of `CboOptions`/`InstabilityOptions`,
  where #17/#18/#20/#21 keep those keys. The wrapper-level conflict case moves
  to the `CboOptions` top level rather than being deleted.
- Documentation. Every user-facing page is П4.3, which owns `website/**` whole;
  no website file is in this package.

## Definition of Done

- The four files carry neither the two retired interfaces nor the seven
  aliases, and `grep -rn 'warningThreshold\|errorThreshold\|projectNamespaces'
  src/Analysis/Evidence/Complexity src/Analysis/Evidence/Coupling` returns
  nothing in these four files.
- Re-running `scripts/enumerate-rule-option-keys.php` reports the seven keys in
  neither `read_unguarded` nor `read_branch_guarded` for these four classes.
- Not offered for validation alone: П3.1 and П3.2 are one landing unit and the
  aggregate runs over the union.

## Test plan (no tests written here)

- One case per removed alias, seven in total: written at the position it used to
  work at, it now exits 3 with the generic refusal, and the key it aliased
  (`threshold` on the three wrappers, `include_namespaces` on
  `coupling.distance`) still produces the effect the alias used to.
- One case proving the removal did not narrow the surviving key: a bare
  `threshold: N` on each of the three complexity wrappers still opens the flat
  branch and still yields the findings it did before.
- `composer gate -- --reference=<the commit П3.1 ended on>`: the seven aliases
  are not written by any corpus case (measured in the overview's reddening
  check), so GREEN with empty maps is the expected result and a red one means a
  corpus case was reached that the checkers did not see.
