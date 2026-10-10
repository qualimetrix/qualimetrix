# 0103. Layer Policy Declaration Evidence and Selection

**Date:** 2026-10-07
**Status:** Accepted

## Context

Layer matching previously conflated attributes on a declaration with attributes
on its members, syntax shape with dependency position, and declaration validity
with the producer that reports forbidden edges. External ancestry could remain
unknown despite an available installation. Case-sensitive criteria could also
receive inconsistent projections of one PHP class identity. Consumer baselines
and inspection output depended on these choices.

## Decision

### Declaration facts and source-owned edges

DependencyModel carries named `ClassLikeDeclaration` facts separately from edges,
including exact `DeclarationPath`, declaration kind, direct `__toString` and
trait alias facts. Collection, worker reconstruction, folding and graph building
must carry that stream even when a declaration has no edges. A synthetic self-edge,
an implicit Stringable edge or an unrelated metric cannot substitute for it.
Architecture reads these prepared facts without a second AST traversal.

Dependency kinds describe positions: parameter/return `type_hint`, property and
promoted-property `property_type`, and typed-constant `constant_type`.
Union/intersection/DNF are syntax shapes. Graph JSON always publishes an object
mapping positions to their observed shapes, such as
`{"property_type":["nullable"],"type_hint":["union"]}`; no shape facts gives `{}`.
`type_reference` expands to the current type positions. Retired `union_type` and
`intersection_type` relation entries cannot become current dependencies.

A forbidden edge belongs to its exact source declaration. Owned target
declarations remain ordered count units, including duplicate targets. Occurrence
identity uses exact source, logical target and dependency kind, independently of
which physical target files were selected. An unmeasured target contributes one
logical unit; a narrowed population retains the accepted identity as
not-compared and cannot tighten its count. Source
annotations control outgoing projections. A target annotation does not waive an
incoming violation, and its unused verdict still requires judged coverage.

### Criteria, known facts and spelling

Membership and `exclude` have six criterion kinds: `patterns`, `attributes`,
`member_attributes`, `implements`, `extends` and `suffix`. Class attributes and
own-member attributes are distinct facts. Anonymous or nested declarations do
not lend attributes to their enclosing named class. Capture patterns remain an
Architecture grammar distinct from public `exact | subtree | regex` selectors;
Architecture membership rejects public selector forms with an accepted pattern
equivalent. Public selectors require explicit kind syntax; YAML schema and CLI
shape refusals do not translate a bare Architecture pattern. A template with
`match: any` must not contain a captureless pattern; `match: all` may combine it
with a capturing criterion.

External declaration facts are read as data, never executed. Placement checks
exact case on each path segment; the declaration must name the requested type.
Prepared ancestry follows classes, interfaces and traits, with an unresolved cut
at 256 on each branch. Missing or conflicting facts remain doubt. Direct,
inherited or trait-provided `__toString` establishes Stringable for a consuming
class; a trait is not itself Stringable. External ancestry does not establish a
class's own attributes. The shared AST name resolver permits an explicit error
handler for metadata reads; collection retains its default collecting handler.

Logical PHP class and namespace identities use ASCII-only folding and the
byte-smallest observed canonical spelling. Exact declaration paths and ordinals
remain distinct. External installed spelling wins only when an observed variant
places exactly and denotes that folded identity; otherwise the observed fallback
wins. Warning transport is limited to mixed groups with two or more observed
spellings, not a singleton placement-status warning. Composer placement and
listing snapshots share an install anchor; stateless external reads can parse
again. Architecture's prepared-fact memo is not a shared parser cache.

Inspection resolves names through parser-derived analysed declarations and graph
ends. Only empty normalized input refuses before collection. Unknown non-empty
names refuse after lookup, including when policy is disabled; installed-only
names do not enlarge this population. No second identifier regexp or parser is
introduced. Typed `LayerAssignment` and mandatory shadow verdicts supply both text
and JSON. Final Finding enablement supplies `policyDisabled`; unavailable
configuration fails rather than pretending that the policy is enabled. Disabled
output omits reported/exemption claims and diagnostic guidance.

### Independent publication and absence judgement

`architecture.layer-declaration` independently owns five configuration-error
channels and four ordinary diagnostic channels. Its only option is `enabled`.
The five existing validators are `FilterExempt`; unrelated `only` filters and
individual diagnostic disables cannot hide them. The producer's own disable or
an Architecture group disable stops it. Ordinary `unmatched-exclude`,
`doubted-assignment`, `layer-overlap` and `unmatched-type` follow exact selectable
publication. Preparation runs if any of layer-declaration, layer-violation or
unassigned-class needs it, then shares one evidence walk. An active unassigned
warn/error mode requires non-empty layers, including an authored empty list.

Established shadow pairs have three named exemptions: narrower-declared-first,
receives-what-it-leaves and non-pattern-precedence. A partially losing non-pattern
layer reports ordinary overlap; full loss reports unreachable-layer instead.
Each unmet positive or exclude type is judged separately, so a known neighbour
cannot hide a typo. Its authored identity retains source kind/locator/importer
chain, key path, layer index, line and exact name. Template copies share one
authored occurrence, including a template that expands to no instances.

Unmatched-type absence needs complete declaration judgement and a read project
install. Withheld judgement emits no absence finding. Architecture's pure
`UnmatchedTypeWarningInterface` accepts `ProjectScopeJudgement` and returns a
nullable warning; Run asks after execution only when exact channel publication
permits it. The existing preparation object promises the query too. A rule-side
logger, outbox or widened Finding execution result would move publication to the
wrong owner. Full directive checks retain initial execution and two reproducibility
executions; narrow counterfactual passes add no declaration execution.

### Source projection scope

Layer violations are file-scoped. Global path exclusions compare the physical
source dependency site; namespace exclusions compare the source declaration.
Git publication compares the source file in both strict and non-strict modes.
Target identity and cardinality remain evidence, so an excluded or changed target
alone does not control publication of the outgoing source finding. Cycles and
declaration diagnostics retain their declared project scope.

Projection scope does not make layer-policy evidence local. The channel still
reads run evidence, so baseline comparability retains whole-run coverage.
Global exclusions change the measured set before baseline capture; Git narrows
only publication after that set has been taken.

### Consumer migration

Upgrade baselines using the existing add-only `--accept-new` and exact cleanup
selectors. Capture of the old consumer identities must use the actual preceding
accepted binary; they cannot be synthesized by the new one. The accepted
integration snapshot supplied this evidence without changing another branch.

Ordinary cleanup may report only inert retired relations: exclusions can prevent
proving absence of valid old target-subject entries. A second read-only cleanup
with layer-violation disabled publishes their selectors as unmeasured/malformed.
Then accept new source identities for that channel and remove only the selected
old entries. Unrelated tightened/suppress entries and non-empty recorded
scope/exclusions must survive. Regeneration or force is unnecessary and would
lose that acceptance. The consumer steps live in the baseline documentation.

## Consequences

These changes intentionally break dependency-position, finding identity,
selection and debug consumers. The module READMEs define the current typed
contracts, while website pages give configuration and migration steps.
[ADR 0030](0030-one-rule-per-type-coverage-dimension.md) is partially superseded
only for the five declaration diagnostics' former layer-violation gate/options.
[ADR 0077](0077-open-universe-selectors-are-explicit.md) is partially superseded
only for Architecture's mutual DSL refusal and accepted-equivalent guidance;
its explicit public selector grammar remains in force.
