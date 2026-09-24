# Logging — PSR-3 Logging

## Overview

PSR-3 logger integration for debugging, monitoring, and logging parse errors.

**Log Levels:**

| Level   | Usage                                 |
| ------- | ------------------------------------- |
| DEBUG   | Parsing details for each file         |
| INFO    | Analysis progress (N files processed) |
| WARNING | Parse errors, deprecated features     |
| ERROR   | Critical failures                     |

## Components

### LoggerHelperTrait

Shared trait used by `ConsoleLogger` and `FileLogger` providing:
- `interpolate()` — PSR-3 message placeholder interpolation (`{key}` → context
  value). Only placeholders the message carries are converted; a non-finite
  float is written `NAN`, `INF` or `-INF`
- `meetsMinLevel()` — log level threshold filtering. A level outside PSR-3's
  eight, as a message level or as a threshold, throws
  `Psr\Log\InvalidArgumentException` instead of being ranked below everything
- `encodeJson()` — the context encoding both loggers use: bytes that are not
  UTF-8 are substituted (U+FFFD); a value substitution cannot save (`INF`,
  `NAN`) returns `null` and the caller says the context was lost

### ConsoleLogger

PSR-3 `LoggerInterface` implementation with output to Symfony Console Output.

**Features:**
- PSR-3 message interpolation (`{placeholder}` tokens replaced with context values)
- Formatting with timestamp and level
- Color output per level: EMERGENCY, ALERT, CRITICAL and ERROR as errors,
  WARNING as a comment, NOTICE and INFO as info, DEBUG plain
- Filtering by minimum level — the only filter. Verbosity is weighed once,
  by `LoggerFactory`, when it picks that level; the logger does not gate a
  second time
- The formatted line is escaped before it is styled: a `<info>` inside a
  message or its context is printed, not read as a tag
- A context that cannot be encoded is replaced by `(context not shown: <reason>)`

**Output format:**
```
[10:15:30] [INFO] Starting analysis
[10:15:31] [WARNING] Failed to parse file src/Legacy.php {"file":"src/Legacy.php","error":"Syntax error"}
[10:15:45] [INFO] Analysis complete {"violations":23}
```

### FileLogger

PSR-3 `LoggerInterface` implementation with output to file in JSON Lines format.

**Features:**
- PSR-3 message interpolation (`{placeholder}` tokens replaced with context values)
- Each entry is a separate JSON line
- Automatic directory creation
- Logging all levels (including DEBUG)
- A path that cannot be written throws `Contract\LogFileUnavailable` with
  the path and the reason PHP gave, and `RuntimeLoggerConfigurator` answers it
  as `--log-file` input (exit 3); the failed call's own PHP warning is
  captured, never printed — under `display_errors=1` it would reach stdout
  ahead of a machine format
- A record whose context cannot be encoded keeps its line, with
  `"context": null` and a `context_error` naming the reason
- A record written short (a full disk) throws instead of leaving a truncated line

**Output format:**
```json
{"timestamp":"2025-12-07T10:15:30+00:00","level":"info","message":"Starting analysis","context":{"paths":["src/"]}}
{"timestamp":"2025-12-07T10:15:30+00:00","level":"debug","message":"Parsing file src/Foo.php","context":{"file":"src/Foo.php"}}
```

### LoggerFactory

Creates the run's logger from the diagnostic writer, `--log-file` and
`--log-level`. The level arrives as null when `--log-level` was not written:
a default would be indistinguishable from a written `info`.

**Logic:**
- A console logger is created at every verbosity except `-q`. Its minimum
  level, with `--log-level` not written: WARNING at normal verbosity, INFO at
  `-v`, DEBUG at `-vv` and `-vvv`. Written: that level at `-v` and above; at
  normal verbosity it can only narrow the console (`error` hides warnings),
  never widen it, so `--log-level=debug --log-file=…` fills the file and not
  the terminal
- A file logger is created with `--log-file`, at the written level or INFO
- Returns a composite logger if both are active, `NullLogger` if neither is

### LoggerHolder and DelegatingLogger

Services built at container compilation receive `DelegatingLogger`, which
reads `LoggerHolder` on every call; the console adapter publishes the run's
logger there once it is configured.

## Integration

The PSR-3 logger is injected into the pipeline (`AnalysisPipeline`,
`CollectionOrchestrator`, `MeasurementAggregationService`), the parser
(`PhpFileParser`), the parallel strategy (`StrategySelector`,
`AmphpParallelStrategy`, `WorkerPool`) and several evidence, cache, Composer
and Git adapters. Parallel workers have no logger: what happens inside a
worker reaches the log only through the result it returns.

**Parse errors:**
- Logged at WARNING level
- Analysis continues for remaining files
- Final statistics show error count

## CLI Options

| Option                | Description                                                                           |
| --------------------- | ------------------------------------------------------------------------------------- |
| `--log-file=<path>`   | Log file path (JSON Lines); an unwritable path is refused with exit 3                 |
| `--log-level=<level>` | Minimum log level (debug/info/warning/error) for the file and, from `-v`, the console |
| (none)                | WARNING and ERROR to the console                                                      |
| `-v`                  | INFO to the console, or the written `--log-level`                                     |
| `-vv`, `-vvv`         | DEBUG to the console, or the written `--log-level`                                    |

## Examples

```bash
# Verbose console output
bin/qmx check src/ -v

# Very verbose (debug level)
bin/qmx check src/ -vv

# Log to file
bin/qmx check src/ --log-file=/tmp/qmx.log

# Set log level
bin/qmx check src/ --log-level=debug --log-file=/tmp/qmx.log
```

## Definition of Done

- `ConsoleLogger` implemented with PSR-3 interface
- `FileLogger` implemented with JSON Lines format
- `LoggerFactory` creates appropriate logger
- Logger integrated into `AnalysisPipeline` and `CollectionOrchestrator`
  (progress and errors)
- Logger integrated into `PhpFileParser` (parse errors)
- CLI options `--log-file`, `--log-level` work
- Parse errors are logged but analysis continues
- Unit tests for all loggers, including the refusal and encoding-failure paths


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
