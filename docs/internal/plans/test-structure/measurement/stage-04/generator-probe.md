# What the generator says about stage 04's own end state

Measured, not reasoned: `scripts/generate-modular-architecture-test-inventory.php`
carries a `--classification-probe=<path>` mode that prints
`owner \t closure_package \t current_suite \t target_path` for any path, existing or not.
Six post-move paths from `relocation-map.csv` were fed to it on `main` @ e15c7f42.

| Probed post-move path                                                              | owner it returns               | target_path it returns                           | verdict                                                                            |
| ---------------------------------------------------------------------------------- | ------------------------------ | ------------------------------------------------ | ---------------------------------------------------------------------------------- |
| `tests/Infrastructure/Git/Unit/GitClientTest.php`                                  | `Infrastructure`               | `tests/Infrastructure/Unit/GitClientTest.php`    | **wrong** — says move back to the flat bucket                                      |
| `tests/Infrastructure/Console/Unit/ApplicationTest.php`                            | `Infrastructure`               | `tests/Infrastructure/Unit/ApplicationTest.php`  | **wrong** — same                                                                   |
| `tests/Infrastructure/Rule/Unit/RuleRegistryTest.php`                              | `Infrastructure`               | `tests/Infrastructure/Unit/RuleRegistryTest.php` | **wrong** — same                                                                   |
| `tests/Reporting/Unit/Formatter/Html/HtmlFormatterTest.php`                        | `Reporting`                    | `tests/Reporting/Unit/HtmlFormatterTest.php`     | **wrong** — drops the `Formatter/Html` remainder                                   |
| `tests/Core/Unit/VersionTest.php`                                                  | `Core/Neutral`                 | `tests/Core/Neutral/none/VersionTest.php`        | **wrong** — owner name is not a namespace, and the suite classifier returns `none` |
| `tests/Analysis/Policy/Architecture/Integration/MaxExpandedLayersFromYamlTest.php` | `Analysis/Policy/Architecture` | unchanged                                        | correct                                                                            |

Reproduce any row with:

```
php scripts/generate-modular-architecture-test-inventory.php --classification-probe=<path>
```

## What this settles

The generator rewrite is not bookkeeping that can follow the moves. Five of six probed
end-state paths are classified wrong today, and four of them **silently**: the generator
regenerates its own artifact and `composer architecture:check` compares that artifact to
the regeneration, so a wrong `target_path` is self-consistent and stays green. Only the
`tests/Core/Unit` row fails loudly, and only because `currentSuite()` returns `none`,
which `assertSuiteClassifierAgreesWithPhpunit()` refuses.

So the ordering is forced: the owner derivation is rewritten **before or with** the first
move, never after. A stage that moved first would leave a green tree whose own inventory
says every moved file is misplaced.

## What this probe cannot see

It answers for the paths it is given, one at a time. It is not a sweep: nothing here
proves the other 108 rows classify correctly, only that the rule producing these five is
the shared one (`classifyOwner()`'s prefix ladder plus `targetPath()`'s
`tests/{owner}/{suite}/{basename}` template, which has no remainder segment at all).
