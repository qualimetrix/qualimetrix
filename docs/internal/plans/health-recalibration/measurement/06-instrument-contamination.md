# The contaminated instrument, measured rather than argued

Both round-1 reviewers reported, independently, that
`scripts/collect-benchmark-data.php` runs `bin/qmx` through `exec()` with the
repository root as its working directory, so every benchmark project was
analysed under this repository's own `qmx.yaml` — while
`scripts/benchmark-regression.php` deliberately uses a neutral directory. Both
rated it HIGH, and the conclusion drawn was that the two instruments "do not
measure the same thing".

The first half is true and is fixed. The second half was not measured by either
reviewer, so it was measured here.

## The measurement

`symfony/console`, `--format=metrics --workers=0`, same binary, same revision,
once from the repository root and once from an empty directory:

| working directory | complexity | cohesion | coupling | typing | maintainability | overall | symbols |
| ----------------- | ---------- | -------- | -------- | ------ | --------------- | ------- | ------- |
| repository root   | 82.7       | 81.1     | 68.9     | 98.5   | 90.1            | 82.7    | 1358    |
| neutral           | 82.7       | 81.1     | 68.9     | 98.5   | 90.1            | 82.7    | 1358    |

Identical, to the decimal, including the symbol count.

## What this means

The leak is real; its effect on health numbers, on this project, is zero.
`qmx.yaml` in this repository carries a memory limit, architecture layers,
coupling framework namespaces and suppression paths — inputs to *findings* and
to *resources*, not to the metric values the health formulas consume. The
guard's own comment says as much: the damage it names is a doctrine-dbal OOM
caused by the inherited `memory_limit`, not a moved score.

The fix is kept — an instrument that reads a configuration that is not its
subject's is wrong regardless of whether today's numbers happen to survive it,
and rules other than the health dimensions may well be affected. But the claim
that the collector and the guard were producing different health numbers is
withdrawn: after the fix, the collector's project scores for `symfony-console`
are 82.7 / 81.1 / 68.9 / 90.1 / 82.7, and the guard's published table before any
of this work says the same.

Two reviewers agreeing is a reason to measure, not a measurement.
