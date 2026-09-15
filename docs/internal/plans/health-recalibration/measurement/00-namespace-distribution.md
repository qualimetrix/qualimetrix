# The namespace level, which no guard watches

From `scripts/collect-benchmark-data.php` on `main` @ `55297007`. The collector
already records per-project distributions of every health dimension over that
project's namespaces; `benchmark-regression.php` reads none of them.

`health.overall` over namespaces, and the median namespace for two dimensions:

| project                 | ns  | p10  | p50  | p90  | max   | maint p50 | cplng p50 |
| ----------------------- | --- | ---- | ---- | ---- | ----- | --------- | --------- |
| symfony-console         | 29  | 84.6 | 89.9 | 96.9 | 99.6  | 98.3      | 90.0      |
| symfony-di              | 17  | 68.2 | 83.7 | 96.1 | 96.2  | 90.0      | 86.7      |
| symfony-http-foundation | 16  | 89.1 | 92.1 | 95.4 | 97.1  | 100.0     | 95.3      |
| symfony-http-kernel     | 22  | 79.7 | 86.1 | 94.1 | 98.0  | 89.1      | 90.3      |
| symfony-routing         | 15  | 72.1 | 81.6 | 93.6 | 94.3  | 80.3      | 93.2      |
| phpunit                 | 59  | 81.8 | 87.5 | 96.2 | 99.5  | 100.0     | 85.7      |
| php-parser              | 20  | 83.0 | 88.8 | 93.1 | 96.4  | 100.0     | 81.2      |
| doctrine-orm            | 47  | 79.1 | 92.1 | 96.0 | 98.9  | 94.3      | 90.8      |
| doctrine-dbal           | 88  | 81.0 | 92.1 | 97.1 | 100.0 | 100.0     | 92.3      |
| flysystem               | 4   | 91.5 | 92.1 | 96.1 | 96.1  | 100.0     | 92.7      |
| composer                | 35  | 56.7 | 78.6 | 92.1 | 94.8  | 73.1      | 86.0      |
| monolog                 | 10  | 80.2 | 90.1 | 97.1 | 97.1  | 93.1      | 91.3      |
| guzzle                  | 5   | 79.6 | 87.2 | 89.7 | 89.7  | 86.1      | 95.1      |
| laravel-framework       | 209 | 77.9 | 84.7 | 90.5 | 95.5  | 100.0     | 92.3      |
| qmx                     | 175 | 83.3 | 91.9 | 95.9 | 98.0  | 98.5      | 91.3      |

## What it says

The project level is the *mild* case. At namespace level:

- The median namespace of every project in the corpus scores 78.6 or above on
  `health.overall`. Twelve of fifteen medians are above 84 — the "Excellent"
  band.
- The tenth percentile is above 68 for thirteen of fifteen projects. Ninety
  percent of all namespaces in the corpus are "Good" or better.
- `health.maintainability` has a **median** of exactly 100.0 in five projects.
  Not a maximum — a median: more than half the namespaces of doctrine-dbal,
  php-parser, phpunit, flysystem and laravel score a perfect maintainability.
- `health.coupling` medians sit in a 14-point band (81.2 to 95.3) across
  fifteen projects of very different structure.

Namespace is the level a user drills into to find where the problem is. At that
level the score is close to constant, which makes it useless for the job
`PRODUCT_VISION.md` principle 9 gives it — "the default answer states where the
problems are".

## Consequence

Two things the plan must carry:

1. Namespace-level saturation is a first-class target of the recalibration, not
   a side effect of fixing the project level. It is the worse of the two.
2. The data to watch it already exists in the collector and is simply not read
   by the guard. The P0 snapshot should cover namespace as well as class, and
   it is cheaper than P0 assumed — the collection side is done.
