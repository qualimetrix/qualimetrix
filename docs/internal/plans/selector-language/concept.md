# Concept: Explicit selector language

**Task:** -

## Goal

Replace the product's inconsistent glob, implicit-prefix, Finder-exclude, and private namespace-prefix matchers with one explicit regular-expression contract wherever a user selects from an open universe of paths or PHP names. The change must preserve loud validation, binding diagnostics, attribution, and deterministic matching across configuration, CLI, analysis, and reporting.

Exact identifiers, typed allow-lists, and architecture capture bindings remain explicit exceptions.

## Where the current tree differs from the request

- `src/Core/Pattern/PathMatcher.php` and `NamespaceMatcher.php` already centralize most, but not all, open-universe matching. They choose implicit subtree-prefix or `fnmatch()` from the presence of `*?[`.
- `exclude` is not a glob: Symfony Finder matches either an exact directory basename or an exact path-segment sequence. `Analysis/Run/ExcludeBinding/ExcludeBindingProbe.php` deliberately mirrors that contract.
- `graph:export` namespace filters and `coupling.framework_namespaces` bypass Core and implement private boundary-prefix matching.
- Architecture has two additional languages. Layer membership adds capture templates to the common FQN glob; `architecture.allow` transfers source-side capture bindings into target selectors.
- The current architecture dialects already disagree: `*` crosses namespace separators in `fnmatch()`, but is segment-local in `CapturePattern`; `[` is active in one and literal or refused in the others.
- Unbound-suppression diagnostics infer a configured glob's filesystem location from its literal head. Arbitrary regex has no generally computable literal head, so that diagnostic cannot retain its current partial-run judgement unchanged.

## Principle

**Use one explicit `exact | subtree | regex` selector for every open path/name universe, render every form to the same full-subject PCRE predicate, and keep domain selectors whose value carries identity or bindings as separate fail-closed languages.**

- YAML list items are one-entry mappings: `exact: value`, `subtree: value`, or `regex: fragment`. CLI values use the corresponding `exact:value`, `subtree:value`, or `regex:fragment` spelling and split only on the first colon. Bare strings are refused; punctuation never chooses a language implicitly.
- `exact` quotes the authored value. `subtree` matches that value itself and separator-bound descendants (`/` for project-relative paths, `\` for PHP names). `regex` is a delimiterless PCRE fragment. The product renders every form with fixed `\A(?:...)\z` anchoring, so substring matching is never accidental.
- `~` is reserved as the internal PHP pattern delimiter in `regex` fragments: any raw U+007E byte is refused there, and an author who needs a literal tilde writes `\x7E`. It remains an ordinary character in `exact` and `subtree`. A string resembling a delimited PHP pattern has no special treatment — inside `regex:` it is a fragment containing those literal characters. Scoped inline option groups remain available when deliberately needed.
- Selector values are validated at configuration resolution and rendered once when bound to a path or PHP-name separator. The separator-bound Core Pattern value is reused for application, audit, attribution, and binding checks. PHP receives the same rendered pattern string on every match and can reuse its process-local PCRE cache; the design does not claim that PHP exposes or stores a compiled-regex object.
- `\A...\z` is defense in depth, not the proof of full-subject matching: PCRE control verbs such as `(*ACCEPT)` can bypass a trailing anchor and `\K` can alter the reported start. A match counts only when the reported whole-match span starts at byte zero and covers every subject byte. Compile-time errors are configuration refusals; runtime match/resource failures are controlled user-selector failures with the authored form and PCRE diagnostic, never silent non-matches or internal-invariant claims.
- The common contract covers global and per-rule path/namespace suppression, `suppress_namespace_channels` values, report drill-down, graph namespace filters, `coupling.distance.include_namespaces`, `coupling.framework_namespaces`, and discovery `exclude`. Discovery gets a Run-owned regex directory pruner so application and unmatched-exclude probing share one predicate.
- Rule/channel selection (`X`, `X.*`, optional level), exact rule ownership, health dimensions, class drill-down, baseline entry handles, and rule-specific exact/prefix lists stay typed and exact. Regex there would turn reviewable identifiers into potentially broad policy edits.
- Architecture layer membership and allow capture selectors remain an Architecture-owned DSL. They require tuple extraction, substitution, declaration-order reasoning, and cross-selector bindings that a generic boolean regex matcher cannot own. Their glob discrepancies are corrected and documented in a separate Architecture package rather than hidden under the Core contract.

**Alternatives:** Raw delimited PCRE was rejected because path delimiters and modifiers become another user grammar and validation surface. Keeping implicit literal/prefix fallback beside regex was rejected because the same string would still change language by punctuation. Translating existing globs to internal regex only was rejected because it improves speed but not expressiveness or consistency.

## Structure

| Object                      | Role and contact with the rest of the product                                                                                                                                         |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core selector values        | A neutral authored definition plus immutable separator-bound path/name patterns; these validate and render the full-subject predicate and preserve the authored form for attribution. |
| Path/name matcher sets      | Changes existing Core matchers to ordered sets of separator-bound patterns; all current consumers keep first-match attribution.                                                       |
| Run directory pruner        | Changes discovery to apply the same regex contract to project-relative directory paths and to measure unmatched exclusions without mirroring Symfony's private grammar.               |
| Architecture selector DSL   | Existing subject-owned exception: retains bindings and declaration semantics; removes false equivalence claims and rejects ambiguous/dead forms.                                      |
| Selector-surface governance | New exhaustive registry/control tying every public selector door to a declared language and preventing another private matcher from appearing.                                        |

Flow: explicit authored form -> owning resolver validates -> path/name binding renders once -> typed matcher is shared by application and audit -> match attribution keeps the authored form and value -> malformed/runtime PCRE state fails as a controlled user error.

## Contracts and data

Every affected public YAML key and CLI option changes shape and semantics; there is no legacy fallback. For example:

```yaml
suppress_namespaces:
  - exact: 'App\Entity\User'
  - subtree: 'App\Entity'
  - regex: 'App\\(?:Entity|Dto)(?:\\[^\\]+)*'
```

```text
--suppress-namespace='subtree:App\Entity'
--suppress-namespace='regex:App\\(?:Entity|Dto)(?:\\[^\\]+)*'
```

The migration updates `CHANGELOG.md`, adds an ADR, updates English and Russian documentation, rewrites examples/presets/dogfood configuration, and explains baseline review after suppression changes.

The regex subject is canonical and explicit: project-relative paths use `/`; namespaces/FQCNs have no leading or trailing `\`; discovery directories are project-relative and use `/`. Configuration retains authored strings for diagnostics while runtime owners receive validated regex values.

**Introduces:** a neutral Core regex value, a Run-owned pruning adapter, selector-language governance, and a migration inventory. No external dependency is required; PHP PCRE is already mandatory at runtime.

## Risks

This is intentionally breaking and can reveal or hide findings until configurations are migrated. Arbitrary PCRE is trusted repository configuration but can still consume excessive CPU; the implementation must derive and enforce fragment/count and PCRE match/depth budgets, surface `preg_last_error_msg()`, and benchmark warm/cold matching over realistic `candidates x patterns` rather than assume PCRE is always faster.

Full regex makes static location inference unsound. Unbound suppression values will therefore be judged only against a complete relevant universe; partial runs stay silent instead of guessing from regex syntax.

## Accepted decisions

- Public open-universe selectors use explicit `exact`, `subtree`, or `regex` forms; there is no bare-string fallback and no punctuation heuristic.
- Regex fragments are delimiterless and automatically full-anchored, with full-span verification; delimited-looking text is interpreted literally as fragment content rather than as a second PHP-pattern grammar.
- Architecture capture selectors remain a documented DSL exception in this campaign; only their existing contradictions and dead forms are repaired.
