# Stage 03 — tooling tests move to the code they test

## What these are, and why they are not controls

Eight files under `tests/Unit/PromiseEffect/` and `tests/Unit/RuleVocabulary/`
test behaviour — of classes that live in `scripts/`, not `src/`:

| Test location                    | SUT namespace                                        | SUT location                |
| -------------------------------- | ---------------------------------------------------- | --------------------------- |
| `tests/Unit/PromiseEffect/` (3)  | `Qualimetrix\PromiseEffect\*`                        | `scripts/promise-effect/`   |
| `tests/Unit/RuleVocabulary/` (5) | `QmxDirectiveAudit\*`, `QmxDirectiveAuditControls\*` | `scripts/directive-audit*/` |

They are ordinary unit tests. They assert what a class returns for given input;
they do not inspect the repository. Putting them in `governance/` with the
controls would group by role — "things that are not src tests" — and ADR 0016
rejects exactly that. Per D3 they go to their subject: next to the code.

**They are invisible to import-based enumeration.** `Qualimetrix\PromiseEffect`
begins with the production prefix, and `composer.json` maps `Qualimetrix\` to
`src/` only, so the namespace looks productive while the code is not there. Two
of the five `RuleVocabulary` files pull their SUT via `require_once` rather than
`use`, so a `use`-based scan sees no SUT at all. Both properties are why the
mechanical witness counted 6 where reading found 8. Any future sweep over this
set must enumerate by `require_once` as well as by `use`.

## Placement

Tests live beside the tool they test, under the tool's own directory, because
the tool is the subject:

```
scripts/promise-effect/          # existing code
scripts/promise-effect/tests/    # its tests
scripts/directive-audit/tests/   # likewise
```

The alternative — one `tools-tests/` root — was rejected on the same ADR 0016
grounds as `controls/`: it names a role and would collect one file per tool.

**Assumption to verify before moving, not after:** these classes are currently
autoloaded neither by `autoload` nor `autoload-dev` — the tests reach them by
`require_once`. Establish how each is loaded today and keep that working, or
give the tools a PSR-4 prefix of their own. If a prefix is added, it is a new
public surface for `scripts/`, which is a decision to state, not a mechanical
step.

## Definition of Done

- The eight files are out of `tests/`, and their SUTs resolve from the new
  location — proved by running them, not by reading the config.
- `phpstan.neon:32`'s ignore for
  `tests/Unit/RuleVocabulary/Fixtures/AuthoredThresholdForms.php` points at the
  fixture's new path, or is removed because it no longer applies.
- G2 reports zero orphans; the moved tests appear in the executed-test count of
  whichever suite now owns them, and that count is stated.
- `tests/Unit/` contains no `PromiseEffect/` or `RuleVocabulary/` remnant, and
  `phpunit.xml.dist` no longer names directories that do not exist.

## Files

`tests/Unit/PromiseEffect/**`, `tests/Unit/RuleVocabulary/**`,
`scripts/promise-effect/**`, `scripts/directive-audit*/**`, `phpunit.xml.dist`,
`phpstan.neon`, `composer.json`.
