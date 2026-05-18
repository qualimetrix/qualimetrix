# PHPStan Custom Rules

Custom rules used to enforce architectural invariants beyond what level-8 phpstan
catches. Currently houses the banned-string-path pair introduced by
[ADR 0015](../../../docs/adr/0015-relative-path-vo.md):

- `PathPropertyMatcher` — shared decision helper (forbidden names, types, scoped namespaces).
- `BannedStringPathPropertyRule` — checks `Node\Stmt\Property` declarations.
- `BannedStringPathPromotedPropertyRule` — checks promoted constructor properties
  (`Node\Param` with `flags !== 0`), which the plain Property rule cannot see.

The rules are committed but **not yet wired into `phpstan.neon`** — the wiring
ships with the last phase of the RelativePath VO migration, once existing
`string $file|$filePath|$oldPath` declarations across `Core`, `Analysis`,
`Reporting`, `Baseline`, and the relevant `Infrastructure` subtrees have been
converted to the typed VOs.
