# 0067. Every Test-Shaped Directory Is One the Inventory Scans

**Date:** 2026-09-20
**Status:** Accepted
**Supersedes:** part of
[ADR 0064](0064-the-html-viewer-lives-outside-the-psr-4-root.md) — the
test-shaped half of the gap accepted in its "One direction of that check is
missing" subsection, and the revisit condition attached to it. The
non-test-shaped half of that gap is **not** superseded; see below.
**Related:** [0016 — Subject Cohesion](0016-subject-cohesion.md)

## Context

A test root has to be registered in several places, and
`TOOLING_TEST_ROOT_OWNERS` in
`scripts/generate-modular-architecture-test-inventory.php` is the one that
decides whether the root is *scanned at all*. Until now the check over that map
answered two questions asymmetrically:

- "a registered key no longer exists on disk" — answered for every key, of every
  shape, by a direct `is_dir()`/`is_file()` pass.
- "a root exists on disk but nobody registered it" — answered **only** for keys
  shaped like `scripts/<tool>/tests/` and `tools/<tool>/tests/`, because the
  listing it compares against is produced by a `glob()` of exactly those two
  parents.

`governance/` had been outside that glob since the day it was registered;
`html-report/` joined it with ADR 0064. The consequence is not a wrong answer
but an unasked question: a root-level project landing without a registration is
absent from the inventory **entirely** — not published under a wrong owner,
where a stray row would eventually catch a reader's eye, but nowhere, with
`composer architecture:check` green and nothing left behind to notice later.

ADR 0064 accepted this and set a revisit condition: a **third** subject outside
the glob's shape. That condition has not been met. The test-shaped part of the
direction is closed here anyway, because the condition was chosen against a cost
this decision does not pay — see below.

## Decision

**Every test-shaped directory the tree carries is one this generator actually
scans, and `assertEveryTestDirectoryIsClaimed()` refuses when one is not.**

### The population, and why this one

ADR 0064 stated the blocking problem exactly: closing this direction "needs a
*closed population* of root-level tooling artifacts — some rule that decides
which of the repository's top-level directories ought to be in this map", and
warned that "deriving such a population wrongly would be worse than not deriving
it: it would refuse for directories that were never meant to be registered, and
the pressure would be to widen it until it refused for nothing."

That warning is correct and is the reason the population here is **not** the one
it anticipated. "Root-level tooling artifact" is not decidable from the tree:
`website/`, `docs/`, `benchmarks/`, `bin/` and `input-doors/` are all top-level
directories, most are not tooling test roots, and any rule separating them is a
judgement about intent that a check cannot make. A population of top-level
directories is all false positives, and the widening pressure ADR 0064 predicts
follows immediately.

The population is instead: **every directory git carries whose own basename
spells "tests"** — `tests`, `test`, `Tests`, `__tests__` or `spec` — outside
`vendor/` and `node_modules/`, taking the shallowest such match per path. This
is decidable, needs no judgement about intent, and it is *narrow on purpose*: it
does not claim to enumerate root-level subjects, only directories whose name
already says what they hold. Measured against this tree it names sixteen
directories, fifteen of which are registered roots and one of which is a
fixture.

The spellings beyond `tests` name zero directories today. They are in the source
anyway, because it is the source that may be generous: a spelling missing from
it is a root the sweep cannot see, while a spelling too many costs at worst one
more literal the day some directory innocently uses it. A vitest or jest project
— the shape the gap was accepted for — writes `__tests__` as readily as `tests`.

### "Claimed" means scanned, and nothing weaker

The claim a directory must hold is that it falls inside the pathspec this
generator's own row pass is handed — read from the expression the scan uses, not
restated beside it.

**An earlier draft of this decision accepted a second door and it was wrong.**
That draft also let a `<directory>` under a `<testsuite>` in `phpunit.xml.dist`
claim a directory, reasoning that the root `tests/` tree is registered as its
several dozen leaf directories rather than as itself. Two reviewers found the
same defect independently: that door grants a claim which does not entail what
the claim is *for*. A new PHP test root declared in `phpunit.xml.dist` and
classified by `testSuitePrefixTable()` satisfies it, runs under PHPUnit, and is
still absent from `test-ownership.tsv`, `test-phpunit-discovery.txt` and
`test-phpunit-suites.txt` — because neither of those registrations touches the
scan scope. The harm this check exists to refuse would have stayed reachable
through a directory the check called claimed.

Measured before removing it: of the sixteen candidates, `tests/` was the only
one the suite door claimed that **the map** did not — `tests/` is not a
`TOOLING_TEST_ROOT_OWNERS` key, which is the whole reason the second door was
written. The scan scope is a wider oracle than the map and covers `tests/` by
its own literal, so moving the claim from the map to the scan scope loses no
candidate and the weaker door is gone.

This is the general shape of the mistake worth recording: a registration that
*correlates* with the property being checked is not the property. Every door has
to be the thing itself.

### The rule is stated as itself, not witnessed

The judged set is the source **minus** `NON_ROOT_TEST_DIRECTORIES`, and the
subtracted set is literals in the same file.

This is the form, learned expensively in this repository, that a control of this
kind has to take. A witness — "some root is registered", "the roots we
remembered are still there" — moves the blind spot rather than removing it,
because what it never enumerates it can never miss; that is precisely the defect
being repaired, restated one level up. Subtraction by literal has the opposite
property: the excuse list is as visible as the thing it excuses, it grows only
by someone writing a path and a reason, and it grows *with* the filter it
guards.

So the exclusion list is checked in three ways and cannot outlive its reason. An
entry naming a path git no longer carries is refused as stale — it excuses
nothing while reading as coverage. An entry naming a path the scan does reach is
refused as redundant — it says two contradictory things about one directory. An
entry whose reason is blank is refused outright, because an excuse that does not
explain itself is the thing this list exists to prevent. One entry exists:
`input-doors/fixtures/main/tests/`, the test directory of the fixture project
the input-door stand analyses.

A glob would have been shorter and is what was rejected: "anything under a
`fixtures` directory" excuses that entry and goes on excusing every future
directory that happens to sit under one, including a real root someone files
there by mistake.

### Git is asked, not the filesystem — and what that delegates to

What decides whether anyone but the current worktree sees a directory is whether
git carries a file under it. `glob()` answers about one machine, which is how
this direction came to be missing in the first place, and it is the same
reasoning `RegisteredDirectoriesReachTrackedFilesTest` already gives for the
mirror direction. `--others` is included so a root is judged when it is created
rather than a commit later.

**`--exclude-standard` is not `.gitignore`.** It is `.gitignore` plus
`$GIT_DIR/info/exclude` plus `core.excludesFile`, and the last two are per-clone
and per-machine — untracked, invisible to review, and not the same on any two
checkouts. The portability argument therefore has a tracked half and an
untracked half, and only the tracked half is a guarantee.

The tracked half was measured rather than assumed. A developer checkout that
followed this repository's own setup instructions is not the near-empty tree a
fresh worktree is: it carries `website/.venv/`, whose site-packages hold a real
`tests` directory, plus `benchmarks/vendor/` and `node_modules/`. Each is held
out by an explicit `.gitignore` line, and the sweep run against such a checkout
names none of them. Had any of the three been merely absent from the worktree
this was written in rather than ignored, `--others` would have reddened
`composer architecture:check` on every machine except the one that declared the
work green.

The untracked half is stated and not defended: whatever a machine excludes
locally is excluded here too, silently and differently per machine.

Separately, `vendor` and `node_modules` are also filtered by segment inside the
check. That is a second copy of part of the same policy, deliberately, and the
two halves are not in the same position: `.gitignore`'s `/vendor/` is anchored
to the repository root — which is why `/benchmarks/vendor/` needed a line of its
own — so the `vendor` segment filter is load-bearing for any nested `vendor/`,
while the `node_modules` half is defensive.

### Why the revisit condition is not waited out

ADR 0064 priced the design work against deriving a population of root-level
tooling artifacts, and at two subjects judged a literal map cheaper. That price
was right for that population. It is not the price of this one: the population
here is a filename convention, the exclusion list has one entry, and the check
needs no judgement about which top-level directories are "tooling". The
condition was a proxy for a cost, and the cost turned out to be lower than the
proxy assumed.

The gap's own shape is the second reason not to wait. ADR 0064 names it: an
unregistered root leaves nothing behind to notice. The independent clause of its
revisit condition — "if a root-level tooling artifact is ever found unregistered
*after the fact*" — therefore cannot reliably fire, because the state it asks us
to detect is the state in which nothing is detectable. A condition that depends
on noticing the thing it is guarding against is not a condition that will be
met.

## Consequences

- A root-level project whose tests live in a test-shaped directory, landing
  unregistered, reddens `composer architecture:check` by name. The scope of that
  sentence is the whole of what is claimed: see the two residues below.
- `NON_ROOT_TEST_DIRECTORIES` is a new address in AGENTS.md's table, and it is
  the one address there that a **root** never visits: it is where a test-shaped
  directory that is *not* a root is excused. It fails **loudly** in all three
  directions — stale, redundant, reasonless.
- A pre-existing row in that table is falsified by this change and re-derived
  with it. The scan-scope literal was marked as failing **silently**, which was
  true while nothing cross-checked scan-scope membership. It is now the thing
  `assertEveryTestDirectoryIsClaimed()` reads, so for any test-shaped directory
  git carries, omission from the scan scope is refused by name before a single
  row is built. What stays silent there is the dead `scripts/tests` literal,
  which no candidate reaches, and the non-test-shaped shape in residue 1.
- `createIsolatedProject()`'s copy list grows a requirement: it must now hold
  every path the generator's literals name, not only every root the tracked
  configuration declares. Without the `input-doors/` copy, the stale-exclusion
  refusal preempts every planting case whose own refusal is raised later. This
  is recorded in AGENTS.md's table for the next root that moves.
- For the `scripts/<tool>/tests/` and `tools/<tool>/tests/` shapes a reader never
  sees this refusal. `fail()` exits, and
  `assertToolingTestRootRegistrationIsComplete()` runs one line earlier, so its
  one-line message is what prints. The two checks overlap there on purpose —
  that one names a missing *map entry* for a shape whose owner is known, this one
  names an unscanned *directory* wherever it is — but only the earlier one
  speaks.

### The two residues

Neither is closed by this decision, and both are named here because a record
that states a closure without its remainder is how the gap being repaired got
written in the first place.

1. **A test root whose directory is not test-shaped.** `governance/` is the
   tree's one example. Declared as a `<testsuite>` `<directory>`, it is caught
   by `assertSuiteClassifierAgreesWithPhpunit()`, which refuses a declared
   directory `currentSuite()` does not classify. Undeclared and outside
   `autoload-dev`, **nothing sees it** — and nothing runs it either, so it is
   silence about a directory PHPUnit never reaches rather than a test tree going
   unexecuted. This is the half of ADR 0064's gap that survives, unchanged.
2. **Tests with no enclosing test-shaped directory.** `viewer.test.js` beside
   the source it covers, or a layout spelling the directory `e2e` or `cypress`.
   The source derives a candidate from a path segment, so a project wrapping its
   tests in no such segment produces none. Nothing refuses it.
