# 0079. A Criterion the Run Cannot Answer Is Undecidable, Not a Non-Match

**Date:** 2026-09-23
**Status:** Accepted

## Context

Layer membership ([ADR 0006](0006-architecture-rules-declaration-order.md),
[ADR 0014](0014-deptrac-retirement.md)) is decided by five criterion kinds:
`patterns`, `suffix`, `attributes`, `implements` and `extends`. Three of them —
`attributes`, `implements`, `extends` — are answered from facts the run
collected: the attribute set of a declaration and the transitive closure of its
`extends`/`implements` edges. `patterns` and `suffix` read the name alone and
need nothing collected.

A collected fact can be missing, and the missing case was spelled as the
negative one. Two shapes were measured on a fixture stand:

- a criterion naming an **ancestor whose intermediate link was not analysed**.
  The closure walk stops at the unanalysed link, the ancestor set comes back
  without the named type, and the criterion answers a confident "no" about a
  class that does extend it.
- a **subject the run never analysed at all** — the far end of a dependency
  edge that points outside `paths:`. It carries no attributes, no interfaces
  and no parents, so *every* graph-backed criterion answered "no" about it.

The second shape was in no review finding and in no brief; it was found by
reproducing the first.

What is missing is narrower than "anything outside `paths:`", and the boundary
decides where the cure belongs. A criterion naming the class's own **direct**
parent or interface still *matches* even when that type is vendor code, because
the edge is recorded from the analysed child. What cannot be answered is a
*miss*: a criterion that names nothing on the recorded chain, for a class whose
chain passes through a declaration the run did not read — that declaration may
extend or implement the named type. A class or interface PHP itself declares is
not such a declaration: its supertypes are PHP's, known without reading a file.

A wrong "no" here is not a cosmetic defect. A class that belongs to a layer and
is told it does not gets judged by another layer's allow-list, or by none at
all, and the report says nothing about the doubt: the reader cannot tell "no
layer claims this class" from "no layer could be asked".

Template expansion made the same question twice. `TupleExtractor` carried its
own copy of the non-pattern criterion predicate, so observation — which decides
whether a concrete layer exists at all — and runtime matching could, and did,
disagree while each looked right on its own.

## Decision

**A criterion has three outcomes, and the third one reaches a reader.**
`CriterionOutcome` is `Matches`, `DoesNotMatch` or `Undecidable`, and
`CriteriaEvaluation::outcome()` is the single place where a `MatchMode` combines
per-kind outcomes into one verdict. The combination is Kleene:

- `match: any` is Kleene OR. One firing kind decides regardless of what the
  undecidable ones would have said; only a run that decided *every* declared
  kind may answer `DoesNotMatch`.
- `match: all` is Kleene AND, mirrored. One kind that definitively did not fire
  decides; every declared kind must fire before the spec may answer `Matches`.

Positive membership, the `exclude:` clause and template observation all ask that
one method, so a mode rule cannot hold on one side of the slice and not the
other. `LayerCriteriaMatcher::evaluate()` and `LayerDefinition::excludeOutcome()`
are the shared entry points; the duplicate predicate in `TupleExtractor` is gone.

The sub-decisions below carry the weight, and each had a cheaper alternative.

**A chain is complete per criterion kind, and PHP's own classes end it.** The
walk records where the parent-class chain was cut and, separately, which
interfaces it reached without facts. `extends` is decidable when the parent
chain is complete; `implements` when the parent chain and the interfaces above
it are. An unread parent class may extend and implement anything, so it reaches
both kinds; an unread interface can hide only interfaces, since no interface
declares a parent class. A class or interface PHP declares is followed through
`PhpBuiltinClassHierarchy`, a static table beside `PhpBuiltinClassRegistry`
(see [ADR 0075](0075-the-builtin-class-list-is-compared-never-generated.md)),
and so `extends: ['\Exception']` and `implements: ['\Throwable']` are answered
for a class extending `\RuntimeException`, both ways. The same holds when a PHP
class is itself the subject, met only as the far end of an edge: its supertypes
and class-level attributes are read the same way rather than reported as
missing. For an interface, the `extends` walk follows the interfaces it
extends. The interfaces PHP adds without their being written — `UnitEnum` and
`BackedEnum` on an enum, `Stringable` on a class or interface declaring
`__toString()` — are recorded as declaration edges, because a confident "no"
from `implements: ['\UnitEnum']` about an enum is the wrong answer this decision
exists to remove. A criterion FQN is stored without its leading `\`, because that
separator is the only way to write a global-namespace class and the run records
no name with it.

The cheaper alternative was one completeness flag for all three graph-backed
kinds, treating every node outside the analysed set alike. It was the first
implementation, and it was measured wrong in the direction this decision exists
to prevent: a class extending `\RuntimeException`, or implementing a vendor
interface, became undecidable for every `extends:` it did not match. Which classes
triggered it depended on an accident of the graph builder, which kept an
`extends` edge to a PHP class and dropped an `implements` edge to a PHP interface.

That accident had a second face: a criterion naming a PHP interface a class
implements *directly*, or a PHP attribute it carries, answered a confident "no",
because the edge never reached the policy. The builder drops edges to PHP's own
classes so that they count toward no coupling metric, and membership was reading
the same filtered view. What a class declares is not a coupling question, so the
graph now answers it separately: `DependencyGraphInterface::getDeclarationDependencies()`
returns every `extends`, `implements`, `trait_use` and attribute edge, PHP target
or not, and `ClassContextFactory` reads only that. Every coupling view — the
dependency lists, the class set, Ce/Ca in both namespace scopes, ClassRank, the
layer allow-list check and `graph:export` — reads what it read before; the
metrics of this repository's `src/` were compared before and after, key for key.
The alternatives were rejected on that ground: flagging the edge and keeping it
in the shared list would have made every coupling reader filter it, and one that
forgot would move a metric; keeping it only for membership inside the policy
would have needed a second extraction of what the graph already records.

**An unanswered layer never withdraws a match.** Two shapes leave a class
matched by one layer while the run cannot answer another question that would
decide its ownership: a layer declared *earlier* whose criteria cannot be
answered, and the matched layer's *own* `exclude:` clause that cannot be
answered. A strictly three-valued reading makes the assignment unknown in both,
and both were first built that way for `exclude:`. That reading was rejected
for both, for one reason: withdrawing the match leaves the class in no layer,
no allow-list judges its edges, and real violations stop being reported. It
trades a wrong answer for a missing one, which is the worse of the two, and
under the default `coverage-gap: ignore` nothing said the answer had gone
missing.

The alternative for `exclude:` was to keep withdrawing the membership and make
the loss visible regardless of the coverage mode. It was rejected because it
repairs the report and not the verdict: the class would still leave the layer,
its edges would still go unjudged, and every configuration with such a clause
would gain a mandatory diagnostic in place of the violations it lost.

**Which unanswered layer is a doubt is decided once, in the walk.** A layer
declared after the assigned one is not a doubt: first match wins, so it could
not have owned the class whatever it answered. `LayerRegistry::undecidedLayers()`
applies that rule while it walks the layers in declaration order — every
unanswered layer for a class nothing matched, only those declared no later than
the assigned layer otherwise — and every reader takes the list as it comes:
`debug:layer-assignment`, whose "it can change" is then true of every layer it
names, and the evidence walk that counts doubted assignments. The first version
left the list unordered and let one reader filter it; the other printed
"it can change" for a layer that could not change anything, and the two
disagreed about the same class. A separate exit of the same walk,
`unansweredExcludeLayers()`, names every matching layer whose `exclude:` went
unanswered, winning or not, because the reader that asks — the inert-clause
check below — asks about the clause, not about the assignment.

**A doubt is information, not a gap.** An assignment in doubt is in a layer,
its edges are judged, and no `layers:` entry is missing, so it does not raise
`architecture.coverage-gap`. That channel is a configuration error: it fails
the run at any severity, and letting a doubt raise it failed every fully
covered project whose `extends`/`implements` layer, declared before its
`patterns` layers, met a vendor class at the far end of an edge — the
vendor class is never analysed, so the earlier layer cannot be answered about
it. Measured on 2026-09-23 with such a layout and complete coverage, doubted
assignments numbered 159 on doctrine/orm, 101 on composer/composer and 410 on
laravel/framework, of which 122, 93 and 364 were symbols outside the analysed
paths; every run exited 2 with no other error, where the previous revision
exited 0. The doubt is published instead by `architecture.doubted-assignment`,
a channel of `architecture.layer-violation` reported at `info`, so it never
gates: one finding per run, only while the coverage mode is not `ignore`, with
the count split into analysed classes and symbols outside the analysed paths
and a recommendation for each kind present. `architecture.coverage-gap` still
names the count in its text when it fires for its own reasons, and points
there. The same three runs now exit 0 with the doubt count unchanged. Declaring
the vendor `patterns` layers first removes the outside-the-paths share (to 37,
26 and 47 on the three projects); declaring the `extends:` layer last, with its
population carved out of the earlier layers by a decidable `exclude:`, removes
the doubt entirely.

The alternative was to keep the doubt inside `architecture.coverage-gap` and
lower the finding to `info` when the doubt is all there is. It was rejected
because a configuration-error channel refuses to report below `warning` —
a configuration error printed as `info` would display a weight it does not
have — and because a severity that depends on which sentences the message
carries makes one channel two. `debug:layer-assignment` alone was not enough
either: it refuses a class the run did not analyse, and most doubts are exactly
such classes.

**An exclude clause that could not answer is not inert.**
`architecture.unmatched-exclude` asks whether a clause ever made a difference.
A clause that could not be answered for a class its layer caught has not been
shown to make none — the class may be exactly the one it was written for — so
it is not reported, and dropping it is never advised. The first version counted
only matches and removals, read a doubted match as "removed nothing", and told
the author to delete the clause.

**Template observation falls toward existence.** A class whose non-pattern
criterion or whose substituted `exclude:` clause cannot be decided still
contributes its tuple, so the concrete layer is created. Refusing the tuple
would delete the layer, runtime would never evaluate any class against it, and
the doubt would come out as a plain non-match with nothing left to report. An
instance created through an unanswerable `exclude:` is reached at runtime,
since that clause no longer withdraws the match. One created through an
unanswerable *positive* criterion (`match: all` with `extends:`) may still be
named by `architecture.unreachable-layer` — noisy rather than silent is the
failure direction chosen there.

**A template declaring a non-pattern criterion under `match: any` is refused at
configuration time.** Only `patterns` carry capture variables, so `suffix`,
`attributes`, `implements` and `extends` are copied into every expanded layer
verbatim. Under `match: all` that is harmless: the criterion narrows each
instance inside the scope its own substituted pattern already fixes. Under
`match: any` it is OR-ed with that pattern, so every instance carries the same
global net — one clause reading "or anything named `*Repository`" makes every
sibling instance claim every repository in the codebase, and the first instance
in expansion order (binding-value alphabetical, not anything the author wrote)
wins it.

The alternative was to scope such a criterion to the instance's own pattern.
It was rejected by arithmetic, not by taste: `gate ∧ (gate ∨ suffix) = gate`,
so the criterion becomes inert. That trades a wrong answer for one that does
nothing while still looking like it does something. `match: all` expresses the
narrowing an author almost certainly meant, and a static layer expresses the
global net if that is really what was wanted.

## Consequences

- `architecture.coverage-gap` distinguishes two gaps that used to look
  identical: a symbol in nobody's layer because the run answered every
  criterion — a hole the author closes by writing a layer — and a symbol in
  nobody's layer because its inheritance chain left the analysed set. A layer
  declared after the unanswered one closes the second as well, but by guessing,
  and the recommendation says so; what decides it is analysing the declaration
  where the chain stops or, for a vendor type that is itself the undecided
  symbol, a `patterns` layer for its namespace declared first. Each of the two
  is advised only when a symbol of its kind is among the undecided, because
  `debug:layer-assignment`, which locates the first, refuses the second. A third sentence
  counts assignments in doubt when the gap is reported; they never raise it.
  `debug:layer-assignment` names where the chain stops (`chainStopsAt` in
  JSON).
- `architecture.doubted-assignment` is a new, never-gating channel of
  `architecture.layer-violation`, published while `coverage-gap` is `warn` or
  `error`.
- Membership is now a function of what the run analysed. Widening `paths:`
  can turn an undecidable layer into a decided one, in either direction. A
  criterion that names a class's direct parent or interface keeps matching, and
  a chain that ends on PHP's own classes stays decided. What moves is a *miss*
  for a class whose chain passes through an unread non-PHP declaration: it was a
  confident "no" and is now undecidable; as a positive criterion that can leave
  the class in no layer, and as an `exclude:` it leaves the class in its layer
  with the doubt published. Measured on 2026-09-23 with one
  `extends:` and one `implements:` criterion naming no real type, counting
  analysed classes whose answer is undecidable (before the per-kind split and
  the PHP-class ground → after): this repository's `src/` 74 → 45 of 1002 for
  `extends` (74 → 58 for `implements`); doctrine/orm 117 → 48 of 453 (117 → 57);
  guzzlehttp/guzzle 15 → 1 of 39 (15 → 11); league/commonmark 45 → 2 of 297
  (45 → 27); laravel/framework 249 → 159 of 1451 (249 → 178). The remainder is
  dominated by vendor base classes — `Symfony\Component\Console\Command\Command`
  alone accounts for 120 of Laravel's 159.
- Four configurations that were accepted now refuse: a template with a
  non-pattern criterion under `match: any`, `relations:` written without a
  value, `coverage-gap: warn|error` declared with no layers, and an
  `attributes`, `implements` or `extends` entry that is nothing but `\` — it
  used to pass the namespace-separator check and name no class. Refusing at
  load time is the project's standing preference over a silent no-op
  ([ADR 0061](0061-configuration-miss-and-refusal-semantics.md)).
- `MembershipResult` grows three variants beside the plain match and
  non-match: `excluded()` and `undecided()`, which are non-members, and
  `doubtedMatch()`, a member whose `exclude:` went unanswered. Every consumer
  reading the `matched` flag sees membership exactly as before; the
  distinctions exist only so `architecture.unmatched-exclude` (did the clause
  remove something, remove nothing, or fail to answer),
  `architecture.coverage-gap`, `architecture.doubted-assignment` and
  `debug:layer-assignment` can answer their own questions.
- Template observation is deliberately narrower than runtime matching in one
  shape: a non-capturing pattern is an AND-filter during observation, while on
  the expanded layer both pattern spellings sit in the single `patterns` kind
  and are OR-ed. That asymmetry is recorded rather than removed, because
  closing it would widen which concrete layers exist.
- `Qualimetrix\Analysis\Policy\Architecture\Layer\AnalysedDeclarations` carries
  the "did this run read this FQN's own declaration?" question. Its `unknown()`
  state — a caller that never supplied the set — answers yes to everything, so
  a registry built without it decides exactly what it decided before. Folding
  that state into an empty set would make every criterion undecidable for every
  such caller.
- Two forms of the `Stringable` PHP adds unwritten still answer "no": a class
  whose `__toString()` comes from a trait, and `extends: ['\Stringable']` for
  an interface declaring `__toString()`. The trait's body is another
  declaration, and a used trait could be made a cut only for every `implements:`
  criterion at once, which would leave every class using a vendor trait
  undecided. Both are named on the website rather than left to be found.
- The attributes of PHP's own classes are the class-level ones. Which members
  carry `#[\Deprecated]` or `#[\NoDiscard]` differs between PHP 8.4 and 8.5, so
  a PHP class met as the far end of an edge (`extends \PDO`) answers "no" to an
  `attributes:` criterion naming an attribute only its members carry.
