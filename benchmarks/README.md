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

### Self-analysis

| ID  | Path   | Description        |
| --- | ------ | ------------------ |
| qmx | `src/` | Qualimetrix itself |

### Proprietary (local only, not in repo)

| ID              | Size        | Description            |
| --------------- | ----------- | ---------------------- |
| private-codebase           | ~3100 files | Private codebase  |
| private-codebase    | ~2200 files | Private codebase            |
| private-codebase   | ~1800 files | Private codebase |
| private-codebase  | ~1000 files | Private codebase      |
| private-codebase | ~N files | Private codebase |
| private-codebase | ~N files | Private codebase |
| private-codebase        | ~600 files  | Private codebase            |
| private-codebase | ~250 files  | Private codebase        |
| private-codebase | ~N files | Private codebase |
| private-codebase | ~N files | Private codebase |
| private-codebase   | ~40 files   | Private codebase          |

## Usage

```bash
# Install benchmark dependencies
cd benchmarks && composer install

# Collect benchmark data
php scripts/collect-benchmark-data.php [output-file.json]

# Regression check — verify health scores are within expected ranges
composer benchmark:check

# Update baselines after intentional formula changes
composer benchmark:update
```

Output is written to `docs/internal/benchmark-data.json` by default.

## Regression Testing

`composer benchmark:check` runs Qualimetrix on all open-source benchmark projects and compares
project-level health scores against expected ranges in `docs/internal/benchmark-baselines.json`.

- Exit code 0: all scores within ranges
- Exit code 1: regression detected (with details)
- Use `--update-baselines` to recalibrate ranges after intentional formula changes

This is NOT included in `composer check` (takes ~3 minutes). Run manually after:
- Changing health score formulas
- Modifying metric collectors or aggregation
- Before releases

## Known Issues

- DuplicationDetector is memory-intensive on large projects (500+ files). It stores normalized tokens for all files with matching hashes in memory simultaneously
- Workaround: `--disable-rule=duplication` skips the detection phase entirely and frees the memory. Alternatively, increase `memory_limit`
