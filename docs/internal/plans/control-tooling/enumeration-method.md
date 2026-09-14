# How the control rig was enumerated, and what the method does not see

Artifacts: `enumeration-tools.tsv` (122 code files, 47 947 LOC),
`enumeration-commands.tsv` (57 composer commands, 23 of them standing inside
`composer check`), plus 232 tracked data files under `finding-gate/`,
`promise-effect/`, `input-doors/`, `directive-audit/`.

## Channels covered

| channel                   | how                                                            |
| ------------------------- | -------------------------------------------------------------- |
| composer command → script | regex over every `scripts/...` path in `composer.json` scripts |
| standing status           | transitive expansion of `@`-references from `check`            |
| literal `require` graph   | `require __DIR__ . '...'` inside every tracked `scripts/*.php` |
| dynamic autoloader        | `__DIR__ . '/<dir>/' . $class` promotes the whole directory    |
| git hooks                 | `scripts/...` and `composer <cmd>` in `.githooks/*`            |
| CI                        | the same, over `.github/workflows/*.yml`                       |
| tests                     | literal `scripts/...` strings anywhere under `tests/`          |
| canon                     | basename present in `AGENTS.md`                                |

## What this method does not see

Named, because an enumeration whose blind spots are unnamed cannot be argued with:

- **Paths built by concatenation.** `Path(...) / "scripts" / "x.py"` in a test does
  not contain the literal string. This produced one false orphan
  (`cross-tool-comparison.py`, really consumed by
  `tests/Analysis/Evidence/Measurement/Tests/test_cross_tool_comparison.py`).
- **Consumers outside the repository's own config.** `init-environment.sh` is invoked
  by a SessionStart hook in `.claude/`, which no channel above reads. Second false orphan.
- **Reference from prose only.** A script named in a code comment or a doc but never
  invoked reads as unreachable and *is* unreachable in the executable sense — but the
  comment makes it look owned. Two cases: `enumerate-refusal-fallback.php`,
  `enumerate-rule-option-keys.php`.
- **Python imports.** Not walked; only literal path strings.
- **`benchmarks/`** has its own `composer.json` and is outside this enumeration.
- **`bin/qmx` product subcommands that are control-shaped** (`qmx directives`,
  `baseline:explain`) ship inside `src/` and are counted as product, not rig.
- The **instrument/control split** used in prose (13 472 LOC second-order) is a manual
  reading of file names, not a measurement. `promise-effect-p1-set.php` calls itself a
  regression guard and is *not* in that sum; some guards live inside instrument classes.
  Treat the figure as an order of magnitude, not as a ratchet baseline.

## Verdicts on the six files no executable channel reaches

| file                             | verdict                                                         |
| -------------------------------- | --------------------------------------------------------------- |
| `init-environment.sh`            | false orphan — SessionStart hook; dev-environment glue, not rig |
| `cross-tool-comparison.py`       | false orphan — consumed by a test via a built path              |
| `enumerate-refusal-fallback.php` | one-shot measurement, named only in a comment                   |
| `enumerate-rule-option-keys.php` | one-shot measurement, named only in prose                       |
| `check-review-disposition.sh`    | orphan                                                          |
| `x8-overlap-sites.php`           | orphan, carries a round number in its name                      |
