# PHPStan Custom Rules

Custom rules used to enforce architectural invariants beyond what level-8 phpstan
catches. Currently houses the banned-string-path pair introduced by
[ADR 0015](../../../docs/adr/0015-relative-path-vo.md):

- `PathPropertyMatcher` — shared decision helper (forbidden names, types, scoped namespaces).
- `BannedStringPathPropertyRule` — checks `Node\Stmt\Property` declarations.
- `BannedStringPathPromotedPropertyRule` — checks promoted constructor properties
  (`Node\Param` with `flags !== 0`), which the plain Property rule cannot see.

Wired into `phpstan.neon` as part of ADR 0015 Phase 6 — the rules now run on
every PHPStan invocation under the identifiers
`qmx.bannedStringPathProperty` and `qmx.bannedStringPathPromotedProperty`,
guarding against regression of typed-path properties in
`Core`, `Analysis`, `Reporting`, `Baseline`, and the relevant
`Infrastructure` subtrees.
