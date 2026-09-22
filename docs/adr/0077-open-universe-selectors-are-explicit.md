# 0077. Open-Universe Selectors Are Explicit

**Date:** 2026-09-21
**Status:** Accepted

## Context

Qualimetrix exposed several spellings for the same user intent. A path or PHP
name without `*`, `?`, or `[` was usually an implicit boundary prefix, while a
value containing one of those bytes was passed to `fnmatch()`. Discovery
exclusions used Symfony Finder's different basename/path rules, graph filters
and framework classification carried private prefix matchers, and Architecture
patterns added captures and bindings on top of another wildcard grammar.

The punctuation heuristic was hard to review and could not express alternatives
or grouping. It also made one authored value mean different things at different
doors. Replacing `fnmatch()` internally would improve one implementation but
would preserve that public ambiguity.

## Decision

Every public selector over an open universe of project-relative paths or PHP
names uses an explicit discriminated form:

- `exact` selects one complete subject;
- `subtree` selects that subject and separator-bound descendants;
- `regex` is a delimiterless PCRE fragment automatically wrapped in
  `\A(?:...)\z` and accepted only when the reported match spans the full
  subject.

YAML uses a one-entry mapping such as `- subtree: src/Generated`; CLI uses a
scalar such as `subtree:src/Generated` and splits on the first colon. Bare
strings are refused. Paths are canonical project-relative strings with `/`;
PHP names have no leading or trailing `\`. A raw `~` is forbidden in regex
fragments because it is the internal delimiter; authors use `\x7E` for a
literal tilde. Fragments and selector sets are bounded in length and count, and
each match applies local PCRE match/depth limits. Compile errors are input
refusals; match-time PCRE failures are controlled selector failures, never a
silent non-match.

Configuration and CLI adapters decode authored input into Core's neutral
`SelectorDefinition`, then bind it once as `PathPattern` or `NamespacePattern`.
Application, first-match attribution, suppression audit, unmatched diagnostics,
graph filtering, coupling classification, and discovery pruning reuse those
typed values. An arbitrary regex has no sound static location, so partial-run
unmatched diagnostics stay silent instead of inferring a prefix from syntax.

Closed identity sets do not gain regex: rule/channel selectors, inline
directives, baseline handles, health dimensions, class drill-down, analysis
roots, and rule-specific semantic allow-lists keep their existing fail-closed
grammars.

Architecture layer membership and allow selectors remain an
Architecture-owned binding DSL. They produce capture tuples and transfer
bindings between declarations, which a boolean Core matcher cannot represent.
That DSL is documented as an exception and rejects raw PCRE and character
classes; it is not accepted through the public `exact | subtree | regex`
shape.

## Consequences

This is a breaking configuration and CLI change. Existing literals become
`exact` or `subtree` according to intent; globs must be translated deliberately
to full-subject regex fragments. Consumer projects must migrate configuration
before upgrading and review the resulting suppression and discovery sets.

PCRE is not claimed to be universally faster. The benefit is one expressive,
explicit contract; simple prefix matching can remain cheaper in isolation.
Regex remains trusted repository configuration, with the limits above bounding
accidental resource use rather than promising that every authored expression is
efficient.

The versioned selector-surface registry under `governance/SelectorSyntax/`
names every public door, universe, owner, language, decoder, matcher, consumer,
and exception. Governance rejects new `fnmatch()` use and undeclared Core
pattern construction sites. This makes the finite reviewed surface explicit;
it does not pretend arbitrary future code can be classified by one grep.

Raw delimited PCRE was rejected because delimiters and modifiers create a
second grammar. Keeping literal/prefix fallback was rejected because
punctuation would still select semantics. A compatibility shim was rejected in
accordance with the project's backward-compatibility policy.
