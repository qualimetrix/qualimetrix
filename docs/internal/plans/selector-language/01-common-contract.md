# Stage 1 — common selector contract and ingress

## Contract

Core separates two phases. `SelectorDefinition` is the neutral authored kind plus
non-empty value. `PathPattern` and `NamespacePattern` are immutable,
separator-bound values built once from a definition; they expose the authored
pair for diagnostics, a stable display spelling, and the rendered predicate.
None parses YAML/CLI or throws configuration-layer exceptions.

Rendering rules:

| Kind      | Rendered body                                                                                                    |
| --------- | ---------------------------------------------------------------------------------------------------------------- |
| `exact`   | `preg_quote(value, '~')`                                                                                         |
| `subtree` | quoted value followed by an optional separator plus one-or-more remaining characters; the exact root is included |
| `regex`   | fragment unchanged after syntax, delimiter, length, and resource validation                                      |

The body is wrapped in fixed resource verbs selected by P0 and
`~\A(?:BODY)\z~`. A positive result is accepted only when
`PREG_OFFSET_CAPTURE` reports byte offset zero and a whole-match byte length
equal to `strlen(subject)`; this rejects partial success through `(*ACCEPT)`,
`\K`, or equivalent PCRE control flow. An empty value, unknown kind, any raw
U+007E byte in a regex fragment, invalid PCRE, or over-budget definition is
refused. Delimited-looking text is not guessed or refused specially. A NUL is
refused in every kind. Path/name normalizers reject wrong separators and
leading/trailing separators instead of silently trimming user input.

`PathMatcher` and `NamespaceMatcher` become ordered sets of their corresponding
separator-bound patterns. `PatternMatch` carries the bound pattern's definition
rather than only a normalized string, so diagnostics can render `kind:value`
without reconstructing provenance. The static string helpers and `GlobSyntax`
disappear; callers needing one predicate receive or build the typed bound pattern.
Runtime PCRE resource/JIT errors raise `SelectorMatchFailure`, a dedicated Core
subclass of `InvalidArgumentException` containing the authored definition and
PCRE diagnostic. This uses the existing documented secondary-refusal route in
Check, GraphExport, Directives, and Baseline command ladders (exit 3) without a
Core dependency on Console. Ingress wraps compile-time failures in its
position-aware `ConfigurationRefusal`; runtime failures cannot honestly recover
a discarded YAML line and therefore report the stable authored `kind:value`.

## P0 — derive budgets and performance guard

**Files:**

- `docs/internal/plans/selector-language/measurement/selector-budget.md`
- a temporary benchmark script under
  `docs/internal/plans/selector-language/measurement/` (removed or retained as a
  reproducible measurement according to review)

**Work:** enumerate migrated values in tracked YAML/fixtures; record maximum
fragment length and selectors per list; benchmark cold construction, warm match,
exact, subtree, alternation, character-class, adversarial nested-quantifier, and
anchor-bypass fragments over realistic `candidates x selectors`. Choose authored length/count
and `LIMIT_MATCH`/`LIMIT_DEPTH` values by recorded criteria, not intuition.

**Gate:** chosen budgets are at least one order of magnitude above the migrated
tracked maximum, the adversarial case terminates within the recorded bound, and
the representative matrix does not materially regress against current exact/
prefix/glob behavior. If PCRE inline limit verbs cannot bound the measured case,
P0 stops implementation and the concept returns to review.

## P1 — neutral Core values and matchers

**Production files:**

- add `src/Core/Pattern/SelectorKind.php`
- add `src/Core/Pattern/SelectorDefinition.php`
- add `src/Core/Pattern/PathPattern.php`
- add `src/Core/Pattern/NamespacePattern.php`
- add `src/Core/Pattern/SelectorMatchFailure.php`
- change `src/Core/Pattern/PatternMatch.php`
- change `src/Core/Pattern/PathMatcher.php`
- change `src/Core/Pattern/NamespaceMatcher.php`
- delete `src/Core/Pattern/GlobSyntax.php`
- update `src/Core/README.md`

**Test files:**

- add `tests/Core/Unit/Pattern/SelectorDefinitionTest.php`
- rewrite `tests/Core/Unit/Pattern/PathMatcherTest.php`
- rewrite `tests/Core/Unit/Pattern/NamespaceMatcherTest.php`
- delete `tests/Core/Unit/Pattern/GlobSyntaxTest.php`

**Cases:** exact metacharacters stay literal; subtree root and descendant match;
prefix sibling does not; path and namespace separators differ; full anchoring and
full-span refusal for `(*ACCEPT)`/`\K`; inline scoped options; first-match
attribution; duplicate definitions preserve the first authored occurrence;
empty/NUL/raw-U+007E/invalid/over-budget forms refuse (with no raw-delimited
heuristic); runtime PCRE resource failure is typed and controlled.

## P2 — configuration and CLI ingress

**Production files:**

- add an Analysis Configuration-owned YAML mapping decoder beside
  `ConfigSchema`, without exposing YAML representation from Core
- add an Infrastructure Console-owned CLI scalar decoder beside
  `ConfigurationInputAdapter`
- change `src/Analysis/Configuration/ConfigSchema.php` and relevant pipeline
  normalization so migrated list entries preserve their one-entry mappings
- change `src/Infrastructure/Console/ConfigurationInputAdapter.php`,
  `CheckConfigurationResolvers.php`, and command option readers that own migrated
  repeatable values
- change `src/Infrastructure/Console/CliOptionsParser.php` so the Finding-owned
  `RuleOptionsParser` continues to parse only `RULE:OPTION=VALUE`, after which the
  Console adapter applies its scalar selector decoder to the known
  selector-valued rule options before owner options are constructed
- update `src/Analysis/Configuration/README.md` and
  `src/Infrastructure/Console/README.md`

**Test files:** corresponding Unit/Integration tests under
`tests/Analysis/Configuration/` and `tests/Infrastructure/Console/`, plus
configuration-vocabulary governance fixtures whose asserted shape changes.

**Rules:** the YAML decoder accepts only a one-entry string-key mapping whose key
is one of the three kinds and whose value is a non-empty string. The CLI decoder
requires a known kind and colon, splitting only once. Both preserve source
position in their refusal, build the subject-specific bound pattern, and delegate
semantic PCRE validation to Core. `RuleOptionsParser` remains unaware of selector
syntax; `CliOptionsParser` converts only the known selector-valued results through
the same Console scalar decoder. Core factories accept already separated
kind/value and never depend on CLI spelling.

**Gate:** all malformed forms fail before file discovery; YAML and CLI render the
same definition; bare legacy strings fail with a migration-oriented message.
Integration cases force a runtime PCRE limit error through check, graph export,
directives, and one baseline command and assert their existing user-refusal route
and exit code rather than an internal-error envelope.

## Dependencies

P0 precedes P1 because limits are part of the contract. P1 precedes P2 because
ingress must construct the neutral type. No product consumer migrates in this
stage, so P2 may temporarily expose parsers only to focused tests until Stage 2.
