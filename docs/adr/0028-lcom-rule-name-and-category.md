# 0028. LCOM Rule Name and Category

**Date:** 2026-08-21
**Status:** Accepted

## Context

The Lack of Cohesion of Methods rule was registered as `design.lcom`, but every
other coordinate of the class disagreed with that name: the class lives at
`src/Analysis/Evidence/Cohesion/LcomRule.php`, its collector and options sit in
the same `Cohesion` capability, and its documentation page is
`website/docs/rules/cohesion.md`, titled "Cohesion Rules".

The mismatch was not cosmetic. `RuleCategory` used to double as the group a
selector or directive addresses, deriving group membership from the first
dot-separated segment of a rule's name (see ADR 0024, which severed that
coupling for category itself but left rule *names* as the de facto group
address for `--only-rule` / `--disable-rule` wildcards and for `qmx rules
--group`). Because the class was named `design.lcom`:

- `--only-rule='cohesion.*'` matched nothing — rejected with "does not match
  any registered producer, group, or channel" — so the Cohesion capability had
  no group address at all, even though `website/docs/llms.txt` already
  advertised `cohesion` as a rule group.
- `--only-rule='design.*'` swept LCOM in together with the unrelated Design
  rules (inheritance depth, NOC, type coverage, data class, god class),
  because the wildcard matches on the name's leading segment, not on the
  class's actual capability.
- `website/docs/rules/index.md` (and its Russian mirror) listed LCOM in the
  "Design Rules" table linking to `design.md`, and separately asserted
  "Cohesion (metrics only, no rule)" for TCC/LCC — both statements were already
  wrong before this rename, and would have become self-contradictory after it
  if left unchanged.

## Decision

Rename the rule from `design.lcom` to `cohesion.lcom`, and change
`LcomRule::getCategory()` from `RuleCategory::Design` to a new
`RuleCategory::Cohesion` case.

The rename is purely nominal — the rule's algorithm, defaults, options, and
CLI aliases are unchanged. It touches every surface that copies the channel ID
literally (config, presets, tests, live documentation) and, separately, the
handful of prose claims that named the old category or the old documentation
page without repeating the literal (`website/docs/rules/index.md` lines
describing the Design/Cohesion split, `src/Core/README.md`'s `RuleCategory`
table).

`docs/adr/**` and completed plans under `docs/internal/plans/` are dated
records of what was decided when; they are not rewritten. **ADR 0025's single
mention of `design.lcom` is deliberately left as-is** — that example
illustrates a point about which *level* a channel selector addresses, not
about spelling, and rewriting an accepted ADR to match a later rename would
misrepresent what was decided at the time it was written.

## Consequences

- `cohesion.*` is now a valid group address for `--only-rule` /
  `--disable-rule`, matching only `cohesion.lcom` today.
- `design.*` no longer selects or excludes LCOM; a consumer relying on that
  incidental sweep must add `cohesion.lcom` (or `cohesion.*`) explicitly.
- `qmx rules --group` now lists LCOM under `Cohesion` instead of `Design`.
- Any consumer's own `qmx.yaml` rule key, `@qmx-ignore` / `@qmx-threshold`
  directive, or baseline entry naming `design.lcom` must be updated to
  `cohesion.lcom`. A stale baseline entry for the old channel does not fail
  the run — it degrades through `InertEntryReason::UndeclaredChannel`, the
  same fail-safe mechanism ADR 0017 established for any channel a baseline
  entry no longer resolves to.
- This project's own `qmx-baseline.json` held zero `design.lcom` entries at
  the time of the rename (verified by direct grep before the change), so no
  ratchet entry needed migration.
