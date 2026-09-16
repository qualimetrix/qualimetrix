# Stage 02 — what a move breaks silently

Every package brief carries this file. Each item is a way the move leaves a
green run that measures less than it did before — the stage's own failure mode,
reproduced by the stage.

## 1. The project root is computed from the file's own depth

42 of the 59 moving files reach the repository root through
`\dirname(__DIR__, N)`:

| N           | 3   | 4   | 5   | 6   |
| ----------- | --- | --- | --- | --- |
| occurrences | 9   | 26  | 12  | 5   |

A file at `tests/Analysis/Finding/Integration/X.php` needs 4; the same file at
`governance/Channel/X.php` needs **2**. Groups are flat, so after the move every
one of them is 2 — no exceptions to reason about.

**Why a stale N is not caught by the run.** `\dirname('/r/governance/Channel', 4)`
does not fail; it returns a directory that exists, somewhere above the
repository. `file_get_contents` on a path under it then fails loudly and the
control reddens — fine. But **29 of the 42 walk a directory** with `Finder`,
`RecursiveDirectoryIterator` or `glob()`, and a walk of a directory holding no
PHP returns `[]`. A control whose claim is "every X has property Y" is
**vacuously true** over an empty population: green, fast, and measuring nothing.

Every package's DoD therefore includes both:

```
grep -rn 'dirname(__DIR__, *[^2)]' governance/     # must print nothing
```

and a per-file check that the population the control walks is non-empty. Several
controls already assert this themselves (`RatchetKeyGrammarTest` carries
`assertNotSame([], $keys, '… for this to prove anything')`); where a moved
control does not, the package states so rather than adding one — a new
assertion is a new guard, and this stage adds none.

The 29 that combine both are listed in the package that moves them; the count is
re-derived per package rather than copied, because a `mixed` split can leave the
walk on either side.

## 2. A control's own scan scope does not follow it

`ScratchPathsCarryRealEntropyTest::ROOTS` was extended to `governance` in stage
01. It is not the only list of roots.

`DocumentationConsistencyTest::itKeepsExecutableSourcesIndependentFromPlanningRecords`
scans `$roots = ['bin', 'scripts', 'src', 'tests']`. `governance/` is absent —
**already, on `main`, before this stage moves anything**. Stage 01 created the
root and this control kept the narrower scope; AGENTS.md's registration table
names this class of address ("every other control that carries its own root
list") and marks it as failing silently. It did.

After 40 controls move, that method stops seeing them entirely. The package that
touches this file fixes the list in the same commit.

## 3. Path literals are pinned in two generated places

Beyond the registration addresses AGENTS.md lists, the channels fixture
directory is pinned at:

- `scripts/generate-modular-architecture-test-inventory.php:168-169`, checked by
  `assertPathLiteralsResolve()` — **fails loudly**, so this one announces itself;
- `docs/internal/generated/modular-architecture/test-fixture-directories.tsv:12`
  — regenerated, so it moves if and only if the generator's literal moved.

A finding, not a task: the same generator carries a branch at `:764` naming
`tests/Fixtures/Channels/` — without `Analysis/Finding/` — and no such directory
exists. It is the residue of an earlier rename and matches nothing today.

## 4. `SILENTLY_EXCLUDED` is keyed by FQCN

`TestFilesAreExecutedTest::SILENTLY_EXCLUDED` names two cases by fully-qualified
name. Both carry `#[Group('live-freshness')]` and both are inside the moving
set, so a plain relocation changes their namespace from `Qualimetrix\Tests\` to
`Qualimetrix\Governance\` and reddens **two** guards at once:
`itCarriesNoStaleSilentExclusionDeclaration` on the old name and
`itNamesEveryCaseTheRunnerExcludesFromCheck` on the new one. The constant is
edited in the same commit as the move.

Checked and found false: the earlier notes claim a deletion must also be
accompanied by an edit to
`ModularArchitectureGovernanceIntegrationTest::itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck`.
That method asserts only the shape of `composer.json`'s script graph and names
neither candidate; `:33-68` contains no reference to either.

## 5. Registering the group, not the root

Stage 01 registered the root at every address. A **group** needs exactly two
edits, and they are symmetric — one without the other reddens by name:

- `phpunit.xml.dist` — a `<directory>governance/{Group}</directory>` inside the
  `Governance` suite;
- `scripts/generate-modular-architecture-test-inventory.php` —
  `['prefix' => 'governance/{Group}/', 'suite' => 'Governance']` in
  `testSuitePrefixTable()`.

`classifyOwner()`, `dispositionFor()` and `targetPath()` already branch on the
`governance/` prefix as a whole and need no edit. Declaring the root as one
`<directory>` instead of per group is deliberately not done: it hides layout
defects.

## 6. What must not move

`finding-gate/enumeration-renames.tsv` must not change: `surfaces()` in
`scripts/generate-rename-enumeration.php:57` deliberately holds `tests` and
`governance` as one surface, so relocation across them is invisible to it. A
diff there is a signal that something other than a relocation happened — never a
reason to regenerate.
