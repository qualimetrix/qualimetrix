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

### ConsoleLogger

PSR-3 `LoggerInterface` implementation with output to Symfony Console Output.

**Features:**
- Formatting with timestamp and level
- Color output for different levels (error/warning/info)
- Filtering by minimum level
- DEBUG messages only with `-v` flag

**Output format:**
```
[10:15:30] [INFO] Starting analysis
[10:15:31] [WARNING] Failed to parse file {"file":"src/Legacy.php","error":"Syntax error"}
[10:15:45] [INFO] Analysis complete {"violations":23}
```

### FileLogger

PSR-3 `LoggerInterface` implementation with output to file in JSON Lines format.

**Features:**
- Each entry is a separate JSON line
- Automatic directory creation
- Logging all levels (including DEBUG)

**Output format:**
```json
{"timestamp":"2025-12-07T10:15:30+00:00","level":"info","message":"Starting analysis","context":{"paths":["src/"]}}
{"timestamp":"2025-12-07T10:15:30+00:00","level":"debug","message":"Parsing file","context":{"file":"src/Foo.php"}}
```

### LoggerFactory

Creates an appropriate logger based on CLI options.

**Logic:**
- Console logger is created with `-v` flag
- File logger is created with `--log-file`
- Returns composite logger if both are active
- Returns `NullLogger` by default

## Integration

Logger is passed to:
- `Analyzer` — logging progress and errors
- `PhpFileParser` — logging parse errors

**Parse errors:**
- Logged at WARNING level
- Analysis continues for remaining files
- Final statistics show error count

## CLI Options

| Option                | Description                                  |
| --------------------- | -------------------------------------------- |
| `--log-file=<path>`   | Log file path (JSON Lines)                   |
| `--log-level=<level>` | Minimum log level (debug/info/warning/error) |
| `-v`                  | Enable INFO logs to console                  |
| `-vvv`                | Enable DEBUG logs to console                 |

## Examples

```bash
# Verbose console output
bin/aimd analyze src/ -v

# Very verbose (debug level)
bin/aimd analyze src/ -vvv

# Log to file
bin/aimd analyze src/ --log-file=/tmp/aimd.log

# Set log level
bin/aimd analyze src/ --log-level=debug --log-file=/tmp/aimd.log
```

## Definition of Done

- `ConsoleLogger` implemented with PSR-3 interface
- `FileLogger` implemented with JSON Lines format
- `LoggerFactory` creates appropriate logger
- Logger integrated into `Analyzer` (progress and errors)
- Logger integrated into `PhpFileParser` (parse errors)
- CLI options `--log-file`, `--log-level` work
- `-v` flag enables console logging
- Parse errors are logged but analysis continues
- Unit tests for all loggers
