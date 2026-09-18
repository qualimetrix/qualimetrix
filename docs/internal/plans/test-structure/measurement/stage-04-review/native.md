# Stage 04 — plan review (native)

Material: `04-subject-layout.md`, `04-packages.md`, `06-governance-subject-groups.md`,
`00-overview.md` and `measurement/stage-04/**`, read at `main` @ `e15c7f42` with the
three modified and three untracked plan files in the working tree. Read-only: nothing
in the tree was edited; the one experiment that needed a modified config ran in a
`mktemp -d` scratch project.

Every finding carries a command that reproduces it. The shared derivation the
findings rest on is the script in **Appendix A** — an independent implementation of
the placement rule, written from the rule statement in `04-subject-layout.md:8-12`
without reading the generator.

---

## F1 — CRITICAL — the self-check that adopted the rule never exercised the rule's third assertion

**Anchor.** `04-subject-layout.md:34-38`, `00-overview.md:19-22`, `04-subject-layout.md:111-133`.

**Status: fact, measured.**

The plan states (`04-subject-layout.md:34-36`):

> **The rule was self-checked before being adopted**, against the test directories
> that were already correct: it reproduces 504 of the 529 non-legacy test files
> exactly. The 25 it does not reproduce all have the same shape …

`529` and `25` are both reproducible, and they are *not* independent:

```
git ls-files tests | grep 'Test\.php$' | grep -vc '^tests/\(Unit\|Integration\|Functional\)/'   # 529
python3 /tmp/native-rule-check.py                                                                # see Appendix A
```

Appendix A classifies the same 529 files against the rule's own three assertions
(`04-subject-layout.md:113-126`) and returns:

| outcome                                                           | count   |
| ----------------------------------------------------------------- | ------: |
| conforms on all three assertions                                  | **263** |
| owner segment is not one of the 37 (`owner-not-manifest`)         | **25**  |
| path remainder ≠ `CoversClass` namespace minus owner              | **121** |
| no `#[CoversClass]` at all                                        | **78**  |
| path owner ≠ `CoversClass` owner                                  | **18**  |
| remainder undecidable — several `CoversClass`, several namespaces | **15**  |
| several `CoversClass`, several owners                             | **9**   |

`529 − 25 = 504`. The published figure is exactly the count of files that satisfy
assertions **1 (owner) and 2 (level)**. Assertion 3 — the one the plan itself
argues is load-bearing ("Without this, a new file under
`tests/Reporting/Unit/Formatter/Whatever/` passes silently", `04-subject-layout.md:122-126`)
— was never in the self-check. "Reproduces … exactly" is therefore false for the
rule as stated; it is true only for a two-assertion weakening of it.

Two classes of counterexample, both unarguable and neither of the "same shape" as
the 25:

**(a) 121 files sit flat under `{owner}/{level}/` while their SUT is in a
sub-namespace of that owner.** Verbatim:

```
$ grep -n 'namespace\|^use Qualimetrix\|CoversClass(' \
    tests/Analysis/Evidence/CodeSmell/Unit/DebugCodeSmellsTest.php
5:namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;
13:use Qualimetrix\Analysis\Evidence\CodeSmell\Debug\DebugCodeSmells;
15:#[CoversClass(DebugCodeSmells::class)]
```

The rule prescribes `tests/Analysis/Evidence/CodeSmell/Unit/Debug/DebugCodeSmellsTest.php`.
Same for the whole of `ComputedMetrics/Health/Unit` (`HealthScoreTest` covers
`…Health\Contract\Score\HealthScore`, sits at remainder `''`), `Analysis/Configuration/Unit`,
`Analysis/Evidence/Measurement/Unit`, `Analysis/Evidence/Security/Unit`.
**34 of the 55 rows of `governance/TestSuiteHygiene/namespace-path-allow-list.php`
name files Appendix A also flags** — 22 remainder-mismatch, 6 remainder-ambiguous,
6 no-`CoversClass` — and those rows carry exactly the remainder the path lacks:

```
'tests/Analysis/Policy/Architecture/Unit/LayerViolationRuleTest.php'
    => 'Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Rules'
# covers …Architecture\LayerViolation\LayerViolationRule; path remainder is ''
```

The tree already records this debt, under a different control and with the opposite
cure written next to it ("The way out is to empty the list, one renamed namespace at
a time" — i.e. rename the namespace flat, which is the reverse of moving the file
down). The stage-04 measurement sees neither the debt nor the collision of cures.

**(b) 18 files whose path owner is a real manifest owner but is not the owner of
what they cover.** Nine of them are one family:

```
$ grep -h '^use Qualimetrix\\Infrastructure\\Console\\Command\\' \
    tests/Analysis/Policy/Baseline/Functional/*.php | sort -u
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRenameChannelsCommand;
…
```

Nine of the twelve files in that directory carry a `#[CoversClass]` whose manifest
owner is `Infrastructure.Console` while their path says `Analysis.Policy.Baseline`
(`BaselineCleanupCommandTest`, `BaselineCommandFailureReportingTest`,
`BaselineCommandOptionSurfaceTest`, `BaselineExplainCommandTest`,
`BaselineGenerateCommandTest`, `BaselineMeasuredSetSeamTest`,
`BaselineRenameChannelsCommandTest`, `BaselineRunBeforeLoadTest`,
`BaselineUpdateCommandTest`; Appendix A's `owner-mismatch` bucket).

This is the same shape as `tests/Functional/Console/LayerAssignmentCommandTest.php`,
which the map *does* move to `tests/Infrastructure/Console/Functional/Command/Debug/`.
The two got opposite treatment because one sat in a legacy bucket and the other did
not — i.e. by bucket membership, not by the rule. The plan's own adapter-exclusion
principle (`AGENTS.md`, "Adapters … live in `src/Infrastructure/`") says these nine
belong with `Infrastructure.Console`.

**Consequence, and it is not cosmetic.** `04-packages.md:197-206` requires P5 to be
*both* green over the whole tree *and* observed to refuse a file "whose remainder
contradicts its `#[CoversClass]`". Post-move (Appendix A, second pass over all 616
test classes) the tree contains 121 remainder mismatches, 16 undecidable remainders,
18 owner disagreements and 12 multi-owner files. **Those two DoD clauses cannot both
hold.** Whoever implements P5 will resolve the contradiction silently — by weakening
assertion 3 until the tree is green — and the stage will land a control that agrees
with the tree it was written against, which `04-subject-layout.md:131-133` names as
the failure mode to avoid.

**Fix direction.** The plan must choose, in the plan, not at the keyboard:
- *Weaken assertion 3 deliberately* to "the remainder is a prefix of some covered
  class's remainder". Measured (Appendix A, loose pass): 511 conform, 21 fail, 84
  have no `CoversClass`. This still refuses the invented `Formatter/Whatever`
  remainder the plan cites as motivation, and it makes flat placement legal by
  decision rather than by accident. Say so and record what it stops catching.
- *Or widen the scope*: roughly 140 further files become in-scope rows, and
  `relocation-map.csv`, `prediction.md` and the package partition all have to be
  re-derived. That is a different stage, not an edit.

Note separately that the residue rationale at `04-subject-layout.md:71-78` ("Leaving
them … would also make the invariant unstatable") does not survive either branch: the
invariant is already unstatable over the tree for reasons that have nothing to do
with the 26 residue rows.

---

## F2 — HIGH — the no-`CoversClass` exception ceiling is wrong by a factor of ten

**Anchor.** `04-subject-layout.md:127-133`, `04-packages.md:189-191`, `04-packages.md:205`.

**Status: fact, measured.**

> They are an **explicit exception list with a ceiling**, currently 8 …
> (`04-subject-layout.md:127-128`)

`8` is the count of map rows that had no coverage claim (`witness = none+decision`,
11 rows of which 8 carry no `CoversClass`). The control at `04-packages.md:189-191`
is not scoped to the map — it runs per file over the tree. Over the tree:

```
python3 /tmp/native-rule-check.py          # no-covers, non-legacy *Test.php: 78
                                           # no-covers, post-move, all *Test.php: 84
grep -L 'Covers' $(…the 78…) | wc -l       # 75 mention no Covers* attribute at all
                                           # the other 3 carry #[CoversNothing]
```

So the exception list is **84 rows, not 8**. With a ceiling of 8 the control is red
on day one; with the derived ceiling of 84 the fourth planted breakage
(`04-packages.md:205`, "a file with no `#[CoversClass]` added beyond the ceiling")
still demonstrates refusal, so nothing is lost by measuring it. What is lost by not
measuring it is that the number in the plan is a guess presented as a measurement,
and it is the kind of number an implementer will "fix" by raising it without
recording a decision — which `04-subject-layout.md:129-130` explicitly wants to
prevent.

**Fix direction.** Derive the ceiling before writing it down, the way
`namespace-path-allow-list.php` derives its 55; state which of the 84 are
`#[CoversNothing]` (a deliberate claim) versus simply undeclared (debt), because the
two deserve different lifetimes.

---

## F3 — HIGH — P0's DoD ("artifacts byte-identical") contradicts what P0 changes

**Anchor.** `04-packages.md:58-60`.

**Status: fact, measured.**

> `composer architecture:check` green and the regenerated artifacts byte-identical
> to the committed ones — P0 moves no file, so the inventory must not change.

P0 gives `targetPath()` the remainder segment it has never had
(`04-packages.md:43-47`). `target_path` is a **column of the committed artifact**:

```
$ php scripts/generate-modular-architecture-test-inventory.php \
    --classification-probe=tests/Analysis/Run/Unit/Pipeline/DependencyGraphAnalyzerTest.php
Analysis/Run	P3	Unit	tests/Analysis/Run/Unit/DependencyGraphAnalyzerTest.php

$ grep -n 'DependencyGraphAnalyzerTest' docs/internal/generated/modular-architecture/test-ownership.tsv
708:tests/Analysis/Run/Unit/Pipeline/DependencyGraphAnalyzerTest.php	…	tests/Analysis/Run/Unit/DependencyGraphAnalyzerTest.php	P3	"Move atomically with the named owner and closure package."
```

The committed `test-ownership.tsv` carries 264 rows whose `target_path` differs from
`current_path`; **171 of them are non-legacy `tests/` rows, and at least 97 differ
only by the remainder the current template drops**. The moment `targetPath()` stops
dropping it, those rows become identity and their `disposition` flips
`Move …` → `Retain …`. `dispositionFor()` losing its
`^tests/Infrastructure/(Unit|Integration)/` anchor (`04-packages.md:49-51`) moves
another block the other way. The artifact **must** change; "must not change" is the
opposite of the truth.

This matters because of what an implementer does with a DoD that cannot pass. Either
they keep the flattening template to preserve byte-identity — reintroducing exactly
the defect `generator-probe.md` was written to prove — or they regenerate, commit,
and the check degenerates into "the artifact equals its own regeneration", which is
the tautology `generator-probe.md:25-30` names as the reason the probe was needed at
all.

**Fix direction.** Replace "byte-identical" with an **enumerated expected diff**: N
rows flip Move→Retain for the remainder, the 114 map rows gain the map's target, the
residue rows flip Retain→Move. Deriving that list is P0's real deliverable, and a
diff that does not match it is the refusal.

---

## F4 — HIGH — P2 and P3 each commit a tree that a fresh clone cannot run

**Anchor.** `04-packages.md:113-135` (P2, P3 DoD), `04-packages.md:160-172` (P4),
`04-subject-layout.md:157` ("each of P0–P4 owns its own rows, sequentially").

**Status: fact, measured — including the PHPUnit behaviour.**

All seven `<directory>` entries that empty out are retired in **P4**
(`04-packages.md:160-166`). But they do not empty in P4 — they empty in P2 and P3:

```
after P2: tests/Functional
          tests/Reporting/FindingProjection/Unit
          tests/Reporting/Formatter/Sarif/Integration
          tests/Reporting/Formatter/Suppressed/Unit
after P3: tests/Unit
          tests/Integration
          tests/Analysis/Finding/RuleConfiguration/Unit
```

(reproduced by replaying the map package-by-package against `git ls-files tests` and
the `<directory>` list of `phpunit.xml.dist`; script shape identical to Appendix A's
directory pass.)

`04-packages.md:12-14` requires every package to commit before the next starts. After
P2's commit and after P3's commit, `phpunit.xml.dist` names directories that git does
not track, because git tracks no empty directory. On a fresh clone — which is what CI
is — PHPUnit then runs nothing. Measured on a scratch project (`mktemp -d`, this
repo's `vendor/`):

```
Test directory ".../tests/Missing" not found
EXIT=2
```

Neither P2's DoD nor P3's DoD can see it: locally the emptied directories still exist
on disk, so the six suite counts are correct and `composer architecture:check` is
green. The plan even states the hazard — `04-packages.md:152-156`, "git tracks no
empty directory, so on a fresh clone a surviving entry is a path that is not there,
and PHPUnit exits 2 having run nothing" — but attaches it only to P4 forgetting an
entry, not to P2 and P3 creating the condition on purpose and holding it across two
commits.

This is the answer to "what stays uncompensated between packages": between P2 and P4,
`main` is worse than both before and after the stage, and the badness is invisible to
every oracle the plan declares.

**Fix direction.** Make each move package retire the `<directory>` entries *it*
empties, together with their `testSuitePrefixTable()` rows; P4's list of seven then
becomes zero and P4 is left with the bucket directories themselves. If the ordering is
kept as written, P2's and P3's DoD must add a fresh-clone check — clone the commit
into a scratch directory and run one suite there — but retiring per package is
cheaper and removes the window entirely.

---

## F5 — HIGH — P2 and P3 never run the tests they move

**Anchor.** `04-packages.md:99-103` (P1), `04-packages.md:119-122` (P2),
`04-packages.md:133-134` (P3).

**Status: fact, by reading the three DoDs against each other.**

P1's DoD ends "`composer architecture:check` green. `composer check:code` green."
P2's DoD is "Every one of the six suite counts identical to P1's row … `composer
architecture:check` green." P3's is the same. Neither runs PHPUnit's assertions —
only `--list-tests`.

P2 is the **largest package by test cases**: 688 cases across 51 files (measured per
file with `vendor/bin/phpunit --no-coverage --list-tests <file>`), against P1's 455.
A P2 file that moves with a stale namespace, a broken relative fixture path, or a
`use` that no longer resolves is counted by `--list-tests` and never executed as a
failure. `composer architecture:check` does not run tests either. The first thing
that would notice is P4's `composer check`, two commits later, with 62 moved files
in between to bisect.

The plan is aware of the class of defect — `04-packages.md:242-245`, "a file with a
stale namespace runs and misleads rather than failing" — and assigns the catch to
P4's tree-versus-map diff, which cannot perform it (see F11).

**Fix direction.** `composer check:code` in P2's and P3's DoD, symmetrically with P1.
It is the same cost the plan already accepted once.

---

## F6 — MEDIUM — the stage DoD forbids exactly what P6 does

**Anchor.** `04-subject-layout.md:168-170` versus `04-packages.md:212-224`.

**Status: fact.**

Stage DoD: "Every row of `relocation-map.csv` was executed; **no file moved that the
map does not name.** Asserted by diffing the tree against the map's `target` column."

P6 moves `tests/Analysis/Finding/Support/FindingFactory.php` to
`tests/Analysis/Policy/Baseline/Support/` and renames two
`UnmatchedExcludeIntegrationTest.php`. None of the three is in the map:

```
$ grep -c FindingFactory docs/internal/plans/test-structure/measurement/stage-04/relocation-map.csv
0
$ git ls-files | grep UnmatchedExclude | grep tests
tests/Analysis/Policy/Architecture/Integration/UnmatchedExcludeIntegrationTest.php
tests/Analysis/Run/Integration/ExcludeBinding/UnmatchedExcludeIntegrationTest.php
```

Evaluated at the end of the stage — which is where a stage DoD is evaluated — the
assertion is false. It is true only at P4. A DoD that is known-false at the moment it
is checked gets read charitably instead of run, and then it is no longer an oracle.

**Fix direction.** Scope the clause to P4 (it is already stated there,
`04-packages.md:176-178`) and give P6 its own bidirectional statement naming its three
files, or add the three to the map with a `group` of `p6`.

---

## F7 — MEDIUM — P4's grep DoD cannot return what it says it will return

**Anchor.** `04-packages.md:174-179`.

**Status: fact, measured.**

> `git grep` for `tests/Unit`, `tests/Integration`, `tests/Functional` and for the
> namespaces `Qualimetrix\Tests\{Unit,Integration,Functional}` returns only
> historical measurement documents.

Three live populations defeat it, none of them touched by any package:

```
$ git grep -l -E "^namespace Qualimetrix\\\\Tests\\\\(Unit|Integration|Functional)" -- tests \
    | grep -v '^tests/\(Unit\|Integration\|Functional\)/' | wc -l
30                      # e.g. tests/Core/Path/Unit/*, tests/Core/Symbol/Unit/*,
                        # tests/Infrastructure/Profiler/Unit/*, 0 of them in the map

$ php -r '$a=require "governance/TestSuiteHygiene/namespace-path-allow-list.php"; …'
55 rows, 34 of which name a legacy namespace for a file stage 04 does not move

$ grep -n 'tests/Unit' tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php
19: * … `tests/Unit/Infrastructure/Console/ApplicationTest.php`
```

Emptying the 30 means renaming 30 namespaces and re-deriving the allow list — real
work that no package owns and that `addresses.md` puts in the "does not apply" table
(`addresses.md:46`, "none of its 55 rows names a moving file"; true, and irrelevant to this DoD clause).
The predictable outcome is that the grep is run, produces 60-odd hits, and is waived
by hand — after which it has refused nothing.

**Fix direction.** Either scope the grep to the three *paths* only (which does hold —
`git grep -E "tests/(Unit|Integration|Functional)\b" -- . ':!tests' ':!docs'` is empty
today), or state the namespace clause as "no file **under a bucket path**", and record
the 30 + allow-list rows as a named leftover with an owner.

---

## F8 — MEDIUM — the merged address list dropped an address witness A had found

**Anchor.** `measurement/stage-04/addresses.md:24-38` (the Addresses table) and
`:40-49` ("Checked and found not to apply"), versus
`addresses-witness-a.md:90`.

**Status: fact.**

Witness A enumerated, with file and line:

> **Stale `{@see \Qualimetrix\Tests\...}` docblock prose**, 10 instances across 8 files
> (`governance/RuleOptionKeys/CliAliasKeyWalkAgreementTest.php:23`, … ) … not counted
> in the totals below.

The merged `addresses.md` contains the string `@see` nowhere — not in the Addresses
table, not in "Checked and found not to apply", not in "What the union of both
witnesses still cannot see". It is the one address class that a witness found and the
merge lost, in a document whose stated method is "the union is the list"
(`addresses.md:21`). I re-measured it independently before reading witness A, by
resolving every moving class's FQCN and grepping non-moving files:

```
governance/Channel/ChannelDeclarationFixtureDriftTest.php:40
governance/Channel/ChannelPresentationCoverageTest.php:79
governance/Channel/SarifRuleDescriptorCoverageTest.php:36
governance/ConfigurationVocabulary/YamlKeyReachabilityTest.php:428
governance/RuleOptionKeys/CliAliasKeyWalkAgreementTest.php:23
tests/Analysis/Run/Unit/Configuration/ProjectScopeCoverageTest.php:22
tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php:19,156
tests/Infrastructure/Console/Functional/Command/CheckCommandConfigurationErrorGateTest.php:22
tests/Infrastructure/Console/Functional/Command/CheckCommandInputValidationTest.php:347
tests/Infrastructure/Console/Functional/Command/CheckCommandProjectScopedGateTest.php:23
```

These are silent by construction — nothing resolves a `{@see}` — which is precisely
why a drop is cheap now and expensive later; `src/` carries 6 more of the same
convention, so the practice is established and will keep producing them. Witness A's
disposition ("documentation debt, not a functional address") may well be the right
call; a dropped row is not the same as a decided row.

**Fix direction.** Put the row back in `addresses.md` with witness A's disposition
written out, and give it to a package (P4 is already editing prose in that area) or
name it as a knowingly-accepted leftover.

---

## F9 — MEDIUM — one witness's "verified with" line is false, and the merge declares all such lines re-measured

**Anchor.** `addresses-witness-b.md:31`; `addresses.md:12-20`.

**Status: fact, measured.**

Witness B dismissed the `ApplicationTest` docblock with:

> Update the comment; that referenced test file no longer exists at that path today
> either (pre-existing drift, not caused by this move) — confirmed:
> `test -f tests/Unit/Infrastructure/Console/ApplicationTest.php` fails already on
> current tree

It does not fail:

```
$ ls -la tests/Unit/Infrastructure/Console/ApplicationTest.php
-rw-r--r--@ 4 … 14495 … tests/Unit/Infrastructure/Console/ApplicationTest.php
$ grep -n 'ApplicationTest' …/relocation-map.csv
52:tests/Unit/Infrastructure/Console/ApplicationTest.php,tests/Infrastructure/Console/Unit/ApplicationTest.php,…
```

The file exists and is row 52 of the map. The reference is not pre-existing drift; it
is drift this stage creates. `addresses.md:12-14` claims "every such row was then
re-measured here by the command in its own row, and all of them held" — this one did
not hold, and was not re-measured, which puts a small hole in the merge's own
guarantee rather than only in witness B.

**Fix direction.** Re-run the two-witness disagreement rows whose disposition is
"does not apply" — that is the half of the table the merge had no incentive to check,
because a dropped row costs nothing until it does.

---

## F10 — MEDIUM — the invariant has a fourth assertion it does not state, and P5 plants no breakage for it

**Anchor.** `04-subject-layout.md:111-126`, `04-packages.md:197-206`.

**Status: fact.**

Assertion 1 is "the path segments before the level segment name one of the 37
manifest owners". It does **not** say the owner must be the owner of what the file
covers. Assertion 3 is stated as a subtraction ("the namespace of the file's
`#[CoversClass]` minus the owner prefix") and is silent about the case where the
covered class is not under that owner's namespace at all, so the subtraction is
undefined. 18 files are in exactly that state today (F1(b)), 9 of them the
`tests/Analysis/Policy/Baseline/Functional/` family covering `Infrastructure.Console`.

P5's five planted breakages do not cover the shape either. Breakage 3, "a file at a
real owner and level whose remainder contradicts its `#[CoversClass]`", plants a wrong
*sub-directory under the right owner*; it cannot demonstrate refusal of a file at the
wrong owner with an empty remainder, which is the shape that actually exists in the
tree and the shape a future adapter test will take.

**Fix direction.** State the owner-agreement assertion explicitly, decide whether the
that nine-file Baseline family is a violation or a recorded exception, and add a sixth
planted breakage: real owner, real level, empty remainder, `CoversClass` owned by a
different owner.

---

## F11 — LOW — the plan assigns the stale-namespace catch to a check that compares paths

**Anchor.** `04-packages.md:242-245`.

**Status: fact.**

> Whether a moved file still runs is not assumed from the count … P4's bidirectional
> tree-versus-map diff is what catches that.

P4's diff compares the tree against the map's `target` **column**
(`04-subject-layout.md:168-170`, `04-packages.md:176-178`) — paths against paths. A
file at the right path with an unrewritten `namespace` produces an identical diff.
The check that actually catches it is
`governance/TestSuiteHygiene/TestNamespacesFollowTheirPathTest.php`, whose allow list
is derived and ceilinged so a fresh violation cannot be absorbed. Harmless in effect —
the catch exists — but the plan's stated reason for believing itself safe is the wrong
one, and a later edit made on that belief (e.g. dropping the Governance suite from a
package's DoD) would remove the real catcher while the plan still says P4 covers it.

**Fix direction.** Name `TestNamespacesFollowTheirPathTest` as the catcher and require
the Governance suite to run in every move package (which F5 also asks for).

---

## F12 — LOW — P5's control has no stated scope, and the tree has files that satisfy none of the three assertions by design

**Anchor.** `04-packages.md:189-191`; `04-subject-layout.md:156-160` (support/fixtures out of scope).

**Status: fact.**

Assertion 2 requires exactly one level segment. `tests/TestSupport/**`,
`tests/Fixtures/**` and every `Support/`/`Fixtures/` directory have none, and after the
stage two more appear above the level segment (`tests/Reporting/Support/`,
`tests/Analysis/Finding/Support/`). `04-subject-layout.md:8-12` scopes the invariant
to "every PHPUnit test class", but `04-packages.md:189-191` describes the control as
running "per file" with the only stated escape hatch being the missing-`CoversClass`
list — which a support class would also land in, and then fail assertion 2 anyway.

Checked and clean: no `*Test.php` lives under `tests/Fixtures/` or
`tests/TestSupport/`, and `find src -type d \( -name Unit -o -name Integration -o
-name Functional \)` is empty, so no legitimate remainder can ever collide with a
level segment.

**Fix direction.** State the control's population in the plan — "files PHPUnit
discovers as test classes" — rather than leaving it to the implementer to infer from
the exception list.

---

## F13 — LOW — `Governance 748` is stale in two DoDs the moment P5 lands

**Anchor.** `04-subject-layout.md:171-176`, `06-governance-subject-groups.md:70-72`.

**Status: fact.**

The stage DoD fixes the six numbers "checked after every package, not only at the
end", Governance among them at 748. P5 registers a **new governance group with a new
control** (`04-packages.md:184-188`), so Governance is not 748 after P5 — it is 748
plus however many cases the control has. Stage 06 then inherits the same stale number:
"Governance case count unchanged at 748 under the runner's own exclusions"
(`06-governance-subject-groups.md:70`), and 06 depends on 04
(`00-overview.md`, stages table).

Baseline verified exact today:

```
$ for s in Unit Integration Functional Infrastructure Tooling Governance; do
    vendor/bin/phpunit --testsuite=$s --no-coverage --exclude-group=benchmark \
      --exclude-group=live-freshness --list-tests | grep -c '^ - '; done
6987 419 203 660 179 748      # bare --list-tests: Governance 750, the rest identical
```

**Fix direction.** Say that the six-number oracle applies to P1–P4 and that P5 moves
Governance by a stated amount; re-anchor stage 06 to "Governance unchanged from the
post-P5 number", not to a literal.

---

## F14 — LOW — `relocation-map.csv` is CRLF

**Anchor.** `measurement/stage-04/relocation-map.csv`.

**Status: fact.**

```
$ grep -c $'\r' docs/internal/plans/test-structure/measurement/stage-04/relocation-map.csv
115          # header + all 114 rows
```

Python's `csv` module strips it; `awk -F,`, `IFS=, read` and `str_getcsv` do not, and
the carriage return lands in the last column (`note`) — or in `target` for the rows
where `note` is empty. The plan's own reproduction block
(`addresses.md:51-61`) uses `csv.DictReader` and is safe; a package script that reaches
for `cut` is not.

**Fix direction.** Normalise to LF, since three packages are going to read this file
by script.

---

## Decisions of the stage — assessed

Asked for explicitly by the brief; none of these is a finding.

- **Not merging the two `HookStatusCommandTest`** (`04-subject-layout.md:95-103`) —
  **sound**. After the moves they are `Infrastructure/Console/{Unit,Functional}/Command/`,
  separated by level, distinct namespaces, no collision. Verified: the map produces no
  duplicate target and no target collides with an existing file.
- **Residue in scope** (`04-subject-layout.md:71-78`) — **sound as an outcome**,
  unsound as argued. The 26 rows genuinely would create a second home for a subject
  (`tests/Reporting/Unit/Formatter/Sarif/` beside a surviving
  `tests/Reporting/Formatter/Sarif/Integration/`). The supporting claim that leaving
  them "would make the invariant unstatable" is wrong for the reason in F1: the
  invariant is unstatable over the tree with or without them.
- **Splitting `SolePrimitiveOwnership` into stage 06** (`04-subject-layout.md:198-208`,
  `06-*.md`) — **sound**. The three cohesion evidences are applied properly, the
  cleared groups (`RepositoryEntrypoints`, `FindingVocabulary`) are recorded with
  reasons, and the stated dependency (04 rewrites `testSuitePrefixTable()`, which every
  new group needs a row in) is real. Only F13's 748 needs re-anchoring.
- **The 11 hand-decided rows** — **sound**, and I checked the machine half
  independently: for all 103 rows marked `witness = CoversClass`, my own
  `CoversClass`→manifest resolution returns exactly the `owner` column, 0 disagreements.
  The 11 notes read as subject judgements, not as tie-breaks by convenience; the two
  I would have argued (`FileProcessingResultWireFormatTest` → Parallel rather than Run,
  `MaxExpandedLayersFromYamlTest` → Architecture rather than Configuration) are argued
  in the note and I agree with both.

---

## coverage

**Read in full:** `04-subject-layout.md`, `04-packages.md`,
`06-governance-subject-groups.md`, `00-overview.md` (through the stages table),
`measurement/stage-04/{relocation-map.csv, prediction.md, addresses.md,
addresses-witness-a.md, addresses-witness-b.md, generator-probe.md}`,
`phpunit.xml.dist`, `docs/internal/modular-architecture-manifest.json`,
`governance/TestSuiteHygiene/namespace-path-allow-list.php`,
`docs/internal/generated/modular-architecture/test-ownership.tsv`, `AGENTS.md`.

**Re-measured independently and found correct — no finding:**

- Baseline per suite: 6987 / 419 / 203 / 660 / 179 / 748 = 9196, and the bare-vs-oracle
  gap is exactly the 2 Governance `live-freshness` cases. `prediction.md` is right,
  including the correction of the earlier "two skipped cases" story.
- The per-package prediction. I counted cases per moving file with
  `vendor/bin/phpunit --no-coverage --list-tests <file>` and replayed the partition:
  P1 = 6705/383/152/1029/179/748 exactly, P2 and P3 suite-neutral exactly. P1 carries
  282+36+51 = 369 cases into Infrastructure; the claim that P1 is the only place a
  suite delta can appear holds.
- The 7 emptying `<directory>` entries and the 3 new level directories — both sets
  reproduced exactly, including the three witness A alone had found.
- The map's `owner` column against an independent `CoversClass`→manifest resolution:
  103/103 agree.
- `addresses.md`'s `__DIR__` claim: 4 moving files use it, all four keep their depth;
  `dirname(__DIR__, 3|4)` and `__DIR__ . '/../../../../Fixtures/...'` all still resolve
  to `tests/`. 33 rows change depth, none of them among the 4.
- Allow-list ∩ moving files = ∅, as `addresses.md` claims.
- No duplicate target, no target colliding with a non-moving file, no post-move
  duplicate path.
- The legacy buckets hold 88 files, all `.php`, 87 tests + 1 support class — no data
  fixture is stranded there, and the map names all 88.
- `git grep -E "tests/(Unit|Integration|Functional)\b" -- . ':!tests' ':!docs'` and the
  namespace equivalent are both empty, so `composer.json`, `phpstan.neon`,
  `.php-cs-fixer.dist.php`, `.githooks/`, `.gitattributes`, `.dockerignore` and
  `.github/` carry no bucket literal. `production-to-test-imports.tsv` is empty.
- PHPUnit 12.5.25 exits 2 and runs nothing on a declared-but-absent `<directory>`
  (measured in a scratch project, not in this tree).

**Not checked — say so rather than imply coverage:**

- The `48` versus `136` `<directory>` arithmetic behind the level-under-owner decision
  (`04-subject-layout.md:46-52`). I did not re-derive either number; the decision is
  also defensible on ADR 0022 grounds alone.
- "103 of the 320 path literals in `classifyOwner()` are dead"
  (`04-subject-layout.md:82-86`). Not verified.
- The current values of `assertCount(28, …)` and `assertCount(1, …)` in
  `ModularArchitectureGovernanceIntegrationTest`, and what they should become after P4.
  I confirmed the address exists and that the test carries `#[Group('live-freshness')]`;
  I did not compute the new numbers.
- The other 108 rows of `relocation-map.csv` against `--classification-probe`.
  `generator-probe.md` already states this limit; I did not close it.
- `createIsolatedProject()`'s copy list, beyond accepting `addresses.md`'s reasoning
  that it copies whole roots.
- Whether the G2 orphan check reaches the three new level directories.
- Interaction with stage 05: `00-overview.md` says 39 ledger files are also in the
  relocation map; I did not check whether any of the 114 targets contradicts a ledger
  disposition.
- Level correctness of any target — out of scope by the plan's own statement, and I
  did not sample it.
- I did not run `composer check`, `composer architecture:check` or the full suite.

**Assumptions.** (1) `*Test.php` ≈ "PHPUnit test class" — the tree gave no
counterexample, but a `TestCase` subclass named otherwise would be outside my
population. (2) For assertion 3 I read "the namespace of the file's `#[CoversClass]`"
as singular and required equality; files with several `CoversClass` in different
namespaces are counted separately (15 pre-move, 16 post-move) rather than folded into
either verdict. (3) `Core.Neutral` → `tests/Core` per `04-subject-layout.md:115-118`.

---

## Appendix A — the reproduction script

Written from the rule statement, not from the generator. Two passes: strict
(assertions 1-3 as written) and loose (remainder as prefix). Save as
`/tmp/native-rule-check.py` and run from the repository root.

```python
import json, os, re, subprocess, collections

M = json.load(open('docs/internal/modular-architecture-manifest.json'))
OWNERS, DECL = M['owners'], M['declarations']
FQ2O = {k: v['owner'] for k, v in DECL.items()}
LEVELS = {'Unit', 'Integration', 'Functional'}
opath = lambda o: 'Core' if o == 'Core.Neutral' else o.replace('.', '/')
ons   = lambda o: 'Qualimetrix\\' + ('Core' if o == 'Core.Neutral' else o.replace('.', '\\'))
OPATHS = {opath(o): o for o in OWNERS}
legacy = lambda f: f.startswith(('tests/Unit/', 'tests/Integration/', 'tests/Functional/'))

def parse(f):
    src = open(f, encoding='utf-8', errors='replace').read()
    ns = re.search(r'^namespace\s+([^;]+);', src, re.M)
    ns = ns.group(1).strip() if ns else None
    uses = {}
    for m in re.finditer(r'^use\s+([A-Za-z0-9_\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;', src, re.M):
        uses[m.group(2) or m.group(1).split('\\')[-1]] = m.group(1)
    covers = []
    for m in re.finditer(r'#\[\s*CoversClass\s*\(\s*([^\)]*?)\s*\)\s*\]', src):
        a = m.group(1).strip()
        if not a.endswith('::class'):
            covers.append('STRING:' + a); continue
        n = a[:-7]
        if n.startswith('\\'): covers.append(n[1:]); continue
        head = n.split('\\')[0]
        covers.append(uses[head] + n[len(head):] if head in uses else (ns + '\\' + n if ns else n))
    return ns, sorted(set(covers))

def remainders(o, covers):
    p, out = ons(o), set()
    for c in covers:
        cns = c.rsplit('\\', 1)[0]
        out.add('' if cns == p else
                (cns[len(p) + 1:].replace('\\', '/') if cns.startswith(p + '\\') else 'OUT:' + cns))
    return out

def classify(path, covers, owners_of_covers, strict=True):
    segs = path.split('/')[1:-1]
    lv = [i for i, s in enumerate(segs) if s in LEVELS]
    if len(lv) != 1: return 'level-not-exactly-one'
    i = lv[0]; opart = '/'.join(segs[:i]); rem = '/'.join(segs[i + 1:])
    if opart not in OPATHS: return 'owner-not-manifest'
    o = OPATHS[opart]
    if not covers: return 'no-covers'
    if not strict:
        for r in remainders(o, covers):
            if not r.startswith('OUT:') and (rem == '' or r == rem or r.startswith(rem + '/')):
                return 'prefix-conforms'
        return 'prefix-fails'
    if len(owners_of_covers) != 1: return 'covers-multi-owner'
    if owners_of_covers[0] != o:  return 'owner-mismatch'
    rs = remainders(o, covers)
    if len(rs) > 1: return 'remainder-ambiguous'
    return 'conforms' if rs.pop() == rem else 'remainder-mismatch'

files = [f for f in subprocess.check_output(['git', 'ls-files', 'tests']).decode().split()
         if f.endswith('.php')]
info = {}
for f in files:
    ns, covers = parse(f)
    oo = sorted({FQ2O[c] for c in covers if c in FQ2O})
    info[f] = (ns, covers, oo)

pre = collections.Counter(classify(f, *info[f][1:]) for f in files
                          if f.endswith('Test.php') and not legacy(f))
print('529 non-legacy, strict:', pre, sum(pre.values()))

import csv
MAP = {r['current']: r['target'] for r in
       csv.DictReader(open('docs/internal/plans/test-structure/measurement/'
                           'stage-04/relocation-map.csv'))}
post = collections.Counter(classify(MAP.get(f, f), *info[f][1:]) for f in files
                           if MAP.get(f, f).endswith('Test.php'))
print('616 post-move, strict:', post, sum(post.values()))
loose = collections.Counter(classify(MAP.get(f, f), *info[f][1:], strict=False) for f in files
                            if MAP.get(f, f).endswith('Test.php'))
print('616 post-move, loose :', loose, sum(loose.values()))
```

Output on `main` @ `e15c7f42`:

```
529 non-legacy, strict: {'conforms': 263, 'remainder-mismatch': 121, 'no-covers': 78,
                         'owner-not-manifest': 25, 'owner-mismatch': 18,
                         'remainder-ambiguous': 15, 'covers-multi-owner': 9}  529
616 post-move,  strict: {'conforms': 365, 'remainder-mismatch': 121, 'no-covers': 84,
                         'owner-mismatch': 18, 'remainder-ambiguous': 16,
                         'covers-multi-owner': 12}                            616
616 post-move,  loose : {'prefix-conforms': 511, 'no-covers': 84,
                         'prefix-fails': 21}                                  616
```
