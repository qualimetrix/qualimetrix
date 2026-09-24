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
because the edge never reached the policy. The builder drops every edge to PHP's
own classes except `extends`, so that the `implements`, `trait_use` and
attribute edges to them count toward no coupling metric, and membership was
reading the same filtered view. What a class declares is not a coupling question, so the
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
and the `exclude:` shape was first built that way. That reading was rejected
for both, for one reason: withdrawing the match leaves the class in no layer,
no allow-list judges its edges, and real violations stop being reported. It
trades a wrong answer for a missing one, which is the worse of the two, and
under the default `coverage-gap: ignore` nothing said the answer had gone
missing.

The alternative for `exclude:` was to keep withdrawing the membership and make
the loss visible regardless of the coverage mode. It was rejected because it
repairs the report and not the verdict: the class would still leave the layer,
its edges would still go unjudged, and every configuration with such a clause
would gain a diagnostic *in place of* the violations it lost. The doubt is
still published in every mode (below), but beside the violations, not instead
of them.

**Which unanswered layer is a doubt is decided once, in the walk.** A layer
declared after a match the run established is not a doubt: first match wins,
so it could not have owned the class whatever it answered. A match whose own
`exclude:` went unanswered is not established — the clause may remove the
class — so the layers after it, up to the first established match, still bear
on the assignment. `LayerRegistry::undecidedLayers()` applies that rule while
it walks the layers in declaration order, and every reader takes the list as
it comes:
`debug:layer-assignment`, whose "it can change" is then true of every layer it
names, and the evidence walk that counts doubted assignments. The first version
left the list unordered and let one reader filter it; the other printed
"it can change" for a layer that could not change anything, and the two
disagreed about the same class. The same walk has three more exits, each for
a reader that asks a different question. `unansweredExcludeLayers()` names
every matching layer whose `exclude:` went unanswered, winning or not, because
the reader that asks — the inert-clause check below — asks about the clause,
not about the assignment. `contenders()` names the layers that could own the
class once the unanswered ones are answered, for the verdicts below that
conclude something from who won. `establishedMatches()` lists the matches
whose `exclude:` was answered, for the verdicts that conclude something from
who lost: the first of them is where `contenders()` stops, and every later one
loses the class whatever the unanswered clauses in front of it answer.

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
gates: one finding per run, with the count split into analysed classes and
symbols outside the analysed paths, each layer that could not answer named
with how many symbols it left in doubt, examples of each kind, and a
recommendation for each kind present. `architecture.coverage-gap` still
names the count in its text when it fires for its own reasons, and points
there. The same three runs now exit 0 with the doubt count unchanged. Declaring
the vendor `patterns` layers first removes most of the outside-the-paths share
(the doubt drops to 37, 26 and 47 on the three projects; composer keeps 18
outside symbols because its own packages share the `Composer\**` prefix);
declaring the `extends:` layer last, with its
population carved out of the earlier layers by a decidable `exclude:`, removes
the doubt entirely.

The channel is published whatever the coverage mode. It was first published
only while `coverage-gap` was `warn` or `error`, as part of the coverage
accounting that mode turns on — but it says whether membership is right, not
how much of the code a layer covers, and `ignore` is the default. Under it, an
`exclude:` the run could not answer left no trace in `check` at all: the inert
clause it used to be misreported as was gone, and the doubt that replaced it
was gated off. A channel that never gates costs a project one line, and a
project that does not want it lists it under `disabled_rules`.

For the same reason it also counts the symbols that are in no layer only
because a layer could not answer about them. Those are part of the coverage
gap, and `architecture.coverage-gap` counts them when it is on; but the layer
that could not answer is otherwise named nowhere once
`architecture.unreachable-layer` stops calling it empty (below).

The alternative was to keep the doubt inside `architecture.coverage-gap` and
lower the finding to `info` when the doubt is all there is. It was rejected
because a configuration-error channel refuses to report below `warning` —
a configuration error printed as `info` would display a weight it does not
have — and because a severity that depends on which sentences the message
carries makes one channel two. `debug:layer-assignment` alone was not enough
either: it refuses a class the run did not analyse, and most doubts are exactly
such classes.

**A layer the run could not answer is not unreachable, and a match it could
not establish shadows nothing.** `architecture.unreachable-layer` said "the
declared criteria match no class" about a layer whose criteria the run could
not answer for any class, and `architecture.potential-shadow` built a shadow
from a winner whose `exclude:` the run could not evaluate — together they told
the author of a carve-out that did not answer to reorder or delete the layer it
was written for. Both are configuration errors that fail the run, so neither
may rest on a conclusion the run did not reach: a layer that could still own an
analysed class whose assignment is in doubt (one of its `contenders()`) is not
unreachable — within the limits the next section sets — and a shadow is drawn
only between matches the run established (`establishedMatches()`). A match
whose `exclude:` went unanswered neither shadows nor is shadowed. The first
established match shadows every later established one even when an unanswered
`exclude:` in front of it holds the class: in the Doctrine carve-out, `legacy`
declared after `repos` loses the class to `app` or to `repos` whichever way the
clause answers, so `repos` → `legacy` is reported, while `app` → `repos`
depends on the answer and is not. The first version dropped every shadow of a
class whose assigned layer stood on such a clause, and with it that real
misordering. A layer the run could not answer about a symbol that an
established match declared earlier already owns is still unreachable if it owns
nothing else: it could not have won that symbol, and "shadowed" is then the
right reading of the finding.

The alternative for `unreachable-layer` was to keep reporting such a layer and
add a sentence naming the symbols in doubt, as `coverage-gap` does. It was
rejected because the finding fails the run: a statement that the run cannot
decide is not an error in the configuration, and lowering the severity when
that sentence is present would make one channel two — the reason a doubt was
kept out of `coverage-gap`. The layer is still reported, with such a sentence,
where the run cannot tell the contest from a mistake in the declaration; the
next section says where that is, and names the cost.

**Which contests keep a layer out of `unreachable-layer`.** Doubt is not
evidence that a criterion is right: a criterion naming a type that does not
exist goes unanswered about every class whose chain the run cannot follow, so
counting every contest turned the typo the channel exists to catch into an
`info` line. Two versions failed that way. The first counted every symbol in
doubt, and one type-hint edge into vendor code kept
`implements: ['App\Contracts\Handlr']` from ever being reported. The second
counted analysed classes only, and one analysed class extending an unread vendor
parent — a controller, a repository, a command: most framework projects have
one — did the same for every `implements:` and `extends:` layer declared before
the layer that owns it, template instances under `match: all` included.
`LayerEvidence::reachedCounts()` now decides it in one place, by what the
contest rests on:

- An analysed class the layer's own criteria matched counts. The criteria are
  right about it; only an unanswered `exclude:` in front decides the owner.
- An analysed class the layer could not answer about counts only while some
  type its `attributes:`, `implements:` or `extends:` criteria name is one the
  run met: declared in the analysed paths, declared by PHP, at either end of
  an edge in the declaration or the coupling view, or declared by the analysed
  project's composer install (see the amendment below). The unread parent may
  reach a type the run met; a type the run never met is indistinguishable from
  a typo.
- A symbol outside the analysed paths never counts. The run never reads it, so
  every such criterion goes unanswered about it in every run.

A contest that does not count is said in the finding, in the words true of it.
A layer that could not answer is told how many symbols it could not answer
about and, when no named type is one the run met, which types those are and
what their absence rests on — with the install asked, a mistyped name or a
package that is not installed; with no install to ask, a mistyped name or a
type reachable only through code the run did not analyse. A layer whose criteria matched
symbols outside the paths is told that an earlier layer holds them through an
`exclude:` no run can answer, rather than that its criteria match nothing.

The costs are named. A criterion naming a type only a vendor chain reaches —
`implements: ['Doctrine\Persistence\ObjectRepository']` over repositories
that extend `ServiceEntityRepository`, with no analysed code naming the
interface — was reported as unreachable again by the first version of this
rule; the amendment below removes that cost where the package is installed, and
it remains only where it is not. A layer written for vendor
types alone — a narrower vendor `patterns` layer behind a vendor carve-out whose
`exclude:` cannot be answered, say — is reported as unreachable while it may
own a vendor type. `architecture.doubted-assignment` names both beside the
error.

The rejected alternative was to keep counting every analysed contest and record
the gap as a limitation. It leaves the channel unable to catch a mistyped
`implements:` or `extends:` on any project where an analysed class extends
vendor code, which is the common case rather than the edge one.

Every layer a contest keeps out of `unreachable-layer` is named in
`architecture.doubted-assignment`: a layer that could not answer among the
layers that could not answer, and a layer that would own a symbol if an
unanswered `exclude:` in front of it removed it in a list of its own. The
first version named only the first kind, and a layer kept out by someone
else's clause was named by nothing in `check`. `debug:layer-assignment` names
both for an analysed class (`contenders` in JSON), and its shadow hint and
`shadowed` follow `establishedMatches()`, so it never points at a
`potential-shadow` that `check` does not report.

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
unanswerable *positive* criterion (`match: all` with `extends:`) is not
reached. Where no match the run established is declared before it, it could
still own the class. While the type its criterion names is one the run met,
`architecture.unreachable-layer` does not call it empty and
`architecture.doubted-assignment` names it; a type the run never met is
reported, as for any layer. Where an established match is declared before it,
it is shadowed there whatever it would have answered.

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
  `debug:layer-assignment`, which locates the first, refuses the second. When
  the gap is reported, its message also counts the assignments in doubt in a
  sentence of its own; they never raise it.
  `debug:layer-assignment` names where the chain stops (`chainStopsAt` in
  JSON).
- `architecture.doubted-assignment` is a new, never-gating channel of
  `architecture.layer-violation`, published in every coverage mode. It names
  each layer that could not answer, with how many assignments it left in doubt
  and how many symbols in no layer, and in a list of its own each layer that
  would own a symbol if an unanswered `exclude:` in front of it removed it.
- `architecture.unreachable-layer` and `architecture.potential-shadow` no longer
  fire on an answer the run did not reach. A layer is not unreachable while its
  own criteria matched an analysed class that an unanswered `exclude:` in front
  of it holds, nor while it could not answer about an analysed class and a type
  its criteria name is one the run met. A match whose `exclude:` went
  unanswered neither shadows nor is shadowed. A symbol outside the analysed
  paths keeps no layer from being unreachable. The finding says what it left
  out: the unanswered symbols and the named types the run never met, or the
  outside symbols an earlier unanswered `exclude:` holds. A criterion naming a
  type only a vendor chain reaches keeps its layer while the analysed install
  declares the type, and is reported where it does not. Neither channel judges
  a run that covers only part of the project (amendment below).
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
- `MembershipResult` grows two variants beside the plain match, the plain
  non-match and the existing `excluded()`: `undecided()`, a non-member, and
  `doubtedMatch()`, a member whose `exclude:` went unanswered. Every consumer
  reading the `matched` flag sees membership exactly as before; the
  distinctions exist only so `architecture.unmatched-exclude` (did the clause
  remove something, remove nothing, or fail to answer),
  `architecture.coverage-gap`, `architecture.doubted-assignment`,
  `architecture.unreachable-layer` and `architecture.potential-shadow`
  (through `contenders()` and `establishedMatches()`) and
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

## Amendment (2026-09-24): the installed type, the partial run, the carve-out

Three conclusions this decision drew turned out to rest on facts the run
could have had, or did not have at all.

**A type the analysed install declares is a type the run met.** The known-type
test above asked only what the run analysed, PHP and the edges; a vendor
interface that only a vendor chain reaches is none of those, so a correct
criterion over it read as a typo and failed the run. `KnownTypes` now asks one
more place, last because it reads files: the analysed project's composer
install, through `ExternalParentSourceInterface` — the port DIT's ancestor walk
reads it by ([ADR 0074](0074-dit-reads-the-ancestors-it-measures.md)), which
places a class through `installed.json`, the project's own `autoload` and the
generated classmap and parses its file without loading it. A name counts only
when the mapped file declares exactly that name, so a mistyped one, or one
differing in case on a case-insensitive filesystem, stays unmet. The finding
that names unmet types says whether an install was asked, because "mistyped or
not installed" and "mistyped or reachable only through unanalysed code" are
different advice.

The install answers existence, not membership. The rejected alternative was to
follow the vendor chain and decide the criterion: the port reads a class's
parent only, not the interfaces it implements, so `implements:` over a vendor
chain would stay undecidable anyway, and a second reader of vendor declarations
inside this capability would duplicate the one Design already owns. Widening
the port is where to start if deciding it is ever wanted.

**Two verdicts need the whole project.** `architecture.unreachable-layer` and
`architecture.empty-template` say that no class matches a declaration. On a run
over part of the project — `qmx check src/Web` — every layer and template whose
code lies outside the slice matched nothing there, and both channels failed the
run; with the known-type test, a type known only from an edge outside the slice
turned into an apparent typo as well. The validator now withholds both unless
the run's paths cover everything the project's manifest declares under
`autoload` — `AnalysisContext::$coversProjectScope`, the predicate
`architecture.unmatched-exclude` and the framework-namespace channel already
read ([ADR 0061](0061-configuration-miss-and-refusal-semantics.md)). The other
three declaration verdicts draw only on what the run read and stay. A project
with no readable production autoload in `composer.json` is judged by both, its
analysed paths taken as the whole project, and every report names the run's
project scope — `covered`, `narrowed` with the channels it did not judge, or
`unknown` — in its own format
([ADR 0084](0084-a-project-scope-has-three-states-and-the-report-names-it.md)).
Measured: `--report=git:staged` does not narrow the analysed set, so a
pre-commit run is judged as before.

**A carve-out's recipient is reachable.** Layer loading refused two layers
declaring the same pattern as "the second occurrence is unreachable", including
when the first carries an `exclude:` — the simplest carve-out this decision
reasons about, which therefore never loaded. Only a layer that takes every class
its pattern names now makes a later occurrence unreachable: one with an
`exclude:` hands what it removes to the next layer that matches it. A later
layer's own `exclude:` makes no such room, and a third occurrence is refused
behind the layer that received the carved-out classes. Whether the recipient
reaches anything is left to `architecture.unreachable-layer` at run time. The
refusal that remains names the carve-out as the way to reach the second layer.
