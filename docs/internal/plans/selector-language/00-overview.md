# One explicit selector language for open path and PHP-name universes

**Status:** planned and reviewed in three rounds; implementation authorized.

**Base:** `main` at `2deb90479400` (`fix: close reviewed regression paths (#149)`).

## Outcome

Every user-facing selector over an open universe of project-relative paths or
PHP names uses an explicit discriminated form:

- `exact` — one complete subject;
- `subtree` — that subject and separator-bound descendants;
- `regex` — an automatically full-anchored PCRE fragment.

YAML uses one-entry mappings; CLI uses `kind:value`. Bare strings are refused.
The form is never guessed from punctuation. Every form is rendered once after it
is bound to a path/name separator, and the separator-bound predicate plus authored
form/value survive for diagnostics and first-match attribution.

Closed identities remain closed: rule/channel selectors, baseline handles,
health dimensions, class drill-down, analysis roots, and rule-specific semantic
allow-lists do not acquire regex. Architecture capture selectors remain an
Architecture-owned binding DSL and are made internally consistent rather than
forced through a boolean Core matcher.

The approved concept is in [`concept.md`](concept.md). The exhaustive source,
configuration, CLI, test, governance, and documentation sweep is recorded in
[`measurement/selector-surfaces.tsv`](measurement/selector-surfaces.tsv).

## Public forms

YAML list:

```yaml
suppress_namespaces:
  - exact: 'App\Entity\User'
  - subtree: 'App\Entity'
  - regex: 'App\\(?:Entity|Dto)(?:\\[^\\]+)*'
```

CLI scalar:

```text
--suppress-namespace='subtree:App\Entity'
--suppress-namespace='regex:App\\(?:Entity|Dto)(?:\\[^\\]+)*'
```

The CLI parser splits on the first colon only. `regex` values are delimiterless,
receive `\A(?:...)\z`, and cannot contain a raw U+007E byte; a literal tilde is
written `\x7E`. Text resembling `/pattern/modifiers` remains ordinary fragment
content — it does not open a second raw-PHP-pattern grammar.

## Non-negotiable invariants

1. A malformed selector is a configuration/input refusal before discovery or
   analysis begins. A valid selector that exhausts a PCRE match/depth/JIT budget
   on a concrete subject is a controlled user-selector failure naming the
   authored selector and `preg_last_error_msg()`, never a non-match or an
   internal-invariant failure.
2. Paths are project-relative with `/`; PHP names have no leading/trailing `\`.
   `subtree` uses the subject's separator and therefore cannot match a sibling
   with the same textual prefix.
3. Ordered selector sets preserve first-match attribution. Application, inert
   diagnostics, and unmatched-binding diagnostics consume the same typed value
   and predicate.
4. Exact and subtree forms are quoted before rendering. Regex fragments are not
   rewritten to escape the delimiter; the raw-byte delimiter refusal keeps the
   PCRE grammar honest. A successful result counts only when the whole-match span
   is `(offset=0, length=strlen(subject))`; anchors alone do not prove this because
   `(*ACCEPT)` and `\K` can alter control flow/span.
5. Regex resource limits and authored length/count limits are derived by the P0
   measurement before their constants land. The gate must leave existing
   migrated repository configuration comfortably below the limit and bound an
   adversarial match independently of process-global php.ini.
6. The campaign does not claim that regex is always faster. The measured local
   sample made wildcard PCRE about 1.7 times faster than `fnmatch`, while the
   current simple-prefix branch was about 9 percent faster than PCRE. The reason
   to migrate is one explicit expressive contract; performance must not regress
   materially on the representative matrix.

## Ownership

- `Core/Pattern` owns the neutral authored selector definition, immutable
  separator-bound path/name patterns, validation/rendering, and ordered matcher
  sets. It knows separators, not YAML, CLI, finding, discovery, or architecture
  configuration.
- Configuration-owning adapters convert YAML one-entry mappings and CLI
  `kind:value` scalars into Core selector definitions and map neutral failures to
  position-aware `ConfigurationRefusal` or input refusal.
- Finding and Reporting carry typed selectors across their existing boundaries;
  they do not parse or reconstruct predicates.
- Run owns directory pruning because pruning is discovery policy and must expose
  the exact same decision to unmatched-exclude auditing.
- Architecture owns its capture/binding DSL because matching there produces
  tuples and cross-selector bindings, not only a boolean.

## Stages and gates

| Stage                         | Document                                                               | Gate                                                                                                     |
| ----------------------------- | ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| 1. Common contract            | [`01-common-contract.md`](01-common-contract.md)                       | Neutral types, ingress forms, refusal/resource contract, and representative benchmark are green          |
| 2. Product consumers          | [`02-product-consumers.md`](02-product-consumers.md)                   | Suppression, drill-down, graph, and coupling consumers use typed selectors with application/audit parity |
| 3. Discovery and Architecture | [`03-discovery-and-architecture.md`](03-discovery-and-architecture.md) | Run prunes with the shared predicate; Architecture DSL contradictions are closed without losing captures |
| 4. Migration and closure      | [`04-migration-and-closure.md`](04-migration-and-closure.md)           | Repository configs/docs migrate, governance inventory is authoritative, full validation and review pass  |

Stages are sequential because each consumes the preceding contract. Only P6 and
P7 are parallel: they have disjoint production/test/doc file sets.

## Definition of done

- Every row marked for migration in the selector-surface inventory accepts only
  the explicit forms and reaches the same Core predicate.
- Every retained exception has a named owner, grammar, refusal tests, and user
  documentation; none is merely an unvisited string list.
- No production `fnmatch()` remains on a user selector path. Remaining uses, if
  any, are enumerated as build/tooling behavior outside the product contract.
- The repository's own configuration, examples, presets, fixtures, English and
  Russian website pages, CLI help, component READMEs, ADR, and `CHANGELOG.md`
  describe the same syntax.
- Selector application, first-match attribution, inert reporting, unmatched
  diagnostics, partial-run silence, discovery pruning, and Architecture capture
  bindings have regression coverage.
- `composer check`, focused performance/resource probes, `git diff --check`, and
  mandatory code review pass with every finding either fixed or explicitly
  rejected with evidence.
