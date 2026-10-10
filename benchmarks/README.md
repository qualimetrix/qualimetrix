# Benchmark Projects

PHP projects used for metric calibration and validation.

## Purpose

These projects serve as a reference corpus for:
- **Calibrating** health score formulas (target percentile distribution)
- **Validating** metric correctness across diverse codebases
- **Regression testing** formula changes against real-world data

## Project List

### Open-source (from `benchmarks/composer.json`)

| ID                      | Package                      | Description                      |
| ----------------------- | ---------------------------- | -------------------------------- |
| symfony-console         | symfony/console              | Symfony Console component        |
| symfony-di              | symfony/dependency-injection | Symfony DI component             |
| symfony-http-foundation | symfony/http-foundation      | Symfony HttpFoundation component |
| symfony-http-kernel     | symfony/http-kernel          | Symfony HttpKernel component     |
| symfony-routing         | symfony/routing              | Symfony Routing component        |
| phpunit                 | phpunit/phpunit              | PHPUnit testing framework        |
| php-parser              | nikic/php-parser             | PHP Parser by nikic              |
| doctrine-orm            | doctrine/orm                 | Doctrine ORM                     |
| doctrine-dbal           | doctrine/dbal                | Doctrine DBAL                    |
| flysystem               | league/flysystem             | Flysystem filesystem abstraction |
| composer                | composer/composer            | Composer package manager         |
| monolog                 | monolog/monolog              | Monolog logging library          |
| guzzle                  | guzzlehttp/guzzle            | Guzzle HTTP client               |
| laravel-framework       | laravel/framework            | Laravel Framework                |

### Legacy anchors

The libraries above are all top-decile code, so before these two were added the
whole corpus sat in the upper third of the 0-100 health scale and the declared
Poor and Critical bands were unreachable by anything real. A scale calibrated
only against excellent code cannot be checked for saying the right thing about
the rest. Both anchors are procedural, predate namespaces, and parse cleanly
under PHP 8.4.

| ID          | Package                   | Analysed path  |
| ----------- | ------------------------- | -------------- |
| codeigniter | codeigniter/framework     | `system/`      |
| wordpress   | johnpbloch/wordpress-core | `wp-includes/` |

`johnpbloch/wordpress-core` ships an installer plugin that would relocate the
package out of `vendor/`. `composer.json` refuses that plugin through
`allow-plugins`, which is what keeps the path above valid; a non-interactive
install fails without it.

### Self-analysis

| ID  | Path   | Description        |
| --- | ------ | ------------------ |
| qmx | `src/` | Qualimetrix itself |

### Private codebases (optional, machine-local)

Calibrating health scores against closed-source applications is useful, but the
codebases themselves — and even their names — must never reach this repository.
They are therefore configured locally and opted into per machine:

```bash
cp benchmarks/local-projects.json.example benchmarks/local-projects.json
# edit local-projects.json to point at your own checkouts
```

`benchmarks/local-projects.json` is git-ignored. When present,
`scripts/collect-benchmark-data.php` appends its entries with `type: private`;
when absent, only the open-source projects above are collected. The same applies
to `benchmarks/local.env` (`QMX_BENCH_MEDIUM` / `QMX_BENCH_LARGE`), which
`scripts/benchmark-comparison.sh` reads for its medium/large timing targets.

Do not record private project names, sizes, or filesystem paths in tracked files
— `scripts/check-private-leaks.sh` fails the build if they appear.

## Usage

Run these commands from the repository root. The separate benchmark dependency
tree is lock-file reproducible; repeat the install command whenever
`benchmarks/composer.lock` changes or the local corpus may be stale.

```bash
# Install or refresh benchmark dependencies
composer install --working-dir=benchmarks --no-scripts

# Collect benchmark data
php scripts/collect-benchmark-data.php [output-file.json]

# Regression check — verify health scores are within expected ranges
composer benchmark:check

# Update baselines after intentional formula changes
composer benchmark:update
```

Output is written to `docs/internal/benchmark-data.json` by default.

The benchmark commands disable Composer's default per-process timeout because
one complete corpus pass is expected to take longer than five minutes. This is
scoped to the benchmark entry point: the CI job's 30-minute timeout remains the
outer guard, and neither `composer check` nor the repository-wide Composer
configuration receives a larger deadline.

## Regression Testing

`composer benchmark:check` runs Qualimetrix on all open-source benchmark projects and compares
project-level health scores against expected ranges in `docs/internal/benchmark-baselines.json`.

Exit 0 means every expected metric was measured within its accepted range.
Exit 1 means a measured regression, an expected metric left unmeasured, or
incomplete coverage in a valid analysis document. Exit 2 means infrastructure
failure, including a missing project path or a failed or timed-out analysis
process. Any incomplete corpus blocks baseline replacement for the whole set.

The authoritative regression verdict is the stable `benchmark` CI job. It
installs both the root and benchmark lock files independently and runs for every
pull request, push to `main`, and merge queue candidate. A local run is useful
for diagnosis and calibration, but it is evidence only for the dependency tree
installed in that checkout.

- Exit code 0: all scores within ranges
- Exit code 1: regression detected (with details)
- Re-anchor only the cells moved by an owning semantic change, using a range of
  ±10 around the freshly measured value. Do not run `benchmark:update` for such
  a targeted shift because it rewrites every cell.
- Reserve `benchmark:update` for an intentional full formula recalibration and
  review every changed cell.

This job catches harms that unit regressions cannot: shifts in metrics after all
collectors, aggregation, and formulas are composed over real package code, and
an incomplete package corpus in the environment performing the check. It costs
about 10 minutes on the Linux runner and stays separate from `composer check`.
False reds can come from dependency availability, cache service failures, or
runner infrastructure rather than from a metric regression.

## Known Issues

- DuplicationDetector is memory-intensive on large projects (500+ files). It stores normalized tokens for all files with matching hashes in memory simultaneously
- Workaround: `--disable-rule=duplication` skips the detection phase entirely and frees the memory. Alternatively, increase `memory_limit`
