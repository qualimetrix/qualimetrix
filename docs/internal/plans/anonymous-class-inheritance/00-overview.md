# An anonymous class must not lend its own declaration to the class that encloses it

One stage, six packages, one merge unit. Companions: `enumeration-declaration-channels.tsv`
(every edge the defect mislabels and every reader of it) and `enumeration-method.md`
(how that list was obtained and what the method cannot see).

## The defect

A named class's `DependencyContext` stays current while the visitor walks an
anonymous class, so edges that describe the **anonymous class's own
declaration** are recorded with the enclosing named class as their source. The
enclosing class then appears, in the model, to declare things it does not.

Two different code paths produce these edges, which is why a cure at one site
is partial:

- **The anonymous class header** — `extends`, `implements`, attributes.
  `DependencyVisitor::consumeAnonymousClass()` routes the nameless `Class_`
  into `ClassLikeHandler` with the enclosing context.
- **The anonymous class body** — `use T;`. This never reaches
  `ClassLikeHandler`; it arrives at `TraitUseHandler` through
  `dispatchInCurrentContext()`, whose context is still the enclosing class.

Measured (`bin/qmx`, fixtures under one namespace each):

| Channel                         | Witness                                                                | Today                           | Correct                |
| ------------------------------- | ---------------------------------------------------------------------- | ------------------------------- | ---------------------- |
| `extends` → DIT                 | `An\Host` `design.dit`                                                 | 2                               | 0                      |
| `extends` → NOC                 | `An\L1` `design.noc`                                                   | 1                               | 0                      |
| `extends` → aggregates          | namespace `design.dit.max`                                             | 2                               | 1                      |
| `extends` → layer membership    | `debug:layer-assignment An\Host` under `extends: ['An\L1']`            | "Matched by: extends An\L1"     | no match               |
| attributes → layer membership   | same under `attributes: ['At\Mark']`, attribute on the anonymous class | "Matched by: attribute At\Mark" | no match               |
| `trait_use`                     | `graph:export`                                                         | `Tr\Host -> Tr\T ['trait_use']` | no edge from `Tr\Host` |
| `implements` → layer membership | not yet measured — P1                                                  | expected to match               | no match               |

The layer-membership rows are the consequential half: architecture policy picks
a class's rule set from its layer, so the defect silently moves a class under
rules that were never written for it. `ClassContextFactory` walks `extendsMap`
as a BFS closure, so the enclosing class inherits the anonymous class's whole
ancestry, not just its direct parent.

The defect is live in this repository: `LoggerFactory` reports `design.dit = 1`
while extending nothing (`new class ($loggers) extends AbstractLogger`). Its own
`qmx.yaml` declares layers by `patterns:` only, so no layer assignment moves
here; the self-analysis shift is `LoggerFactory design.dit` 1→0 and three
`dit.avg` values.

This contradicts Critical Rule 6 and predates `e5c48360`; that commit only
widened the blast radius from a class-level number to the aggregates.

## What the cure must not break

`new class extends L1 {}` produces **no** `New_` edge — `InstantiationHandler`
fires only when `New_->class` is a `Name`, and here it is a `Class_`. The
mislabelled edge is therefore the **only** record that `Host` references `L1` at
all. Invariants to hold, each with a named witness in P2:

- `Host.coupling.ce` and `coupling.cbo` unchanged (measured 1 on the fixture).
- `L1.coupling.ca`/`cbo` unchanged.
- The edge still present for ClassRank, cycle detection and the
  architecture **violation** check — membership is what must stop seeing it,
  not the dependency.
- For a builtin parent: `DependencyGraphBuilder::retainGraphDependencies()`
  keeps an edge to a PHP builtin **only** when its type is `Extends`, so any
  cure that changes the type deletes `new class extends \stdClass {}` from the
  graph outright. Measured: `Bi\Factory` `coupling.ce` = 1 today, entirely from
  that edge.

## Design fork — settled below, owner may overrule

- **A — retype the anon edges to `New_`.** Rejected on four grounds, all
  confirmed against code: it deletes the builtin-parent edge (above);
  `description()` publishes "instantiates", which is false of an `implements`
  target; `isStrongCoupling()` flips these edges from strong to weak; and
  `relations: [inheritance]` stops covering them, although `new class extends
  Base` *is* subclassing and deptrac — the tool this rule replaces — attributes
  it to the enclosing class. That last point makes A a `Breaking` policy change
  bought for nothing.
- **B — new enum case(s).** Fixes `extends` and `implements` only. Attributes
  are already their own type, and `trait_use` arrives by another path, so both
  would still need a second mechanism. B therefore buys a partial cure at the
  price of splitting the `inheritance` alias and touching every `match` on
  `->type`.
- **C — drop the edge.** Rejected: it is the sole witness of a real dependency.
- **D — mark the edge as not a declaration fact of its source. Recommended.**
  Add one defaulted field to the readonly VO `Dependency` meaning "this edge
  describes an anonymous class nested in the source, not the source itself".
  Set it for the four channels above. Type, `description()`,
  `isStrongCoupling()`, the `relations:` vocabulary and `BaselineEdge` identity
  are all untouched, so no published contract shifts and deptrac parity holds.
  Readers split cleanly: **declaration readers** drop flagged edges
  (`DitGlobalCollector`, `NocCollector`, and `ClassContextFactory`'s
  `extendsMap`/`implementsMap`/`attributesMap`); **dependency readers** ignore
  the flag entirely (coupling, ClassRank, cycles, violation checks,
  `JsonGraphExporter`). One production construction site
  (`DependencyContext::addDependency`), so a defaulted field breaks nothing,
  and the persistent cache stores parsed AST nodes rather than `Dependency`.

  It is **not** free of serialization, though. `Dependency` crosses the worker
  boundary in parallel mode, and `FileProcessingResultWireFormatTest` pins its
  current four-argument shape. A flag that does not survive IPC would make the
  default parallel run disagree with `--workers=0` silently — and `--workers=0`
  is what every measurement in this plan used.

  Open question for P1, not to be settled by default: the flag is invisible in
  the published projections (`LayerViolationFinding`, `JsonGraphExporter`), so a
  consumer would see an edge that exists but does not count toward membership,
  with nothing saying why. Either surface it in the graph export or record in
  the ADR why it stays internal.

Under D this is a `Fixed`, not a `Breaking`.

## Packages

**Merge unit: one PR.** The packages are an execution order, not separate
merges — P1 alone would ship a behaviour change with no CHANGELOG entry, and P4
alone would leave a declared-delta row with nothing retiring it.

### P1 — the cure
Files: `ClassLikeHandler.php`, `DependencyVisitor.php`, `DependencyContext.php`,
`Contract/Dependency.php`, `DependencyVisitorTest.php`.

Two sites, because there are two paths. The header edges are flagged where
`ClassLikeHandler::handleClass()` already sees `$node->name === null`. The
`trait_use` edge needs the visitor to know it is inside an anonymous class —
a depth counter raised in `consumeAnonymousClass()` and lowered in
`leaveNode()`, which today skips nameless class-likes.

Do **not** repeat the plan's earlier claim that `consumeAnonymousClass()` is
what keeps the anonymous body attributed to the enclosing class. It is not:
body attribution comes from `enterNamedClassLike()` never firing for a nameless
node and `leaveNode()` not clearing the context. `consumeAnonymousClass()` only
routes the header. The ADR must record the real mechanism.

Usage edges from the anonymous body (`new`, static calls, type hints) stay
attributed to the enclosing class and stay unflagged: they are usages, not
declaration facts, and no membership criterion reads them.

`itAttributesAnonymousClassExtendsAndImplementsToTheEnclosingClass` asserts
today's behaviour as intended. Rewrite it; keep the half that says the edge
exists with the enclosing source, change the half about what that edge means.
Measure the `implements` membership row left open in the table above.

Two edges of the counter's behaviour, both measured, both needing a fixture:

- **Nesting deepens the defect.** An anonymous class two levels deep still
  lends its trait to the outermost named class: `Tp\Outer -> Tp\T
  ['trait_use']`. The counter must treat any depth ≥ 1 as anonymous, not just
  the immediate one.
- **A top-level anonymous class emits nothing today** — `consumeAnonymousClass()`
  returns false with no context, and the body's `use T;` is dropped with it.
  `itIgnoresAnAnonymousClassWithoutAnEnclosingNamedClass` pins this and must
  stay green; a counter that changes it is a regression, not a fix.

`FileProcessingResultWireFormatTest` must be extended in this package, not a
later one: it is the only thing between a flag that survives IPC and a tool
whose parallel and serial runs report different metrics.

**First step of P1, before any edit:** run the four fixtures in parallel mode on
the unfixed tree and record the values. Without that pre-change parallel
baseline, "parallel agrees with serial afterwards" still passes for a flag that
serializes to a wrong value consistently on both sides.

DoD: all four channels flagged; the edge still present in `graph:export` for
each; `Host.ce`/`cbo` and `L1.ca` unchanged on the fixtures; the builtin-parent
fixture `Bi\Factory.ce` still 1; and every fixture in this plan re-measured
**with the default worker count as well as `--workers=0`**, agreeing.

### P2 — regression tests on the path that publishes the value
Files: tests under `tests/Analysis/Evidence/Design/…/Inheritance/`, plus an
integration test over extraction → graph → global collector → aggregation.

`InheritanceDepthCollector` **does not publish** `design.dit` — its own docblock
says the definition belongs to `DitGlobalCollector` — and its visitor only ever
tracks named classes, so it never saw the anonymous edge in the first place.
A test added there cannot go red on this defect no matter what fixture it uses.
The regression test belongs on `DitGlobalCollector` and on the integration path.

The existing `itIgnoresAnonymousClass` is insensitive for a second, independent
reason: its parent is `\stdClass`, whose depth is 0 either way. Verified green
on the unfixed tree. Repair it so its name becomes true, but do not count it as
the regression witness.

Cases: `Host.dit = 0`; `L1.noc = 0`; namespace `dit.max = 1`; and the
legitimate-case witnesses from "What the cure must not break".

Red-before-green mechanism, since P1 lands first: write each assertion against
the pre-P1 commit and record the failure, or run it on a `mktemp -d` copy with
the cure reverted. "It passes after P1" is not evidence; the recorded red is.

### P3 — layer membership tests
Files: tests under `tests/Analysis/Policy/Architecture/`.

Covered, and done: all three criteria — `extends:`, `implements:`,
`attributes:` — stop matching the enclosing class, plus the transitive
`extends:` case (membership walks a BFS closure) and an `exclude:` block
built from the same maps. See
`tests/Analysis/Policy/Architecture/Integration/AnonymousClassInheritanceIntegrationTest.php`.

Not covered, and not coverable with the current wiring: template layers.
`ArchitecturePolicy::prepare()` builds the `ClassSet` handed to
`LayerExpansionStage` with a brand-new, **unbound** `ClassContextFactory` —
the dependency graph is only bound to the registry's own factory one line
later, via `registry()->bindGraph($graph)`. So every non-pattern criterion
(`extends`/`implements`/`attributes`) sees an empty `ClassContext` during
tuple observation regardless of the anonymous-class cure: under `match: all`
an empty context never satisfies `extends`, so the template expands to zero
concrete layers even for a genuine, non-anonymous positive control; under
`match: any` (the default), `TupleExtractor` OR's `extends` with the pattern,
so a class already bound by the pattern is observed independently of what
`extends` says, making the criterion inert for classification either way.
This is a separate, pre-existing defect in `ArchitecturePolicy.php`, outside
this plan's file set (P1 never touches it), and it blocks a template-layer
test from being meaningful — not just from being written. Recorded as a test
note in `AnonymousClassInheritanceIntegrationTest.php` rather than fixed
here; the owner decides separately whether to cure it.

### P4 — gate coverage
Files: `finding-gate/cases/design/`, `finding-gate/cases/layers/` and their
`qmx.yaml`, `declared-delta.tsv`.

The corpus contains **zero** `new class`, so a GREEN gate proves nothing here.
Two cases are needed, not one: `design` owns the metric channels and `layers`
owns `architecture.layer-violation`, and neither carries the other's channels.
Worse, no corpus case declares any graph-based criterion at all — every case
config uses `patterns:`/`suffix:` — so the layer-membership half has zero gate
coverage before and after unless the `layers` case gains such a criterion.

A declared row is valid against **one** reference. While this PR is open that
reference is the pre-change commit, so the rows are live and the gate is green
with them in place. Retiring them inside this PR is self-contradictory: the very
next run against the same reference would then see an undeclared diff. They go
stale only **after** the merge, when any newer reference already carries the
change — which is exactly the tail #112 had to clear after #92 left its rows
behind. So: declare here, and carry the retirement as a named obligation for the
commit that follows the merge. `graph:export` is not among the gate's compared
surfaces, so the edge-level change stays unwitnessed there — state that rather
than implying the gate covers it.

DoD also names `governance/Channel/ChannelLevelDeclarationDriftTest.php`, which
runs `bin/qmx` over every `cases/*/case.json` inside `composer check` and which
a new fixture can redden. Reference for the gate run is the pre-change commit.

**Known fixture weakness.** The `layers` case observes the extends-membership
cure through the `architecture.layer-violation` finding's *message text*
(which layer name gets blamed), not through the finding's presence or
absence. An earlier shape that toggled the finding itself produced
`finding-count-mismatch` noise unrelated to what was being tested, so message
text was the deliberate choice — but it means the case is sensitive to
message wording and would not by itself distinguish "membership fixed" from
"membership fixed and the message format also changed." Recorded here as a
known compromise, not a defect to fix in this package.

### P5 — documentation
Files: `CHANGELOG.md`, `docs/adr/` (new ADR), `src/Analysis/Evidence/Design/README.md`,
`src/Analysis/Evidence/DependencyModel/README.md`, `src/Analysis/Policy/Architecture/README.md`,
`website/docs/rules/design.md` + `.ru.md`, `website/docs/rules/architecture.md` + `.ru.md`.

There are no separate DIT/NOC pages — both live in `design.md`. `relations:` is
documented in `architecture.md`, the page describing the surface this change
reasons about; it must say what an anonymous class contributes.
`default-thresholds.md` and `remediation-time.md` mention the metrics; check
whether either states a value this change moves.

The CHANGELOG entry names `design.dit`, `design.noc`, their aggregates, and
layer membership by all three criteria. The ADR records why C and A were
rejected — the edge is the sole witness of a real dependency, and retyping it
would have shifted `relations:` semantics away from deptrac parity.

### P6 — validation and review
`composer check` in full, `composer architecture:check`, the gate per P4, and
`bin/qmx check src/` to confirm the self-analysis shift is exactly the predicted
`LoggerFactory design.dit` 1→0 plus three `dit.avg` values. A larger shift is a
finding to report, not a baseline to regenerate.

## Out of scope — reported, not fixed

Both surfaced while reviewing this plan; neither is caused by it, and neither is
fixed by the cure above. Each needs the owner's decision separately.

- **`attributes:` membership already ignores where the attribute sits.**
  `FunctionLikeHandler` and `PropertyHandler` emit `Attribute` edges whose
  source is the enclosing class, so `attributesMap` mixes a class's own
  attributes with those of its methods, parameters and properties. Measured: a
  class whose **method** carries `#[Mark]`, the class itself carrying nothing,
  is assigned to a layer declared `attributes: ['Am\Mark']` —
  "Matched by: attribute Am\Mark". The criterion is documented as class-level.
  Wider than anonymous classes; flag D does not address it.
- **Stale docblock** at `ClassContextFactory:49-55`, describing a "no-graph
  mode" in `debug:layer-assignment`.
