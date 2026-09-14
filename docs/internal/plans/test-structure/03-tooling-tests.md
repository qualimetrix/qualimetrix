# Stage 03 — tooling tests move to the code they test

## What these are

9 files (plus tooling methods inside 4 `mixed` files) test behaviour — of code
that is not `src/`:

| SUT namespace                                        | Lives in                    |
| ---------------------------------------------------- | --------------------------- |
| `Qualimetrix\PromiseEffect\*`                        | `scripts/promise-effect/`   |
| `QmxDirectiveAudit\*`, `QmxDirectiveAuditControls\*` | `scripts/directive-audit*/` |
| `Qualimetrix\PhpStan\Rules\*`                        | `tools/phpstan/`            |
| finding-gate readers                                 | `scripts/finding-gate/`     |

They are ordinary tests: they assert what a class returns for input the test
built. They are not controls. Filing them with the controls would group by role
— "things that are not src tests" — which ADR 0016 rejects; they go to their
subject, next to the code.

**The first draft said 8 files and named the class implicitly.** The re-ruling
made the criterion explicit ("the SUT is a repository tool") and the count moved
to 9 plus 4 partials — it gained `SuppressionSnapshotKeyTest`, which tests the
snapshot generator, and both `BannedStringPath*RuleTest`, which test the
project's custom PHPStan rules. A count derived from a criterion nobody wrote
down is not a count.

**They are invisible to import-based enumeration.** `Qualimetrix\PromiseEffect`
starts with the production prefix while `composer.json` maps `Qualimetrix\` to
`src/` only, and several files pull their SUT via `require_once` rather than
`use`. That is why the mechanical witness found 6 where reading found 8 and the
criterion now finds 9. Any future sweep must enumerate `require_once` as well.

## Placement

Tests live beside the tool, under the tool's own directory:

```
scripts/promise-effect/tests/
scripts/directive-audit/tests/
tools/phpstan/tests/
```

A single `tools-tests/` root was rejected on the same ADR 0016 grounds as
`controls/`: it names a role and collects one file per tool.

## The hazard review found: these files leave every guard's field of view

`scripts/` and `tools/` are outside `tests/` and outside the controls root, so
G2 and G3 from stage 01 do not scan them. **The first draft's DoD ("G2 reports
zero orphans") was satisfiable trivially — by the guard not seeing the files at
all** — while the eight tests silently stopped running forever. That is the
`ownerless files` failure this project has already paid for, reproduced by a
stage whose own DoD was green.

Therefore, before any file moves:

1. The guards' scanned roots must include `scripts/**/tests/` and
   `tools/**/tests/`. A root nobody scans is a root where tests go to die.
2. A PHPUnit suite must cover them, **and** `scripts/phpunit-aggregate.py:32`'s
   `SUITES` tuple must list it, or the aggregate never runs them.
3. Autoloading must be established. These classes are currently reached by
   `require_once`, not by `autoload` or `autoload-dev`. Either keep that working
   or give the tools a PSR-4 prefix — which is a new public surface for
   `scripts/`, a decision to state rather than a mechanical step.

`.php-cs-fixer.dist.php` and `phpstan.neon` already include `scripts`, so those
two registrations need nothing.

## Definition of Done

- The 9 files are out of `tests/`, and their SUTs resolve from the new location
  — proved by running them, not by reading the config.
- **The moved tests appear in the aggregate's executed-test count**, and that
  count is stated. This is the DoD item whose absence made the first draft
  unsafe.
- G2 and G3 scan the new roots; their scanned-root list is shown to include them.
- `phpstan.neon:32`'s ignore points at the fixture's new path, or is removed.
- `tests/Unit/` holds no `PromiseEffect/` or `RuleVocabulary/` remnant.
- `composer architecture:check` green (pinned paths), and `composer check`
  green.

## Ordering

This stage depends on stage 02: eight of these files also carry a
`repo-control`-adjacent history and appear in the controls verdict, and stage
02's DoD asserts where each verdict class ends up. Run 02 first, then 03, then
04 — the overview's dependency column says so.
