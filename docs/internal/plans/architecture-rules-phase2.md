# Architecture Rules — Phase 2 (Conceptual Plan / Stage 1)

**Status:** Stage 1 — conceptual design, two rounds of triple review applied; ready for ADR 0007 drafting
**Author:** 2026-05-13
**Last revised:** 2026-05-13 (round 2 triple-review: `LayerExpansionStage` runs after Collection, D7 precision, `LayerSelector` contract split, cartesian ceiling configurable, `DependencyType` used directly, `runtime_check` alias, selector grammar precise spec, overlapping allow union, wildcard self-allow silenceable)
**Target:** Five flexibility extensions to `architecture.layer-violation` rule that close the long tail of "I'd use
this but my project is X"
**Tier:** 2 (extends user-facing schema; new feature surface)
**Depends on:** [architecture-rules-followup.md](architecture-rules-followup.md) (declaration-order pivot must land
first; all Phase 2 design assumes ordered matching as the foundation)

---

## Scope of this document

**This is Stage 1 — design only.** It captures semantics, contracts at signature level, edge cases, and locked
decisions. It does NOT prescribe step-by-step implementation, file paths, or per-step DoD. After Stage 1 clears
triple review (two rounds completed), locked decisions go into **ADR 0007**, then the implementation plan (Stage 2)
is appended to this document and gets its own triple review before any code is written.

**Reviewer focus for Stage 1.** Reviewers should evaluate:

- Soundness of cross-cutting design decisions D1–D7
- Coverage of edge cases per direction
- Inter-direction composition: do the 5 directions interact cleanly without surprise?
- What's MISSING: directions or design questions not yet addressed
- What's RECONSIDERABLE: explicitly dropped items that deserve a second look

**Not in scope for Stage 1 review:** implementation steps, file paths, code style, performance tuning specifics.
Those belong to Stage 2.

---

## Context & motivation

The architecture.layer-violation rule shipped in Phase 1 (see [ADR 0005](../../adr/0005-architecture-rules.md) and
[architecture-rules.md](architecture-rules.md)), with a pivot in flight via
[architecture-rules-followup.md](architecture-rules-followup.md) replacing specificity-based resolution with
declaration-order matching. Phase 1 covers the textbook DDD/Clean Architecture case: classes are identified by
namespace, layers depend on each other through an explicit allow-list.

Triage discussion on 2026-05-13 surfaced five flexibility extensions that real codebases need but Phase 1 doesn't
support:

1. Classes whose architectural role doesn't match their namespace (a `Repository` in `App\Service\` should still be
   a repository)
2. DDD bounded contexts where N modules share the same internal layer structure and cross-module dependencies must
   be forbidden without listing all N pairs
3. Subtree exclusion within a layer match
4. Allow-list edges restricted by relation kind (extends vs implements vs new vs static_call vs ...)
5. Unclassified classes still governed by policy (not just a coverage diagnostic)

Without these extensions, the "one tool replaces deptrac" promise breaks down for non-textbook projects. Teams either
contort their YAML or fall back to deptrac for the long-tail cases.

---

## Locked design decisions (cross-cutting)

These are pre-decided before Stage 2 implementation planning. Reviewers may challenge them; landed challenges shift the
decisions; otherwise they constrain Stage 2.

### D1. Declaration-order matching is the foundation

All Phase 2 directions assume the declaration-order pivot from `architecture-rules-followup.md`. No direction
re-introduces specificity computation. When a class matches multiple layer entries, the layer declared earlier wins.

### D2. `match: any | all` controls multi-criterion membership combination (with carve-out for templates — see D7)

Within a single non-template layer entry, criteria of different kinds (`patterns`, `suffix`, `attributes`,
`implements`, `extends`) combine via `match: any` (default) or `match: all` (strict). Within a single kind, lists are
always OR'd (`attributes: [A, B]` means "has A or B"). The `match` flag governs cross-kind combination only.

**Why `any` default:** the dominant use case is migration of existing codebases where conventions are inconsistent.
`any` captures the legacy class that matches by suffix even though its namespace doesn't match. `all` is opt-in for
strict-convention projects.

### D3. Templates expand by observed binding tuples AFTER Collection (NOT cartesian product, NOT at config-load)

A template layer (e.g. `name: 'domain-{module}'`) is expanded by walking the discovered class set + collection-derived
metadata (attributes, interfaces, parent classes), matching classes against the template's capture-producing
criteria, and collecting the **distinct observed binding tuples**. One concrete layer instance is created per
observed tuple.

This avoids creating layers for combinations that don't exist in the codebase. If a project has modules `Order` and
`Inventory` with no `App\Sales\` namespace, the template `App\{vendor}\{module}\**` produces only the tuples that
actually appear — never the cartesian product of all `{vendor}` × all `{module}` values.

**Pipeline phase.** `LayerExpansionStage` runs **after the Collection phase finishes and before RuleExecution**.
Capture criteria can reference `attributes`, `implements`, `extends`, which require collection-derived AST metadata
that does not exist at configuration load. The earlier-draft phrasing "at configuration load" was incorrect and is
revised here. Concretely: configuration loading produces a list that may include both static `LayerDefinition`s and
unexpanded `TemplateLayerDefinition`s; `LayerExpansionStage` consumes that list plus the collected `ClassSet` and
emits a list of concrete `LayerDefinition`s only. Allow-list validation that references concrete layer names runs
AFTER expansion.

### D4. Allow-list selectors support exact match, glob, and (mandatory) capture-binding — with a precise grammar

Allow-list keys and values are `LayerSelector`s. The selector kind is determined by the string at config-load time:

- **Captured glob** if the string contains `{var}` placeholders (e.g. `'domain-{m}'`)
- **Glob** if the string contains glob metacharacters (`*`, `?`, `[`) but no `{var}` (e.g. `'domain-*'`)
- **Exact** otherwise (e.g. `'shared-kernel'`)

Literal layer names cannot contain `*`, `?`, `[`, `{`, `}` characters (validated at template-name and static-name
config load — these are reserved for selector syntax).

The contract is split into source-side and target-side operations (round-2 finding: a single `matches()` method
cannot extract a binding for source-side captured selectors):

- `LayerSelector::matchSource(string $layerName): ?CaptureBinding` — for use on the LHS of an allow entry; returns
  a `CaptureBinding` (possibly empty for glob/exact) on match, null otherwise
- `LayerSelector::matchesTarget(string $layerName, CaptureBinding $sourceBinding): bool` — for use on the RHS;
  captured selectors require the binding established by the source-side match

Bare strings keep exact-match semantics — fully back-compat with Phase 1.

### D5. Capture-variable binding is MANDATORY in Phase 2 MVP

Capture binding (`'app-{m}': ['domain-{m}']` for same-instance-only constraints) is **not optional**. Without it,
direction 2's `'app-*': ['domain-*']` permissively allows cross-module app→domain edges, defeating the bounded-context
isolation that motivates the whole direction. Capture binding ships with the base template feature.

**Note.** D5 closes the main DDD leak (same-instance allows), but does NOT make every wildcard configuration safe.
A user who writes `'domain-*': ['domain-*']` (wildcard on both sides) still permits all-to-all cross-module
dependencies. Such configurations produce a `warning`-severity diagnostic at config load (see direction 2 edge
cases) — silencable via an explicit `allow_cross_instance: true` flag on the entry, signalling intent.

### D6. Backward compatibility for Phase 1 users is required

Phase 1 YAML configs (post-followup-pivot) without templates / suffix / attributes / capture-binding must continue to
work unchanged. Phase 2 strictly extends the schema; it does not modify or rename existing keys. The followup-plan's
breaking pivot (specificity → ordered) is the ONLY breaking change in this trajectory.

### D7. Template layer membership: capture-producing criteria mandatory; non-capturing criteria ALWAYS filter

A criterion is **capture-producing** if it references one or more capture variables `{var}` declared in the layer's
name template. For non-template layers, every criterion is non-capturing and D2's `match: any | all` applies as
described.

For template layers:

- The layer MUST declare at least one capture-producing criterion (validated at config load)
- **Capture-producing criteria** combine according to `match: any | all` — `any` means "at least one
  capture-producing criterion matches and establishes bindings"; `all` means "every capture-producing criterion
  matches with consistent bindings"
- **Non-capturing criteria** ALWAYS act as AND-filters on the captured candidates, regardless of `match` mode. They
  can only narrow, never widen. If a class matches a capture-producing criterion (producing bindings) but fails a
  non-capturing filter, the class is `NoMatch` for THIS template instance and moves on to the next layer in
  declaration order.

This carve-out resolves the round-2-flagged ambiguity: "non-capturing optionally narrow" was contradictory with
`match: any`. The clarification: `match` controls only how capture-producing criteria combine; non-capturing criteria
are a separate constraint layer, always-AND.

---

## Direction 1 — Class-membership beyond namespace

### Problem

A class's architectural role often does not match its namespace:

- `App\Service\UserRepository` is semantically a repository, despite the `Service` namespace
- A class with `#[ORM\Entity]` is an entity regardless of namespace
- A class implementing `Doctrine\Persistence\ObjectRepository` is a repository

Phase 1 forces users to refactor their code to align namespaces with architecture before the rule produces useful
results. Phase 2 lets the rule meet the code where it is.

### Conceptual semantics

A layer entry supports up to five membership criteria:

| Key          | Semantics                                                                        |
| ------------ | -------------------------------------------------------------------------------- |
| `patterns`   | List of glob patterns matching FQN (existing in Phase 1)                         |
| `suffix`     | List of class-name suffixes (`'Repository'`, `'Controller'`); short-name only    |
| `attributes` | List of attribute class FQNs; class has #[Attr] (use-statement-aware resolution) |
| `implements` | List of interface FQNs; class implements transitively                            |
| `extends`    | List of parent-class FQNs; class extends transitively                            |

**`implements` and `extends` are split** (decision on Q1): cleaner taxonomy, each maps to a distinct supertype
relation, and it surfaces nicely in `match: all` ("must extend AbstractBase AND implement Loggable").

A class is a member of the layer iff its membership matches per `match: any | all` (with template carve-out per D7):

- `match: any` (default, non-template): class is a member if ANY criterion has at least one matching entry
- `match: all` (non-template): class is a member only if ALL declared criteria have at least one matching entry

Within a single kind (`attributes: [A, B]`), entries are always OR'd. A missing criterion is "trivially satisfied"
under `match: all` (no need to write empty `patterns: []` to opt out).

### YAML examples

Migration-friendly default (`match: any`):

```yaml
- name: repository
  patterns: ['App\Repository\**']
  suffix: 'Repository'
  implements: 'Doctrine\Persistence\ObjectRepository'
# Member if class is in App\Repository, OR named *Repository, OR implements ObjectRepository.
```

Strict convention (`match: all`):

```yaml
- name: command-handler
  match: all
  attributes: ['App\Messenger\AsCommandHandler']
  suffix: 'Handler'
  patterns: ['App\Handler\**']
# Member only if class has the attribute AND ends in Handler AND lives in App\Handler.
```

Combined extends + implements:

```yaml
- name: domain-aggregate
  match: all
  extends: 'App\Domain\AggregateRoot'
  implements: 'App\Domain\HasIdentity'
```

### Edge cases

- **Empty criteria.** A layer with no criteria is a configuration error (caught at config load).
- **Attribute resolution.** Attribute matching uses post-resolution FQN (php-parser's `NameResolver` already runs in
  collection phase). Short names like `'Entity'` are rejected at config load — require FQN.
- **Interface / parent-class transitive resolution.** `implements: 'X'` matches if the class implements `X` directly or
  transitively via interface chains. `extends: 'Y'` matches if `Y` appears anywhere in the parent-class chain. Both
  use existing collector data — no new AST traversal phase.
- **Diagnostic specificity.** When OR semantics catch a class, the violation message records WHICH criterion matched
  ("class X is in layer `repository` because suffix `Repository` matched"). Multiple matched criteria are listed.
  Requires extending the membership-result VO.
- **`match: all` with one criterion.** Trivially equivalent to `match: any` with one criterion.

### Open questions for Stage 1 review

- **Q2 (closed).** No `not_*` negative criteria — covered by direction 3's `exclude:` or by declaration order.
- **Q3 (closed).** Unset criteria trivially satisfied under `match: all`.

### Contracts (signature level)

`LayerDefinition` (post-Phase 2 direction 1):

- `__construct(string $name, MembershipSpec $membership)`
- `matches(ClassContext $context): MembershipResult`
- `name(): string`
- `membership(): MembershipSpec`

`MembershipSpec`:

- `__construct(list<string> $patterns, list<string> $suffix, list<string> $attributes, list<string> $implements, list<string> $extends, MatchMode $mode, ?ExcludeSpec $exclude)`
- `MatchMode` enum: `Any`, `All`
- Validation at construction: at least one non-empty criterion

`ClassContext`:

- Minimal info `matches()` reads: FQN, short name, resolved attribute FQNs, resolved interface chain, resolved
  parent-class chain
- Built from existing collection-phase data — no new AST traversal phase needed

`MembershipResult`:

- `Match` with list of matched criterion descriptors AND captured bindings (empty for non-template layers)
- `NoMatch`

---

## Direction 2 — Template layers (submodule partitioning)

### Problem

DDD-style bounded contexts: `App\Module\Order\Domain\*`, `App\Module\Inventory\Domain\*`,
`App\Module\Billing\Domain\*` each form an isolated module. Cross-module dependencies between domains should be
forbidden. Listing each module-as-layer in YAML doesn't scale: a project with 20 modules needs 20 boilerplate entries
plus manual maintenance when a module is added.

### Conceptual semantics

A layer entry can declare a **capture variable** in its name template:

```yaml
- name: 'domain-{module}'
  patterns: 'App\Module\{module}\Domain\**'
```

In `LayerExpansionStage` (after Collection, before RuleExecution):

1. Walk the discovered class set. For each class, attempt to match against the template's capture-producing criteria,
   extracting binding tuples (per D7 — non-capturing criteria filter the candidates).
2. Collect the **distinct observed binding tuples**.
3. Create one concrete `LayerDefinition` per observed tuple — name substituted with the captured values.

Example: scan finds classes in `App\Module\Order\Domain\Foo`, `App\Module\Order\Domain\Bar`, `App\Module\Inventory\Domain\Baz`.
Observed tuples: `{module: Order}`, `{module: Inventory}`. Expanded layers: `domain-Order`, `domain-Inventory`.

After expansion, the rest of the architecture machinery sees ordinary distinct layers. Default allow-list semantics
(Phase 1) say "a layer cannot depend on another layer unless explicitly allowed" — so cross-module domain edges become
natural allow-list violations without any new flag.

### YAML examples

Basic template + glob allow:

```yaml
architecture:
  layers:
    - name: 'domain-{module}'
      patterns: 'App\Module\{module}\Domain\**'
    - name: 'app-{module}'
      patterns: 'App\Module\{module}\Application\**'
    - name: shared-kernel
      patterns: 'App\Shared\**'
    - name: vendor
      patterns: 'vendor/**'

  allow:
    'domain-*':           # any domain-{x} may use ...
      - shared-kernel
      - vendor
    'app-*':              # any app-{x} may use ...
      - 'domain-*'        # any domain-{x} (PERMISSIVE — cross-module app→domain allowed)
      - shared-kernel
```

**Strict same-instance** with capture binding (mandatory MVP feature, per D5):

```yaml
allow:
  'app-{m}':
    - 'domain-{m}'        # same {m} only: app-Order → domain-Order yes; app-Order → domain-Inventory NO
    - shared-kernel
```

`{m}` in source and target binds to the same value. `app-Order` is only allowed to depend on `domain-Order`. This is
the true DDD bounded-context expression.

Explicit cross-instance permission (silences the wildcard-self-allow warning):

```yaml
allow:
  'domain-*':
    - target: 'domain-*'
      allow_cross_instance: true       # acknowledge: domain-* may depend on any domain-*
```

### Capture-variable grammar

- Variable references are `{name}` where `name` is `[A-Za-z_][A-Za-z0-9_]*` (PHP-identifier-like)
- Variable name is **case-sensitive**
- **Captured value** matches a single namespace segment by default — `[^\\]+` (no backslashes); case is preserved
  exactly as it appears in the class FQN; comparison in allow-list (`app-{m}` → `domain-{m}`) is case-sensitive
- **Multi-segment capture:** `{name:**}` matches `(?:[^\\]+(?:\\[^\\]+)*)` — one or more segments. Single-segment
  default is the safer choice and matches common bounded-context layout
- **Literal braces** are written as `\{` and `\}` in YAML strings (rare in PHP FQNs; the escape exists for completeness)
- Variable in name template MUST also appear in at least one capture-producing criterion (config-load validation)
- Same variable name across criteria within one layer binds to the same value (co-binding)
- Variables in different layer entries are independent — no global variable namespace
- A captured value cannot contain `*`, `?`, `[`, `{`, `}` (would collide with selector syntax — guaranteed by the
  `[^\\]+` matching, but tested explicitly)

### Edge cases

- **Discovery scope.** Expansion runs against the class set discovered for the run. Adding a new class in a new
  module produces a new template instance on the next run. No caching across runs.
- **Zero template instances.** A template that produces zero instances fires **`architecture.empty-template`**
  (severity **warning** by default — stronger than info because a typo silently disables policy). One warning per
  template that expands to zero instances.
- **Template instance with zero hits during analysis.** A specific concrete instance produced by expansion may
  match zero classes during rule analysis if all its candidate classes are shadowed by earlier-declared layers, or
  if all are excluded by the layer's `exclude:` block. Emits `architecture.unreachable-layer` (info) at the instance
  level — same handling as any other concrete layer. Note: expansion itself applies all criteria (capture-producing
  AND non-capturing) before observing a binding tuple, so non-capturing rejection at expansion time prevents tuple
  creation rather than producing a zero-hit instance.
- **Static-template name collision.** A template instance whose name collides with a statically declared layer
  (`name: 'domain-Order'`) — reject at expansion time with an error pointing at both declarations.
- **Template-template name collision.** Two templates that produce overlapping instance names (e.g. both expanding to
  `domain-Order` via different patterns) — reject at expansion time with both source templates and the conflicting
  name in the error message.
- **Wildcard-self-allow.** `'domain-*': ['domain-*']` (without capture binding) defeats partitioning by allowing
  all-to-all. Default: emit a `warning`-severity diagnostic suggesting capture-binding `{m}` or explicit pairs.
  Silenceable per entry via `allow_cross_instance: true` — making the intent explicit.
- **Allow-list with capture binding but no source template.** `allow: { 'app-{m}': ['domain-{m}'] }` requires the
  source to be a template producing `{m}`. Reject at config load if source is an exact-name layer and target uses
  `{m}`.
- **Cartesian-blowup guard (multi-variable templates).** Observed-tuple expansion makes blowup much rarer, but still
  possible with very broad multi-variable templates. Hard ceiling on the expanded instance count: **default 500** —
  a conservative number for medium-sized projects; monorepos with 1000+ DDD modules legitimately need to override.
  **Configurable** via `architecture.max_expanded_layers: <N>`. On overflow, reject at expansion with a clear error
  pointing at the offending template, the resulting count, AND the current ceiling, suggesting either splitting
  into narrower layers OR raising the ceiling explicitly.
- **Unbalanced braces in selectors.** A `{` without matching `}` (or a stray `}`) anywhere in a selector string —
  e.g. typo `'domain-{module'` — is rejected at config load with a `ConfigLoadException` pointing at the offending
  line. Without this guard, the string would parse as exact-match (no `{var}` placeholder detected), and the user
  would see a silent non-match rather than an error.
- **Same-layer dependencies in expanded templates.** Intra-layer dependencies (e.g. `domain-Order → domain-Order`)
  are still allowed by default, carrying Phase 1 semantics. Each expanded instance is a distinct layer, so
  `domain-Order → domain-Inventory` is genuinely cross-layer.
- **Deterministic expansion order.** Concrete layers from a single template are inserted at the template's position
  in the declared layer list, in **lexicographic order of captured values** (lex order of tuples for multi-variable
  templates). Deterministic across runs.

### Open questions for Stage 1 review

- **Q4 (closed).** Single-segment default; explicit `{m:**}` for multi-segment.
- **Q5 (closed).** Separate `LayerExpansionStage` AFTER Collection (D3 revised). Cartesian guard lives there;
  `qmx info` introspects expanded layers via this stage.
- **Q6 (closed).** Configurable ceiling `architecture.max_expanded_layers`, default 500. Overflow is a hard error
  with actionable message.
- **Q7 (closed).** 2b ships together with 2a — see D5.

### Contracts (signature level)

`TemplateLayerDefinition`:

- `__construct(string $nameTemplate, MembershipSpec $membership, list<string> $variables)`
- `variables(): list<string>`
- `nameTemplate(): string`

`LayerExpansionStage`:

- `expand(list<LayerDefinition|TemplateLayerDefinition> $entries, ClassSet $classes, int $maxExpansion): list<LayerDefinition>`
- Throws `ConfigLoadException` on blowup ceiling exceeded, static-template name collision, or template-template
  name collision

`LayerSelector` (new abstraction over allow-list keys/values):

- `LayerSelector::exact(string $name): self`
- `LayerSelector::glob(string $pattern): self`
- `LayerSelector::captured(string $pattern, list<string> $variables): self`
- `matchSource(string $layerName): ?CaptureBinding` — for use on LHS; returns binding (possibly empty) on match,
  null otherwise. Glob/exact return empty binding on match
- `matchesTarget(string $layerName, CaptureBinding $sourceBinding): bool` — for use on RHS; captured selectors use
  the binding established on the source side; glob/exact ignore it

`AllowListEntry` (post-Phase 2):

- `__construct(LayerSelector $source, list<AllowTarget> $targets)` — `AllowTarget` defined under direction 4

`CaptureBinding`:

- Map of variable name → captured value
- Empty for non-template selectors

---

## Direction 3 — Negative patterns (`exclude:` within a layer)

### Problem

Sometimes a layer claims a broad pattern but excludes a subtree:

> "All of `App\Service\**` is service, EXCEPT `App\Service\Legacy\**` which is unclassified."

### Status under declaration-order matching

**Substantially redundant** for the namespace-only case. The same outcome is achievable by declaring a narrower layer
EARLIER. But still useful when combined with `suffix`/`attributes`/`implements`/`extends`, or when the excluded subtree
should remain genuinely unclassified.

### Proposed semantics

```yaml
- name: service
  patterns: 'App\Service\**'
  exclude:
    patterns: ['App\Service\Legacy\**']
    suffix: ['LegacyService']
    match: any                # default
```

`exclude:` mirrors the membership criteria (`patterns`, `suffix`, `attributes`, `implements`, `extends`) plus its own
`match: any | all`:

- `match: any` (default): if ANY exclude criterion matches, the class is excluded
- `match: all`: only if ALL exclude criteria match is the class excluded — useful for narrow "exclude suffix X only
  under namespace Y" cases

If exclusion fires, the class is excluded regardless of positive criteria — exclude is a hard filter.

### Edge cases

- **Empty `exclude:`** is a configuration error.
- **Template + exclude with capture variables.** Exclude criteria CAN reference the same capture variables as the
  template name (`exclude: { patterns: 'App\Module\{module}\Domain\Generated\**' }`). They filter only within the
  same-binding instance. Exclude CANNOT introduce a NEW capture variable that doesn't appear in the layer name —
  validated at config load (would be capture-producing in the exclude side, which doesn't make sense).

### Open questions

- **Q8 (closed).** Include with minimal scope; `exclude.match: any | all` added.

### Contracts (signature level)

`ExcludeSpec`:

- Same shape as `MembershipSpec` but with its own `MatchMode` and no recursion (no nested `exclude`)
- At least one criterion required

`MembershipSpec`:

- Gains optional `exclude: ?ExcludeSpec` field

---

## Direction 4 — Dependency type filter

### Problem

> "Domain may implement contracts but may not method-call them."
> "Application may extend Symfony bundles but may not use them by static call."

Phase 1's allow-list says "A may depend on B" yes/no, but doesn't distinguish HOW.

### Proposed semantics (whitelist only)

Long-form allow entry gains an optional `relations` list restricting which edge kinds are permitted:

```yaml
allow:
  domain:
    - target: contracts
      relations: [implements, extends]    # only inheritance, no method calls
    - target: vendor
      relations: [extends]                # may subclass vendor types only
```

### Available relation kinds

`AllowTarget` stores `?list<DependencyType>` directly — no parallel enum. This eliminates drift risk: when the
collector gains a new dependency type, the user-facing surface picks it up automatically. Aliases live at the
configuration layer as syntactic sugar that expands to constituent `DependencyType` values at config-load time.

**Direct values** (1:1 with `DependencyType` enum at `src/Core/Dependency/DependencyType.php`):

- `extends`, `implements`, `trait_use`
- `new`
- `static_call`, `static_property_fetch`, `class_const_fetch`
- `type_hint`, `property_type`, `intersection_type`, `union_type`
- `catch`, `instanceof`
- `attribute`

**Aliases** (config-load expansion):

- `inheritance` → `extends`, `implements`, `trait_use`
- `static_access` → `static_call`, `static_property_fetch`, `class_const_fetch`
- `type_reference` → `type_hint`, `property_type`, `intersection_type`, `union_type`
- `runtime_check` → `catch`, `instanceof`

`attribute` is intentionally NOT grouped under any alias — it's a distinct metadata category, and the standalone
direct value is the clearest way to address it.

User can mix: `relations: [inheritance, attribute]` is shorthand for `[extends, implements, trait_use, attribute]`.
Aliases and direct values are deduplicated after expansion.

**Notable limitation.** There is no instance method-call relation kind in the current `DependencyType` enum — only
`static_call`. Documenting this honestly in user-facing docs; if instance-call tracking becomes a Phase 3 need, it
requires extending the collector first.

Bare string allow entries (`allow: { domain: [contracts] }`) keep current "all kinds allowed" semantics — fully
back-compat.

### Overlapping allow entries

When multiple allow targets within one source resolve to the same target layer (e.g. via overlapping glob selectors),
their permissions **UNION**:

- If any matching entry uses bare/short-form (no `relations`), the union is "all relations allowed" — short-form
  dominates
- Otherwise, the union of `relations` lists applies

Documented in the allow-validator section of Stage 2.

### Why whitelist-only

Whitelist alone implicitly forbids everything else. Adding `forbid_relations` introduces resolution ambiguity,
redundant expressivity, and a maintenance burden when new `DependencyType` values appear. If a real use case for
`forbid_relations` appears, it can be added later without breaking whitelist users.

### Edge cases

- **Empty `relations: []`.** Reject at load: "use a bare allow entry to remove the rule entirely, or list at least
  one relation kind".
- **Unknown relation kind.** Reject at load with the full list of known direct values and aliases.
- **Alias + direct mix.** `relations: [inheritance, static_call]` expands to
  `[extends, implements, trait_use, static_call]` at config load; deduplicated.
- **Long-form mixing with short-form.** Both allowed in the same `allow:` map.
- **Overlap with `allow_cross_instance: true`.** A target that uses both `relations:` and `allow_cross_instance: true`
  is allowed — the flag suppresses the wildcard-self-allow warning, independent of relation kinds.

### Open questions

- **Q9 (closed).** Per-source default for `relations` — deferred until real signal.

### Contracts (signature level)

`AllowTarget` (post-Phase 2):

- `__construct(LayerSelector $target, ?list<DependencyType> $relations = null, bool $allowCrossInstance = false)`
- `relations(): ?list<DependencyType>` — null means "any relation"
- `allowCrossInstance(): bool`

No parallel `RelationKind` enum.

`AllowAliasExpander` (configuration-layer service):

- `expand(list<string> $tokens): list<DependencyType>` — converts user tokens (aliases + direct values) to a
  deduplicated `DependencyType` list
- Validates **direct** token values against `DependencyType::cases()` **reflectively** (not against a hardcoded
  list). This is the mechanism that closes the drift risk: adding a new value to the enum in `Core` automatically
  becomes accepted by the YAML `relations:` field, no Phase 2 code change required
- Validates **alias** tokens against the hardcoded alias map (`inheritance`, `static_access`, `type_reference`,
  `runtime_check`); aliases are Phase-2-controlled vocabulary, expanded to their constituent `DependencyType`
  values

---

## Direction 5 — Catch-all layer recipe (documentation, not feature)

### Status

**No new feature.** Subsumed by declaration-order matching from `architecture-rules-followup.md` (Step 0). A final
layer with `patterns: '**'` already captures every unclassified class.

### Action item

- Add a "Common recipes" section to `website/docs/rules/architecture.md` (EN + RU) documenting catch-all layers
- Cross-link from `architecture-rules-followup.md` Step 0 documentation TODO

Already an item in followup-plan Step 0; mentioning here for tracking.

---

## Out of scope for Phase 2

- **Per-edge severity** (`level: warning | error` per allow entry). No precedent in deptrac/ArchUnit/NDepend.
  Workaround: split into two rules with different severity.
- **Wildcard source/target in non-template configs.** Marginal; direction 2 covers the templated case.
- **`forbid_relations` for direction 4.** Whitelist suffices; revisit on real demand.
- **Pure positional partitioning** (`partition_by: 1`). Rejected — symbolic capture variables (direction 2) cover
  the same use case more legibly and robustly.
- **Discovery-aware caching of template expansion across runs.** Expansion is cheap (single pass over class set);
  caching adds invalidation complexity for marginal gain.
- **Instance method-call relation kind.** Requires extending the collector first; candidate for Phase 3.
- **Per-source default `relations`.** Deferred (Q9).

---

## Stage 2 — Implementation Plan

**Status:** Ready for Stage 2 triple review
**Prerequisite:** `architecture-rules-followup.md` fully landed (declaration-order pivot must be in `main`).
ADR 0006 and ADR 0007 accepted.

Eight steps in two parallelisable branches after Step A. Steps D and E carry triple-review triggers because they
introduce new domain semantics (pipeline stage, capture-binding contract); the rest are standard or no-review.

---

### Step A: `MembershipSpec` + `MatchMode` + `ClassContext` scaffolding (direction 1 backbone)

**Goal.** Introduce the new membership abstraction and refactor `LayerDefinition` to use it. Behaviour unchanged —
this is a structural refactor that enables Steps B/C/D/F to add features without further touching `LayerDefinition`.

**Files:**

- NEW `src/Core/Architecture/Layer/MembershipSpec.php` — VO carrying the five criterion lists + `MatchMode` + optional
  `ExcludeSpec` reference (kept null in Step A; populated in Step F)
- NEW `src/Core/Architecture/Layer/MatchMode.php` — enum `Any`, `All`
- NEW `src/Core/Architecture/Layer/ClassContext.php` — VO read by `LayerDefinition::matches()`; carries FQN, short
  name, resolved attribute FQNs, interface chain, parent-class chain. In Step A, only FQN and short name are
  populated; Step B fills the rest.
- NEW `src/Core/Architecture/Layer/MembershipResult.php` — `Match | NoMatch` sum type with matched criterion
  descriptors (empty in Step A; populated in Step B)
- MODIFY `src/Core/Architecture/Layer/LayerDefinition.php` — `__construct(string $name, MembershipSpec $membership)`,
  `matches(ClassContext): MembershipResult`. Old `matches(string $fqn): bool` becomes a thin wrapper for migration —
  removed at end of Step B.
- MODIFY `src/Configuration/Architecture/ArchitectureConfigurationFactory.php` — accept ONLY `patterns:` and `match:`
  in long-form layer entries; build `MembershipSpec`. Unknown criterion keys (`suffix`, `attributes`, etc.) are
  REJECTED with a clear error until Step B opens the schema. ("Accept but silently ignore" was rejected during
  Stage 2 review — silently-no-op fields are a footgun.)
- MODIFY `src/Core/Architecture/Layer/LayerRegistry.php` — `resolveLayer()` and `resolveAll()` continue to accept
  `SymbolPath` at the public boundary, but internally build a minimal `ClassContext` (FQN + short name only) and
  delegate to `LayerDefinition::matches(ClassContext)`. Step B injects a full `ClassContextFactory` so the registry
  can populate the rest of the context.
- NEW `tests/Integration/Architecture/Phase1ConfigCompatibilityTest.php` — pins behaviour: a representative
  Phase-1-shape config (patterns-only layers, bare-string allow entries, no templates) loads and produces identical
  violations through every Stage 2 step. This file is created in Step A and exercised on every step's branch tip
  per the BC requirement (D6).
- MODIFY tests for `LayerDefinition`, `LayerRegistry`, `ArchitectureConfigurationFactory`

**Test cases:**

- Existing Phase 1 configs (patterns only) build a `MembershipSpec` with only `patterns` set, `match: any` default
- `match: all` parses; `match: any` is the default
- Unknown `match:` value rejected at config load with the valid values listed
- `MembershipSpec` rejects construction with all criterion lists empty (will be enforced more strictly in Step B)
- `LayerDefinition::matches(ClassContext)` returns `Match` for FQN-matching ClassContext, `NoMatch` otherwise
  (same behaviour as Phase 1 + followup, new shape)

**DoD:**

- [ ] `composer check` green
- [ ] All existing `LayerDefinitionTest` cases pass (behaviour preserved)
- [ ] No new diagnostics or violations on `bin/qmx check src/`
- [ ] Self-analysis: dogfooding qmx.yaml continues to work without changes

**Dependencies:** followup-plan Step 0 (LayerDefinition new contract). No other Step 2 dependencies.

**Review:** standard (introduces 4 new VOs in `Core/Architecture/Layer/`).

---

### Step B: Membership criteria — `suffix`, `attributes`, `implements`, `extends` (direction 1 completion)

**Goal.** Wire the four new criterion kinds + extend `MembershipResult` to record which criteria matched +
populate `ClassContext` from collection-phase data.

**Files:**

- MODIFY `src/Core/Architecture/Layer/MembershipSpec.php` — final shape:
  `__construct(list<string> $patterns, list<string> $suffix, list<string> $attributes, list<string> $implements,
  list<string> $extends, MatchMode $mode, ?ExcludeSpec $exclude = null)`; validate at construction (at least one
  non-empty criterion list when no exclude; documented invariant)
- MODIFY `src/Core/Architecture/Layer/LayerDefinition.php` — `matches(ClassContext)` walks all five criteria
  per `MatchMode`; remove the migration wrapper added in Step A; populate `MembershipResult.matchedCriteria`
- MODIFY `src/Core/Architecture/Layer/ClassContext.php` — full population from `AnalysisContext::$metrics` and
  collection-derived attribute/interface/parent-class chains
- NEW `src/Core/Architecture/Layer/ClassContextFactory.php` — service building `ClassContext` from the collection
  results; used by `LayerViolationRule`, `LayerRegistry`, and (later) `LayerExpansionStage`. **Data source:** the
  factory reads attribute FQNs, interface chain, and parent-class chain from the existing collection-phase metadata
  carried in `MetricBag` / `CollectionResult`. **Worker compatibility:** any new VO referenced by collection output
  must be serializable for `amphp/parallel` workers — `ClassContext` itself is built outside workers (in the main
  process from already-merged collection output), but the underlying metadata fields must be added to the worker
  result type if not already present
- MODIFY `src/Core/Architecture/Layer/LayerRegistry.php` — final form: accepts `ClassContextFactory` in
  constructor; `resolveLayer(SymbolPath)` and `resolveAll(SymbolPath)` now build full `ClassContext` (not minimal)
  for matching against criteria that need attribute/interface/parent data
- MODIFY `src/Configuration/Architecture/Validation/LayersValidator.php` (introduced by followup-plan Step 3) —
  validate the four new keys: suffix is short-name (no `\`), attributes/implements/extends are FQN strings, all
  non-empty if present
- MODIFY `src/Rules/Architecture/LayerViolationRule.php` — pass `ClassContext` (built via factory) into
  `resolveEdge`/`resolveLayer` lookups. Update violation message to include matched-criterion descriptor when OR
  semantics produced the match.
- MODIFY tests: per-criterion unit tests + integration test combining `match: all` with multiple criteria

**Test cases:**

- Each criterion individually matches a representative class
- `match: any` with multiple criteria: class matching only `suffix` (not `patterns`) is a member
- `match: all`: class matching `patterns` but not `attributes` is NOT a member
- `match: all` with unset criterion (e.g. `attributes` empty): trivially satisfied; the criterion is ignored
- Short-name in `attributes:` is rejected at config load (FQN required)
- Transitive interface/parent-class resolution: class implements `Sub` extends `Base` — matches `implements: Base`
- Violation message records WHICH criterion matched ("suffix", "attribute Foo", "interface Bar")

**DoD:**

- [ ] `composer check` green
- [ ] Existing `bin/qmx check src/` output unchanged (no behaviour drift; new criteria only fire when declared)
- [ ] A fixture project exercising all five criteria with both `match: any` and `match: all` produces expected
      violations and zero false-positives

**Dependencies:** Step A.

**Review:** standard (new criterion kinds, well-defined per-criterion semantics).

---

### Step C: `LayerSelector` abstraction + glob allow-list (foundation for templates and direction 4)

**Goal.** Replace raw strings in allow-list keys/values with `LayerSelector` instances. Parse selector kind from
string content per the D4 grammar. Glob form (`'domain-*'`) works end-to-end. Capture-binding form (`{var}`) parses
but binding flow is wired in Step E.

**Files:**

- NEW `src/Core/Architecture/Allow/LayerSelector.php` — sealed VO with private constructor + three static factories
  `exact`, `glob`, `captured`. Methods: `matchSource(string $layerName): ?CaptureBinding`,
  `matchesTarget(string $layerName, CaptureBinding $sourceBinding): bool`, `originalString(): string`
- NEW `src/Core/Architecture/Allow/CaptureBinding.php` — immutable map of `string => string` (variable name →
  captured value)
- NEW `src/Core/Architecture/Allow/AllowTarget.php` — VO carrying `LayerSelector $target`, optional `?list<DependencyType>
  $relations` (null in Step C; wired in Step G), optional `bool $allowCrossInstance` (false in Step C; wired in
  Step E). Step C builds with the optional fields defaulted.
- NEW `src/Core/Architecture/Allow/AllowListEntry.php` — `LayerSelector $source`, `list<AllowTarget> $targets`
- MODIFY `src/Configuration/Architecture/Validation/AllowValidator.php` (introduced by followup-plan Step 3) —
  parse strings into `LayerSelector` instances; detect selector kind from content; reject layer names containing
  `* ? [ { }`; reject unbalanced braces in selectors with a `ConfigLoadException` pointing at the line
- MODIFY `src/Core/Architecture/ArchitectureConfiguration.php` — `allowList()` returns `list<AllowListEntry>`
- MODIFY `src/Core/Architecture/Layer/LayerPolicy.php` — `isAllowed(source, target): bool` migrates from
  map-based lookup to traversal of `list<AllowListEntry>` using `LayerSelector::matchSource(sourceName)` and
  `matchesTarget(targetName, sourceBinding)`. In Step C the source binding is always empty (captured selectors
  parse-only; Step E wires real bindings). The method signature stays `isAllowed(source, target): bool` at this
  step; Step G adds an overload accepting `DependencyType` for the relation filter.
- MODIFY `src/Rules/Architecture/LayerViolationRule.php` — `LayerPolicy::isAllowed()` is the only entry point that
  changes; the rule itself sees no contract change beyond what Step B already introduced (passing `ClassContext`
  to `LayerRegistry`)
- Tests: per-kind selector tests, grammar edge cases (unbalanced braces, reserved chars in names), back-compat with
  bare-string Phase 1 configs

**Test cases:**

- `'domain'` parsed as exact selector; matches only layer named `domain`
- `'domain-*'` parsed as glob selector; matches `domain-Order`, `domain-Inventory`
- `'domain-{m}'` parsed as captured selector; `matchSource('domain-Order')` returns `{m: Order}` binding
- `'domain-{m'` rejected at config load (unbalanced brace) with line number in message
- Layer entry with `name: 'has-bracket-]'` rejected at config load
- Phase 1 bare-string allow entries `[contracts, vendor]` still work — built as exact selectors
- Per `LayerSelector::glob`, `matchesTarget` ignores binding (empty binding passes through)

**DoD:**

- [ ] `composer check` green
- [ ] All existing allow-list tests pass (back-compat)
- [ ] Glob allow entries work end-to-end on a fixture project
- [ ] Grammar edge cases produce clear `ConfigLoadException`s

**Dependencies:** followup-plan Step 0 (uses `LayerDefinition::name()`).

**Review:** **triple** (Step C carries contracts that D, E, and G depend on — `LayerSelector` API, `AllowListEntry`
shape, `LayerPolicy` migration. Reviewed once here so downstream steps don't need to re-litigate the foundation).
Parallelisable with Step B at the file level (disjoint directories: B in `Core/Architecture/Layer/`, C in
`Core/Architecture/Allow/`), but both touch `LayerViolationRule.php` and `LayersValidator.php` — **execute
sequentially** until one branch is merged.

---

### Step D: `LayerExpansionStage` + observed-tuple template expansion + `architecture.empty-template` (direction 2a)

**Goal.** Introduce `TemplateLayerDefinition` and a new pipeline stage between Collection and RuleExecution that
expands templates into concrete `LayerDefinition`s by observed binding tuples. Add the warning-severity
`architecture.empty-template` diagnostic and the configurable cartesian-blowup ceiling.

**Files:**

- NEW `src/Core/Architecture/Layer/TemplateLayerDefinition.php` — `nameTemplate: string`, `MembershipSpec
  $membership`, `list<string> $variables`. Validation at construction: every variable referenced in `nameTemplate`
  must also appear in at least one capture-producing criterion
- NEW `src/Analysis/Architecture/LayerExpansionStage.php` — **regular service** (not a generic "pipeline stage"
  abstraction — the project has none; `AnalysisPipeline::analyze()` is hand-written sequential code). Method:
  `expand(list<LayerDefinition|TemplateLayerDefinition> $entries, ClassSet $classes, int $maxExpansion): LayerExpansionResult`.
  Walks the class set for each template, applies all criteria (capture-producing AND non-capturing) per D7,
  collects distinct observed binding tuples, instantiates one `LayerDefinition` per tuple. Inserts expanded
  instances at the template's position in declaration order with lexicographic ordering of captured values.
- NEW `src/Analysis/Architecture/LayerExpansionResult.php` — VO carrying `list<LayerDefinition> $expandedLayers`
  AND `list<string> $emptyTemplateNames` (templates that expanded to zero instances). This is the channel for the
  `architecture.empty-template` diagnostic to reach the rule.
- NEW `src/Core/Architecture/Layer/ClassSet.php` — VO wrapping the discovered class FQN set + `ClassContext`
  resolver for each. Built by `AnalysisPipeline` from `CollectionResult` metadata.
- MODIFY `src/Configuration/ConfigSchema.php` — add constant for `architecture.max_expanded_layers` (default 500)
- MODIFY `src/Configuration/Architecture/ArchitectureConfigurationFactory.php` — recognise capture variables in
  `name` and pattern strings; build `TemplateLayerDefinition` when found; validate "variable in name → variable in
  at least one capture-producing criterion"
- MODIFY `src/Configuration/Architecture/Validation/LayersValidator.php` — template-layer validation
- MODIFY `src/Analysis/Pipeline/AnalysisPipeline.php` — inject `LayerExpansionStage`; after `collectionOrchestrator->collect()`
  returns and BEFORE `MetricEnricher::enrich()`, call expansion when `ArchitectureConfiguration` carries any
  `TemplateLayerDefinition`. The resulting `LayerExpansionResult` is written into `ArchitectureConfigurationHolder`
  (the existing shared holder between RuntimeConfigurator and AnalysisPipeline).
- MODIFY `src/Core/Architecture/ArchitectureConfigurationHolder.php` — gains `setExpandedLayers(LayerExpansionResult)`
  + `expandedLayers(): ?LayerExpansionResult`. `LayerRegistry` consumes the expanded list when present; falls back
  to the static layer list otherwise.
- MODIFY `src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php` — register
  `LayerExpansionStage` as a service; add it to `AnalysisPipeline`'s constructor argument list
- MODIFY `src/Core/Architecture/ArchitectureConfiguration.php` — gains the unexpanded entries list method
- MODIFY `src/Rules/Architecture/LayerViolationRule.php` — adds `EMPTY_TEMPLATE_DIAGNOSTIC_NAME` constant and
  `buildEmptyTemplateDiagnostics()` helper. Reads `emptyTemplateNames` from the expansion result via the holder;
  emits one warning-severity diagnostic per name at end of run.
- (Note: `src/Core/Rule/AnalysisContext.php` is NOT modified — expanded layers reach the rule through
  `ArchitectureConfigurationHolder`, not through `AnalysisContext`, to keep the rule context boundary stable.)
- Tests: per-template-shape unit tests + integration test exercising the full pipeline; tests for empty-template
  diagnostic, ceiling overflow, static-template and template-template name collisions, deterministic order

**Test cases:**

- Single template `'domain-{module}'` with `patterns: 'App\Module\{module}\Domain\**'` expands to N concrete layers
  for N observed module values
- Single-variable expansion is deterministic and lex-sorted by captured value
- Multi-variable template (`'cluster-{tenant}-{module}'`) expands only by observed binding tuples, not cartesian
  product (a fixture with disjoint tenant/module sets must not produce phantom layers)
- Cartesian ceiling: a template that would expand to 501 instances with default ceiling fails fast with an error
  pointing at the template + count + ceiling + suggestion. `architecture.max_expanded_layers: 1000` raises the
  ceiling.
- `architecture.empty-template` fires (warning) for a template whose patterns matched no class in the codebase
- Static-template name collision: `name: 'domain-Order'` and `name: 'domain-{m}'` expanding to `domain-Order` —
  rejected at expansion with error pointing at both declarations
- Template-template name collision: two templates expanding to the same instance name — rejected at expansion
- D7 carve-out: a class matched by a capture-producing criterion (with valid bindings) but failing a non-capturing
  filter is NOT included — the tuple is never observed
- `match: any` for a template: at least one capture-producing criterion matches AND establishes bindings; the
  capture-producing criteria are combined per `match: any | all`

**DoD:**

- [ ] `composer check` green
- [ ] Dogfood: at least one template is added to `qmx.yaml` (e.g. for `src/Metrics/{Category}/`) and produces
      expected concrete layers
- [ ] `architecture.empty-template` fires on a fixture with a typo'd template
- [ ] Cartesian overflow error message is actionable

**Dependencies:** Step A (uses `MembershipSpec`), Step B (uses criteria), Step C (selectors used by allow-list when
references concrete instances).

**Review:** **triple** (new pipeline stage, new contracts in two domains, complex expansion semantics).

---

### Step E: Capture-binding in allow-list + `allow_cross_instance` + wildcard-self-allow warning (direction 2b)

**Goal.** Wire `LayerSelector::captured` end-to-end: `matchSource` returns a real binding, `matchesTarget` consumes
it. Add the `allow_cross_instance` long-form flag and the wildcard-self-allow warning.

**Files:**

- MODIFY `src/Core/Architecture/Allow/LayerSelector.php` — `captured` factory + corresponding `matchSource` /
  `matchesTarget` implementations (binding extracted from source name; binding used to instantiate target glob)
- MODIFY `src/Core/Architecture/Allow/AllowTarget.php` — wire `allowCrossInstance: bool` field
- MODIFY `src/Configuration/Architecture/Validation/AllowValidator.php` — parse `allow_cross_instance: true` on
  long-form entries; cross-validate that captured-variable targets reference variables declared on the source
  (config-load error if `'app-{x}': ['domain-{y}']` — `{y}` is not bound by source)
- NEW `src/Configuration/Architecture/Validation/WildcardSelfAllowDetector.php` — emits a deferred warning
  (via Step 1 of followup-plan) when an entry has glob-on-both-sides and lacks `allow_cross_instance: true`
- MODIFY `src/Rules/Architecture/LayerViolationRule.php` — when evaluating whether a target allow applies, walk
  `LayerSelector::matchSource` on the source layer name, then `matchesTarget` on the target layer name with the
  source binding
- Tests: same-instance allow positive/negative, cross-instance flag, wildcard-self-allow warning fires by default,
  silenced by flag, target with undeclared captured variable rejected

**Test cases:**

- `'app-{m}': ['domain-{m}']` allows `app-Order → domain-Order` but NOT `app-Order → domain-Inventory`
- `'domain-*': ['domain-*']` emits a wildcard-self-allow warning at config load; `allow_cross_instance: true` on
  the long-form variant silences the warning
- `'app-{x}': ['domain-{y}']` is rejected at config load (undeclared `{y}` on target)
- Glob source + captured target: `'shared-*': ['domain-{m}']` is rejected (source doesn't produce `{m}`)
- Captured source + exact target: `'domain-{m}': ['vendor']` works (vendor is exact, ignores binding)

**DoD:**

- [ ] `composer check` green
- [ ] Dogfood: at least one `allow:` block uses capture binding
- [ ] DDD partitioning fixture: cross-module domain → domain edges trigger violations; same-module edges pass

**Dependencies:** Step D (concrete template instances must exist).

**Review:** **triple** (this is the DDD-critical piece; binding semantics carry the most foot-gun potential).

---

### Step F: `exclude:` clause + `exclude.match` (direction 3)

**Goal.** Add the `exclude` block to layer entries. Mirrors `MembershipSpec` criteria with its own `match: any | all`.
Pure hard filter — when exclusion fires, the class is excluded regardless of positive criteria.

**Files:**

- NEW `src/Core/Architecture/Layer/ExcludeSpec.php` — same shape as `MembershipSpec` (five criterion lists +
  `MatchMode`) but no nested exclude. Validation: at least one non-empty criterion
- MODIFY `src/Core/Architecture/Layer/MembershipSpec.php` — wire the optional `exclude` field
- MODIFY `src/Core/Architecture/Layer/LayerDefinition.php` — `matches()` first checks positive criteria, then if
  matched, evaluates exclude (hard filter); if exclude fires, returns `NoMatch`
- MODIFY `src/Configuration/Architecture/Validation/LayersValidator.php` — accept `exclude:` block on layer entries;
  validate each kind; reject exclude block introducing new capture variables not declared by the template
- Tests: each criterion in exclude; `exclude.match: all`; exclude with template capture variables (filters within
  same-binding instance); exclude can't introduce new capture variables

**Test cases:**

- `service` layer with `patterns: 'App\Service\**'` and `exclude.patterns: ['App\Service\Legacy\**']` correctly
  excludes the subtree
- `repository` layer with `suffix: 'Repository'` and `exclude.patterns: ['App\Test\**']` excludes test repositories
- `exclude.match: all` requires every exclude criterion to match before excluding ("only exclude if it's both in
  Legacy AND ends with `Bridge`")
- Template `'domain-{m}'` with `exclude.patterns: 'App\Module\{m}\Domain\Generated\**'` filters generated code per
  instance
- Exclude introducing `{n}` (not in layer name) rejected at config load

**DoD:**

- [ ] `composer check` green
- [ ] Fixture with combined positive + exclude works as documented
- [ ] No behaviour drift on configs without `exclude:`

**Dependencies:** Step B (mirrors `MembershipSpec` criterion machinery). **Sequencing note:** Step F modifies
`MembershipSpec.php` (adds `exclude` field). Step D also modifies `MembershipSpec.php` (via `TemplateLayerDefinition`
construction). Therefore F and D **must not run in parallel** despite sitting on different branches of the
sequencing diagram. Run F either fully before D or fully after E.

**Review:** standard (mechanical mirror of Step B's criteria with hard-filter semantics).

---

### Step G: `relations:` filter + `DependencyType` direct + 4 aliases (direction 4)

**Goal.** Long-form allow targets gain optional `relations` list restricting edge kinds. `AllowTarget` stores
`?list<DependencyType>` directly. `AllowAliasExpander` (configuration layer) provides reflective validation against
`DependencyType::cases()` for direct tokens + hardcoded alias map for `inheritance`, `static_access`,
`type_reference`, `runtime_check`.

**Files:**

- NEW `src/Configuration/Architecture/Allow/AllowAliasExpander.php` — `expand(list<string> $tokens):
  list<DependencyType>`; reflective validation against `DependencyType::cases()` for direct values; hardcoded
  alias map; deduplicates after expansion
- MODIFY `src/Core/Architecture/Allow/AllowTarget.php` — wire the `relations` field (added in Step C as null)
- MODIFY `src/Configuration/Architecture/Validation/AllowValidator.php` — parse `relations:` on long-form entries;
  call `AllowAliasExpander` to produce the `list<DependencyType>`; reject empty list and unknown tokens; handle
  overlapping allow entries union semantics (short-form dominates)
- MODIFY `src/Rules/Architecture/LayerViolationRule.php` — when evaluating an allow entry, check the dependency
  edge's `DependencyType` against the entry's `relations` (if non-null); when null, accept any relation
- Tests: each alias expansion, direct enum values accepted, unknown token rejected with full known-list in error,
  alias + direct mix dedup, overlapping union semantics (short-form + long-form on same source-target pair)

**Test cases:**

- `relations: [extends, implements]` allows only inheritance edges
- `relations: [inheritance]` expands to `[extends, implements, trait_use]`
- `relations: [inheritance, static_call]` expands to `[extends, implements, trait_use, static_call]`
- `relations: [unknown_token]` rejected with error listing all known direct values and aliases
- Reflective validation drift-test (data-provider over `DependencyType::cases()`): for every existing enum value,
  asserts that `AllowAliasExpander::expand([$value])` accepts it and returns `[$value]`. The test re-runs whenever
  the enum changes; the assertion list is generated from `cases()`, never hardcoded. This is how "drift risk closed"
  is mechanically enforced — no manual updates required when the enum gains a new value
- Overlapping allow entries: `domain: [contracts]` + `domain: [- target: contracts, relations: [extends]]` — the
  short-form dominates (any relation allowed for `contracts`)
- Empty `relations: []` rejected at config load
- Edge of type `static_call` is allowed when `relations: [static_access]`; disallowed when `relations: [inheritance]`

**DoD:**

- [ ] `composer check` green
- [ ] Dogfood: at least one allow entry uses `relations:` with an alias
- [ ] Fixture exercising each direct value and each alias produces expected violations

**Dependencies:** Step C (uses `AllowTarget`).

**Review:** standard (well-defined alias map + reflective validation).

---

### Step H: Documentation, recipes, dogfood migration, CHANGELOG

**Goal.** Public-facing docs, recipes, examples, and dogfood `qmx.yaml` migration. Final wrap-up.

**Files:**

- MODIFY `website/docs/rules/architecture.md` (EN) — sections for each new feature: multi-criterion membership,
  templates with capture variables, exclude block, relations filter. Include the runnable YAML examples from
  Stage 1 plan
- MODIFY `website/docs/rules/architecture.ru.md` (RU) — same content, structurally aligned
- MODIFY `website/docs/getting-started/configuration.md` (EN + RU) — capture variable grammar reference;
  `architecture.max_expanded_layers` config option
- MODIFY `qmx.yaml` (dogfood) — migrate at least one section to use templates + capture binding; verify
  `bin/qmx check src/` stays clean
- MODIFY `CHANGELOG.md` — `Changed` entries for each direction; one `Breaking` entry only if any Phase 1 config
  surfaces an incompatibility (none expected per D6)
- VERIFY `mkdocs build --strict` green
- VERIFY no orphan ADR references; cross-link 0007 from the architecture rules pages

**Test cases:** N/A (docs step)

**DoD:**

- [ ] `cd website && .venv/bin/mkdocs build --strict` green
- [ ] EN and RU structurally aligned
- [ ] CHANGELOG entries under `[Unreleased]`
- [ ] Dogfood `qmx.yaml` exercises at least: one multi-criterion layer, one template, one capture-binding allow,
      one `exclude:`, one `relations:` filter

**Dependencies:** Steps A–G.

**Review:** none (docs).

---

## Cross-cutting (Stage 2)

### Validation strategy

After each step: `composer check` AND `bin/qmx check src/ --memory-limit=512M` (self-analysis must remain clean of
new violations).

### Review trigger summary

| Step  | Review level | Reason                                                                                        |
| ----- | ------------ | --------------------------------------------------------------------------------------------- |
| A     | standard     | new VOs in `Core/Architecture/Layer/`                                                         |
| B     | standard     | criterion semantics, well-defined per-kind                                                    |
| **C** | **triple**   | foundation contracts for D, E, G (`LayerSelector`, `AllowListEntry`, `LayerPolicy` migration) |
| **D** | **triple**   | new expansion stage wired into `AnalysisPipeline`, complex semantics, three new contracts     |
| **E** | **triple**   | DDD-critical capture-binding flow                                                             |
| F     | standard     | mechanical mirror of B with hard-filter                                                       |
| G     | standard     | alias map + reflective validation                                                             |
| H     | none         | docs                                                                                          |

### Sequencing

```
                              [ followup plan landed ]
                                        |
                                        v
                                   [ Step A ]
                                        |
                       +----------------+----------------+
                       v                                 v
                   [ Step B ]                       [ Step C ]
                       |                                 |
                       +---------+                       v
                                 |                  [ Step D — triple review ]
                                 |                       |
                                 v                       v
                            [ Step F ]              [ Step E — triple review ]
                                                         |
                                                         v
                                                    [ Step G ]
                       +---------+---------------------+
                                 v
                            [ Step H ]
```

Steps B and C run in parallel after A; F can run anytime after B; D requires both B and C; E requires D; G requires
C (and benefits from edge data available by E). H is last.

### Backward compatibility

Phase 1 configs (post-ADR 0006 schema) must continue to work through all Stage 2 steps. Add a regression test in
`tests/Integration/Architecture/Phase1ConfigCompatibilityTest.php` that builds and runs a Phase-1-shape config
on each step's branch tip.

### Subagent isolation

- Steps A, B, F operate on `Core/Architecture/Layer/` and touch `Rules/Architecture/LayerViolationRule.php`.
  Sequential.
- Step C operates on `Core/Architecture/Allow/` plus modifies `LayerPolicy.php` and `LayerViolationRule.php`.
  **Cannot parallel with B** despite different primary directories — both touch `LayerViolationRule.php`. Run B
  fully before C (or vice versa).
- Steps D and E operate on `Analysis/Architecture/`, `Core/Architecture/Allow/`, `Analysis/Pipeline/`,
  `Infrastructure/DependencyInjection/`. Sequential after C.
- Step F modifies `MembershipSpec.php` — **cannot parallel with D**. Run F before D or after E.
- Step G touches `Configuration/Architecture/Allow/` and `LayerViolationRule.php`. Sequential after E (because E
  has the latest `LayerViolationRule.php`).
- Step H is single-agent, last.

In short: this is **effectively a sequential plan**, not a parallelisable one. The original "branches" view of
the sequencing diagram was over-optimistic — too many shared files. Treat the diagram as showing logical dependency
order, not opportunities for parallel agents.

### Cache invalidation

Phase 2 adds new membership-criteria input to `LayerDefinition` (attributes, interfaces, parent classes). AST
cache keys (`ASTCache`) must include the architecture-config hash so that adding/removing layers with attribute or
implements criteria invalidates the cache. Validate in Step H by adding a regression test: change `qmx.yaml`
architecture section between two runs, expect the second run to re-evaluate (not hit stale cache).

### Performance budget

Evidence-based `architecture.potential-shadow` from followup-plan is `O(classes × layers × patterns-per-layer)`.
Phase 2 adds 4 more criterion kinds; the per-layer cost grows from "match against N patterns" to "match against
N patterns + M suffixes + K attributes + L interfaces + P parent classes". For typical projects this remains
sub-second; large monorepos (>100k classes × 50 layers) should be benchmarked in Step H and the result documented.
If the cost exceeds 5 seconds on the benchmark suite (`benchmarks/`), raise it as a follow-up — not a Phase 2
blocker.

---

## Definition of Done

### Stage 1 (complete)

- [x] Stage 1 triple review (Claude + Gemini + Codex) — three rounds completed; findings folded in
- [x] All locked decisions D1–D7 accepted (no revisions in round 3)
- [x] All open questions Q1–Q9 closed
- [x] ADR 0007 drafted and accepted
- [x] Stage 2 implementation plan written

### Stage 2 (ready for review)

- [ ] Stage 2 triple-review round (focus: step boundaries, file lists, sequencing, test coverage, review triggers
      — NOT design)
- [ ] Review findings folded in
- [ ] Steps A–H executable independently from this document with no further design decisions needed
- [ ] Subagent isolation correct (no parallel agents touching same files)
- [ ] BC compat regression test described in cross-cutting
