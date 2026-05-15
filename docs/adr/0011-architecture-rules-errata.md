# 0011. Architecture Rules: Errata for ADR 0005 and ADR 0007

**Date:** 2026-05-15
**Status:** Accepted
**Supersedes:** [0005](0005-architecture-rules.md) (errata for "Phase 2 deferrals" section), [0007](0007-architecture-rules-phase-2-design.md) (errata for "info vs warning" wording, D7 template-criteria phrasing, and D4 metacharacter list)

## Context

ADR 0005 (Phase 1) and ADR 0007 (Phase 2) were authored before their respective implementations
landed. After the Phase 2 implementation stabilized and the architecture remediation cycle
(2026-05-15) audited the resulting code against the locked ADR text, three specific passages were
identified that no longer match shipped behaviour or contain internal contradictions.

ADRs are immutable. This errata captures the corrections in a separate document so that future
readers consulting 0005 or 0007 can find the authoritative reading without having to derive it
from the code. The main decisions of both ADRs remain in force; only the items listed below
require correction.

## Decision

### E1. ADR 0005 Phase 2 deferral on dependency-type filtering — superseded by ADR 0007

ADR 0005 §"Phase 2 deferrals" states:

> **`types:` filter per allow-rule** — the YAML structure already accepts `{target: 'foo', types: [extends, method_call, ...]}` for forward compatibility, but the filter is not enforced. Declaring `types:` emits a configuration warning.

This passage is **stale**. The feature shipped in Phase 2 (Step G) under the key **`relations:`**,
not `types:`. The YAML key `types:` is **not** recognised by the loader; it is not silently
forward-compatible and it does not emit a "configuration warning". An entry like
`{target: 'foo', types: [...]}` is parsed as an unknown key under the long-form allow entry — its
handling follows the generic unknown-key validation of the surrounding section, not a dedicated
warning specific to `types:`.

Authoritative current behaviour (ADR 0007 D4 / Direction 4 implementation note):

- The user-facing key is `relations:`
- Direct token values are validated reflectively against `Qualimetrix\Core\Dependency\DependencyType::cases()`
- Aliases (`inheritance`, `static_access`, `type_reference`, `runtime_check`) expand at config load via `AllowAliasExpander`
- `attribute` is a stand-alone direct token, not aliased
- Long-form is the only form that accepts a relations filter; bare/short-form targets remain "any relation kind"

### E2. ADR 0007 D7 — capture-producing vs non-capturing criterion combination

ADR 0007 §D2 defines `match: any | all` for non-template layers. §D7 carves out template-layer
semantics: capture-producing criteria combine according to `match`; non-capturing criteria always
act as AND-filters.

Round-3 and round-4 reviews of the Phase 2 design surfaced that the D2 vs D7 split, read together,
left readers with two plausible interpretations of `match: all` on a template layer:

1. **All criteria (capture-producing and non-capturing) must each produce at least one match within their kind** — symmetric with D2's non-template definition.
2. **All capture-producing criteria must produce a match with consistent bindings; non-capturing criteria additionally filter the survivors.**

The authoritative reading is interpretation (2). D7 is correct as stated; the source of confusion
is that the reader has to combine D2 (general rule) with D7 (template carve-out) to arrive at it,
and D7's wording leaves implicit the fact that non-capturing criteria are not part of the
`match: all` quantifier — they are an unconditional post-filter regardless of `match` mode.

Authoritative phrasing for template-layer membership:

- Template layers MUST declare at least one capture-producing criterion (config-load validation).
- **Capture-producing criteria** combine according to `match: any | all`:
    - `match: any` (default) — at least one capture-producing criterion produces a binding; that binding is the candidate.
    - `match: all` — every capture-producing criterion produces a match, and all of their bindings agree on every shared variable.
- **Non-capturing criteria** (declared on the same layer) act as a hard AND-filter on the candidates from the previous step, regardless of `match` mode. They never participate in the `match: all` quantifier.
- A class matching a capture-producing criterion but failing a non-capturing filter is excluded from tuple observation — no concrete instance is materialised.

The shipped expansion implementation in `LayerExpansionStage` and `TupleExtractor` follows this
phrasing; D7's wording remains correct but should be read with E2 in mind.

### E3. ADR 0007 "info vs warning" wording for new Phase 2 diagnostics

ADR 0007 §Consequences states:

> **Two new info-severity diagnostics specific to Phase 2.** `architecture.empty-template` (warning, not info — a typo silently disables policy and deserves attention) and the existing `unreachable-layer` extended to fire per concrete template instance.

The leading clause "Two new info-severity diagnostics" is **contradicted** by its own parenthetical
("warning, not info"). The authoritative severities, as shipped and pinned by tests:

| Diagnostic                       | Severity    | Notes                                                                                      |
| -------------------------------- | ----------- | ------------------------------------------------------------------------------------------ |
| `architecture.empty-template`    | **warning** | New in Phase 2. A typo silently disables policy; warrants attention.                       |
| `architecture.unreachable-layer` | info        | Existed pre-Phase-2 (ADR 0006). Phase 2 extends it to fire per concrete template instance. |
| `architecture.potential-shadow`  | info        | ADR 0006, unchanged by Phase 2.                                                            |

Read the §Consequences paragraph as: "One new warning-severity diagnostic and one info-severity
diagnostic extended in scope." There is no info-severity `empty-template`.

### E4. ADR 0007 D4 metacharacter list — `[` is rejected, not accepted

ADR 0007 §D4 lists `[` as a glob metacharacter that, in the absence of a `{var}` placeholder,
classifies a selector as a **glob** rather than an **exact** string:

> Contains glob metacharacters (`*`, `?`, `[`) without `{var}` → **glob** (e.g. `'domain-*'`)

This was the original intent. The shipped behaviour (per ADR 0006 / Phase 5.7 / M17) **rejects `[`
in allow-list selectors at config-load time** with a `ConfigLoadException` that suggests `{var}`
capture syntax. Brackets are not silently interpreted as glob character classes, because the
collision with `{var}` capture intent caused semantic divergence in user-authored configs.

Authoritative grammar for `LayerSelector`:

- Contains `{var}` placeholder → **captured glob** (e.g. `'domain-{m}'`)
- Contains `*` or `?` without `{var}` → **glob** (e.g. `'domain-*'`)
- Contains `[` → **rejected** at config load with an actionable hint
- Otherwise → **exact**

Layer **names** are independently restricted to `[a-z][a-z0-9_-]*` and have always rejected `[`.

## Consequences

- ADR 0005's Phase 2 deferral list is, in practice, fully closed (with `types:` shipping as
  `relations:`). The deferral note is preserved unchanged in ADR 0005 for historical fidelity;
  E1 above is the authoritative current state.
- ADR 0007's locked design decisions remain in force. E2–E4 clarify wording but do not change the
  shipped surface.
- Future ADRs that build on 0005 or 0007 should cite this errata alongside the originals when
  the corrected passages are load-bearing.

## References

- Phase 1 design: [ADR 0005](0005-architecture-rules.md)
- Declaration-order pivot: [ADR 0006](0006-architecture-rules-declaration-order.md)
- Phase 2 design: [ADR 0007](0007-architecture-rules-phase-2-design.md)
- M17 implementation: `src/Architecture/Domain/Allow/LayerSelectorParser.php`
- `relations:` implementation: `src/Architecture/Configuration/Allow/AllowAliasExpander.php`
- Diagnostic severities: `src/Architecture/Rules/LayerViolationRule.php`, `tests/Architecture/`
