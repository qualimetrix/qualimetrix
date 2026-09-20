# One name, two declarations: DIT answers for whichever file was read last

## Why

`DitGlobalCollector` builds its child → parent map keyed by the child's **fully
qualified name**, while the edge it reads carries an exact `DeclarationPath` on
the source side. Two declarations of one name therefore write the same key, the
later edge wins, and every declaration of that name is published with the depth
of whichever parent the walk happened to reach last.

Nothing in the report disagrees with itself, because `InheritanceRule` reads the
metric back through the same collapsed key: the rule and the map agree while
both disagree with the source.

This is ordinary PHP, not broken code: a `class_exists()`-guarded polyfill and a
conditional compatibility shim both declare one name in more than one file.

## What was measured (not argued)

Fixtures under a scratch tree, `bin/qmx check --no-cache --format=json`:

| input                                                                  | published `design.dit`                                                |
| ---------------------------------------------------------------------- | --------------------------------------------------------------------- |
| `A.php: Shim extends Base1`, `B.php: Shim extends Base2 extends Base1` | **both declarations report 2**; A's true depth is 1                   |
| the same two bodies, file names swapped so discovery order reverses    | **both report 1**                                                     |
| `--workers=0` / `2` / `4`                                              | identical — the dependence is on file order, not on parallelism       |
| the polyfill shape: `if (!class_exists(Shim::class)) { class Shim … }` | the conditional declaration is registered, and collapses the same way |
| a child of the collapsed name (`Child extends Shim`)                   | inherits the wrong depth, +1                                          |
| NOC: `A.php` and `B.php` both declare `Shim extends P`                 | **`NOC(P) = 2`** for a hierarchy with one subclass named `Shim`       |

Two facts follow that the opening description did not claim:

1. The published depth **moves when files are renamed**. It is not merely wrong,
   it is a function of the traversal order of the file system.
2. `NocCollector` carries a *different* defect of the same family. Its parent
   side is logical by construction and is fine; its **child** side counts edges,
   so one subclass declared twice counts twice.

Blast radius, measured by scanning for repeated class FQNs: `src/` has none and
the gate corpus had none before this plan added a case. So the ordinary tree is
expected to be byte-identical, and that expectation is itself checkable.

## The decision

DIT is a fact about a **declaration** — the source text says which parent this
declaration extends. NOC is a fact about a **name**: `extends P` names a name,
and which file declared `P` is not knowable from the edge. The fix follows that
split rather than making both sides exact.

| question                                                 | answer                                                   |
| -------------------------------------------------------- | -------------------------------------------------------- |
| child side of DIT                                        | exact: the map is keyed by the child's `DeclarationPath` |
| parent side of DIT                                       | logical, because the edge carries no more than that      |
| a parent name whose declarations disagree about depth    | **max**                                                  |
| the one value the logical class projection still carries | max over that name's declarations                        |
| NOC                                                      | count **distinct child names**, not edges                |
| NOC per declaration                                      | not introduced: there is no fact to attach               |

**Why max, and what it costs.** Two reasons, neither of which is "the channel
judges higher as worse" — that is the direction of a *judgement*, not a policy
for collapsing a *measurement*, and citing it would manufacture a precedent.
The reasons are that max does not depend on traversal order, and that it does
not hide a finding the source can justify: some declaration really is that deep.
The cost is real and is not hidden either: in the polyfill shape the deeper
declaration is often the one a live autoloader never reaches, so the name-level
value can describe a branch that does not execute. That cost is paid at the
*name* level only — the per-declaration values, which are what the rule now
publishes, are exact for both branches.

Rejected: refusing to answer when a parent name is ambiguous (the precedent is
`MetricSubjectIndex::logicalCallableMetrics()`, which returns `null` for ≠ 1
declarations). It removes a published metric for a class whose own declaration
is unambiguous, and the rule would then silently skip it.

Named, not fixed: the same logical bag collapses its *other* class metrics by
last-writer-wins (`MetricBag::merge()`), so after this change `design.dit` is
the one key in it with a stated policy. Making that uniform is a repository-wide
question about `InMemoryMetricRepository`, not about inheritance.

## Contracts

`MetricRepositoryInterface` gains the door declarations lack:

```
addSubjectScalar(MetricSubject $subject, string $key, int|float $value): void
    // the declaration-addressed counterpart of addScalar(SymbolPath, …)
```

This was written without it first, on `addSubject()` and a one-key bag, and the
product's own analysis of this repository refused that: it makes every global
collector a dependent of `MetricBag`, which sits on its CBO threshold at 68, so
the first such collector tipped it over. Measured with the three changed files
reverted — a clean tree reports nothing — so the edge was this change's, not a
pre-existing one.

The walk moves to `InheritanceDepthResolver`, for the same reason and by the
same measurement: with the second index inside it, `DitGlobalCollector` matched
all three god-class criteria (WMC 49 ≥ 47, LCOM 3 ≥ 3, LOC 388 ≥ 300). The
collector keeps the protocol and the repository pass; the resolver owns the
index and the depth:

```
InheritanceDepthResolver::fromGraph(graph, projectClassNames): self
    ->depthOf(declarationCanonical): int
```

Both of the resolver's indexes come from the **graph's `Extends` edges**, not
from the repository. That is deliberate: the old `isset($parentMap[$parentFqn])`
test was keyed by edge sources, so deriving the per-name index from the same
edges preserves that behaviour by construction rather than by argument. The
repository supplies the *write* population only.

`NocCollector::buildChildrenMapFromGraph()` keeps its signature; its counter
becomes a set of distinct child logical canonicals.

## Resolution order inside `InheritanceDepthResolver::depthOf()`

Stated because it must stay behaviour-preserving for every tree that declares
each name once — that is the whole corpus, the benchmarks and this repository.

1. the declaration has no parent edge → `0`
2. the parent is a PHP builtin → `1`
3. the parent name has declarations among the edge sources →
   `1 + max(depth of each, cycle-marked branches excluded)`; if every branch is
   cycle-marked → `1`
4. the parent name is a known project class with no edge of its own → `1`
   (the root case stage 01 of the DIT campaign introduced; kept verbatim)
5. otherwise → `1 + resolveExternalClassDit(parent)` (untouched; campaign
   stage 02 replaces this step)

Step 3 subsumes today's "parent has a parent of its own" branch: a parent that
is itself a root yields max depth `0`, so the child still gets `1`.

Publication, which the steps above do not decide:

6. each class declaration carrying DIT is written back onto **its own subject**
   via `addSubjectScalar`
7. then, per name, `addScalar` writes the max onto the logical class — after the
   per-declaration writes, so the last-writer-wins side effect cannot survive
8. `InheritanceRule` reads `getSubject($subject)` for the declaration it already
   iterates, instead of `get($subject->toSymbolPath())`

Steps 6-8 are one indivisible change: 6 without 8 leaves the published finding
collapsed, and 8 without 6 regresses every cross-file chain to the stale
per-file value.

## Enumeration of consumers

[measurement/dit-noc-consumers.tsv](measurement/dit-noc-consumers.tsv) — every
tracked file that names `design.dit` or `design.noc`, grouped into writer,
reader, name-only, value oracle, registry and documentation, with what each one
holds and what this change does to it.

**How it was obtained, and what the method cannot see.** `git ls-files | xargs
grep -l` for the two channel names (80 files), then four narrowing sweeps: the
`MetricName::DESIGN_*` constants in `src/`; the generic class-level readers
(`all(SymbolLevel::Class_)`, `allLogicalClasses()`), which publish whatever key
a class bag holds; the declaration readers (`allDeclarations()`); and, for the
files that pin numbers rather than names, reading the assertions. Not covered,
and stated rather than assumed: a computed-metric formula in a **user's**
`qmx.yaml` names `design.dit` by string in a file this repository does not hold —
that channel reads the logical projection, and is the reason the projection keeps
carrying a value at all.

**Registries the sweep found that `composer check` does not read.** Both are
already stale, measured rather than supposed, so neither can be trusted as a
witness that nothing moved. `finding-gate/enumeration-static-channels.tsv`
records `InheritanceRule.php:75,121` where the tree says 68 and 114.
`promise-effect/promise-ledger-frozen-ranges.tsv` hashes line ranges inside
`website/docs/rules/design.md`, which P3 edits — and a run of
`composer promise-ledger:freeze:check` in a throwaway worktree of `origin/main`
reports **179 drifted rows**, the same 179 this branch reports, including rows
in `website/docs/usage/cli-options.md`, which nothing here touches. So the
freeze is not re-taken in this change: re-freezing would bless 178 drifts that
belong to somebody else's edit, under cover of one that belongs here. Both are
refreshed by `composer enumeration:channel-universe` and
`composer promise-ledger:freeze`, outside the aggregate.

## Work packages

Disjoint file sets. P1 → P2 → P3 is the review order; P2 does not depend on P1.

### P1 — declaration-exact depth

Files: `src/Analysis/Evidence/Design/Inheritance/DitGlobalCollector.php`,
`src/Analysis/Evidence/Design/Inheritance/InheritanceRule.php`,
`docs/internal/modular-architecture-manifest.json` (any new cross-owner import
from `Design.Inheritance` must be listed there before `architecture:check` will
accept it), the regenerated
`docs/internal/generated/modular-architecture/*`,
`tests/Analysis/Evidence/Design/Unit/Inheritance/DitGlobalCollectorTest.php`,
`tests/Analysis/Evidence/Design/Unit/Inheritance/InheritanceRuleTest.php` (it
mocks `get()`; a mock answers the new call with an empty bag and goes quietly
green), and a new integration test beside `DitAggregateRunTest`.

**The guard, and why the existing ones are not it.** `DitAggregateRunTest`
asserts namespace and project `dit.avg/max/p95`, which are built from logical
classes and stay correct in the half-done state; `UnloadableExternalParentRunTest`
asserts only that the run did not error and exited 0. No test in `check:code`
asserts the **value of a `design.dit` finding**. The new integration test is that
witness: a subprocess run with `--format=json` over a three-file chain
(`Leaf extends Middle extends Base`), asserting `violations[].metricValue` per
declaration subject, plus the duplicated name reporting 1 and 2. It is written
and shown to fail on the half-done state before the rest of P1 is trusted.

Seeding note: the 25 seed sites in `DitGlobalCollectorTest` use
`add($logicalPath, …)` while `createExtends()` hardcodes `test.php`. One helper
must produce the `DeclarationPath` for both the seed and the edge source, or
every class scores 0 and the failure reads as a product defect. The rewrite is
scripted, then the diff is read.

DoD: on the duplicate-name fixture, `bin/qmx check --format=json` publishes
`design.dit` findings of 1 and 2 for the two declarations of one name and 3 for
its child; the same tree with the two files renamed publishes the same values;
`composer check:code` **and** `composer architecture:check` green.

### P2 — NOC counts subclasses, not declarations

Files: `src/Analysis/Evidence/Design/Inheritance/NocCollector.php`,
`tests/Analysis/Evidence/Design/Unit/Inheritance/NocCollectorTest.php`.

DoD: two declarations of one child name contribute `1` to their parent; two
declarations of one *parent* name still report the same NOC (asserted, so it
stays a decision rather than an accident); `composer check:code` green.

Named and unchanged: `NocRule` iterates declarations, so a duplicated name still
emits one NOC finding per declaration and its remediation minutes still count
twice. That is finding emission, not measurement, and moving it is a separate
contract change.

### P3 — publication

Files: `finding-gate/cases/duplicate-declaration/**` (present: `composer.json`,
`qmx.yaml`, `case.json` with `"coverage": "auxiliary"` — `cases/design` already
owns both channels, so an authoritative second producer would be
`coverage-multiplicity`), `finding-gate/declared-delta.tsv` and
`finding-gate/declared-delta/`, `CHANGELOG.md`, `docs/adr/00NN-….md` plus the
ADR index, `src/Analysis/Evidence/Design/README.md`,
`website/docs/rules/design.{md,ru.md}`, `docs/internal/plans/README.md`.

The expected gate delta is known in advance and is wider than the duplicated
declarations, which is not a defect: `Base` is declared once, yet its NOC
honestly moves 4 → 3 because one of its children is declared twice. The delta is
exactly — `design.dit` for `Shim@src/ShimNative.php` 1 → 2 and for `Consumer`
2 → 3; `design.noc` for `Base` 4 → 3; the logical `design.dit` of `Shim` in
`format:metrics` 1 → 2; and the corresponding rows of `baseline:explain` for the
two declared subjects. Anything outside that list is a finding, not a delta.

The deltas are produced by `composer gate -- --reference=origin/main
--derive-declared-delta` and each row is given a written reason; the mode exits 4
on a write and is never a verdict.

**Four addresses this plan did not name, found by running it.** A new ADR file
has two of its own: a row in the ADR index, and a row in the ownership table
inside `scripts/generate-modular-architecture-production-inventory.php`, which
refuses an unclassified documentation path by name. A declared delta
is not enough on its own: the gate refuses a delta that moves a *compared*
field unless `finding-gate/declared-field-moves.tsv` licenses that exact
`(surface, field, from, to)` tuple — 33 of them here, one per moved
`message`, `recommendation`, `metricValue` and `techDebtMinutes` value. And
`governance/Channel/ChannelJudgedMetricDriftTest` compares a finding's
magnitude against `--format=metrics`, which addresses a class by name; for a
name declared twice its oracle cannot say which declaration the row describes,
so it now stops short of calling that a disagreement, narrowly, with the
declared key still required to exist. That excuse is written into the control's
own enumeration of what it does not cover, which is where it can be found
again.

DoD: the gate delta matches the list above and nothing else; `composer check`
green; `composer promise-ledger:freeze:check` green (or re-frozen, because the
website edit shifts hashed line ranges); the ADR names the old and the new
surface; the CHANGELOG carries a `Breaking` entry.

## Test plan

| level       | what it pins                                                                      |
| ----------- | --------------------------------------------------------------------------------- |
| unit        | two declarations, different parents → different depths; same parent → same depth  |
| unit        | a parent name with two depths → child takes the max, both insertion orders        |
| unit        | cycle through a duplicated name still terminates and yields today's `1`           |
| unit        | NOC: one child name declared twice counts once; two distinct children count twice |
| integration | a run's `design.dit` **findings**, per declaration, over a cross-file chain       |
| integration | the same tree with the two files renamed — the findings must not move             |
| gate        | one auxiliary corpus case, both channels, both declarations in `explainSubjects`  |

A gate run whose reference carries the same product code proves the corpus case
is complete and stable, not that the change is safe; the witness for the order
dependence is the renaming integration test. That run has been made, and it is
GREEN.

## Out of scope, named

- **Two declarations of one name inside one file.** They do not survive to the
  repository at all: every class producer keys by FQN within a file, so the
  second body overwrites the first and only one subject is published — measured,
  and already recorded by
  `tests/…/Measurement/Integration/Identity/ClassProducerOrdinalTest.php`. Two
  consequences this plan accepts rather than fixes: the DoD's renaming claim
  holds for cross-file duplicates only, and the max of step 3 is taken over an
  incomplete set when the duplicate is in one file.
- **Cycles remain order-dependent.** For `A extends B` and `B extends A` the
  walk scores whichever node it enters first as 2 and the other as 1. `max` does
  not repair this, because the cycle marker makes a branch's value depend on the
  entry point. The determinism claim above covers acyclic hierarchies.
- `InheritanceDepthCollector::calculateDit()` — the per-file pass publishes no
  depth.
- **What `resolveExternalClassDit()` does.** Its body is untouched, but it has
  moved: it is now a private static of `InheritanceDepthResolver`, carried
  along when the walk left the collector. Stage 02 of the DIT external-ancestry
  campaign addresses it by name, so that plan is corrected in the same change.
- Declaration-exact NOC, CBO, ClassRank and layer membership. Only the two
  metrics this plan names were measured.
