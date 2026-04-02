# Architecture Findings (April 2026)

Findings from user-perspective testing and bug fixing session.
Discovered during investigation of CLI option bugs (`--cache-dir`, `--class`, `--log-level`).

---

## 1. Eager DI Resolution vs Late Configuration

**Severity:** High — new bugs will appear as new config-dependent services are added.

### Problem

The application uses a "set defaults at build time, override at runtime" pattern:

```
bin/qmx:
  ContainerFactory::create()
    → ConfigurationConfigurator: sets new AnalysisConfiguration() (DEFAULTS)
    → $container->compile()
    → $container->get(CheckCommand::class) ← resolves ALL dependencies eagerly
      → FileParserFactory → CacheFactory::create() ← reads DEFAULT config (cacheDir=.qmx-cache)

  CheckCommand::execute()
    → resolveConfiguration()     ← CLI --cache-dir first available HERE
    → runtimeConfigurator()      ← sets CORRECT config
    → But CacheInterface already created with the wrong config!
```

### Root Cause

`ConfigurationConfigurator` (line 57) sets `new AnalysisConfiguration()` with defaults during container build.
`bin/qmx` (line 46) calls `$container->get(CheckCommand::class)` which eagerly resolves the entire dependency tree.
Any service in that tree that reads `ConfigurationProviderInterface::getConfiguration()` gets the default config, not the CLI-merged config.

### Current Fix (Tactical)

Changed `CachedFileParser` and `FileParserFactory` to accept `CacheFactory` instead of `CacheInterface`, deferring cache creation to first actual use (after config is set).

### Affected Files

- `src/Infrastructure/DependencyInjection/Configurator/ConfigurationConfigurator.php:57`
- `bin/qmx:46` (eager `$container->get()`)
- `src/Infrastructure/Cache/CacheFactory.php` (memoization + reset pattern)

### Systemic Fix Options

**Option A: Lazy service resolution.** Don't call `$container->get()` in `bin/qmx`. Instead, register commands in the container and let Symfony Console resolve them lazily via `ContainerCommandLoader`.

**Option B: Remove default config.** Don't call `setConfiguration(new AnalysisConfiguration())` in `ConfigurationConfigurator`. Instead, make `ConfigurationHolder::getConfiguration()` return defaults when no config is set. This way early reads still get correct defaults, but won't be cached with wrong values.

**Option C: Audit and enforce laziness.** Ensure all config-dependent services use factory patterns (like the CacheFactory fix). Less elegant but pragmatic.

### Risk of Inaction

Any new service that:
1. Is in CheckCommand's dependency tree
2. Reads `ConfigurationProviderInterface` during construction or factory call
3. Memoizes the result

...will silently ignore CLI configuration. The bug is hard to reproduce in tests because tests typically set configuration before constructing services.

---

## 2. Filtering at Wrong Abstraction Layer

**Severity:** Medium — fixed, but pattern may recur.

### Problem

The `--class` and `--namespace` drill-down filters were implemented at the formatter level. Each formatter was responsible for calling `ViolationFilter::filterViolations()` individually. Result: only 2 of 11 formatters actually filtered.

| Formatter                  | Before Fix                    |
| -------------------------- | ----------------------------- |
| JsonFormatter              | Filtered                      |
| SummaryFormatter           | Filtered (only with --detail) |
| TextFormatter              | NOT filtered                  |
| CheckstyleFormatter        | NOT filtered                  |
| SarifFormatter             | NOT filtered                  |
| GitLabCodeQualityFormatter | NOT filtered                  |
| GithubActionsFormatter     | NOT filtered                  |
| HtmlFormatter              | NOT filtered                  |
| MetricsJsonFormatter       | NOT filtered                  |
| HealthTextFormatter        | NOT filtered                  |
| TextVerboseFormatter       | NOT filtered                  |

### Fix Applied

Moved filtering to `ResultPresenter.presentResults()` — the central point where violations flow from pipeline to formatters. Now all formatters receive pre-filtered violations.

### Architectural Lesson

Formatters should be **pure renderers**: they receive data and produce output. Cross-cutting concerns (filtering, sorting, truncation) belong in the pipeline layer above.

Files changed:
- `src/Infrastructure/Console/ResultPresenter.php` — central filter applied
- `src/Reporting/Formatter/Json/JsonFormatter.php` — removed duplicate filter
- `src/Reporting/Formatter/Summary/SummaryFormatter.php` — removed duplicate filter

---

## 3. PSR-3 Message Interpolation Not Implemented

**Severity:** Low — cosmetic issue in log output.

### Problem

PSR-3 spec requires loggers to interpolate `{placeholder}` tokens in messages using the context array. Our `ConsoleLogger` and `FileLogger` append context as JSON instead:

```
// Actual output:
[WARNING] Unknown option "{key}" for rule "{rule}" {"key":"maxCcn","rule":"complexity.cyclomatic"}

// PSR-3 expected:
[WARNING] Unknown option "maxCcn" for rule "complexity.cyclomatic"
```

### Affected Files

- `src/Infrastructure/Logging/ConsoleLogger.php:63-74` — `format()` method
- `src/Infrastructure/Logging/FileLogger.php` — same pattern

### Fix

Add an `interpolate()` helper per PSR-3 recommendation:

```php
private function interpolate(string $message, array $context): string
{
    $replace = [];
    foreach ($context as $key => $val) {
        if (is_string($val) || is_numeric($val) || (is_object($val) && method_exists($val, '__toString'))) {
            $replace['{' . $key . '}'] = (string) $val;
        }
    }
    return strtr($message, $replace);
}
```

---

## 4. Dead Code: StorageFactory

**Severity:** None — no runtime impact.

`src/Infrastructure/Storage/StorageFactory.php` is defined but never used in production code. No PHP file references it (only READMEs). Likely a remnant from a refactoring or prepared for future SQLite-based storage auto-detection.

**Action:** Delete or mark with `@internal @todo` comment.

---

## Summary

| #   | Finding                  | Severity | Status                                    |
| --- | ------------------------ | -------- | ----------------------------------------- |
| 1   | Eager DI vs Late Config  | High     | Tactical fix applied, systemic fix needed |
| 2   | Filtering at wrong layer | Medium   | Fixed                                     |
| 3   | PSR-3 interpolation      | Low      | Open                                      |
| 4   | Dead StorageFactory      | None     | Open                                      |
