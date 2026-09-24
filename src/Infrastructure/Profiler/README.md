# Profiler — Performance Instrumentation

Span-based profiler for measuring analysis pipeline performance.

## Architecture

```
Core/Profiler/
└── Contract/ProfilerInterface.php # neutral instrumentation vocabulary

Infrastructure/Profiler/
├── ProfileSession.php       # per-container state, Core port implementation
├── Profiler.php             # internal recording engine
├── Span.php                 # internal measurement value
├── Contract/                # Console-facing session/report promises
└── Export/
    ├── ProfileExporterInterface.php
    ├── JsonExporter.php          # Export to JSON
    └── ChromeTracingExporter.php # Export for Chrome DevTools
```

## Core Concepts

### ProfilerInterface

```php
interface ProfilerInterface
{
    public function start(string $name, ?string $category = null): void;
    public function stop(string $name): void;
}
```

### ProfileSession and Span

Each `start()`/`stop()` pair creates a `Span` — a record of a single measurement:
- `name` — operation name (e.g., `collection`, `aggregation`)
- `category` — category (e.g., `pipeline`, `collection`), or `null`
- `startTime` / `endTime` — `hrtime(true)` nanoseconds
- `startMemory` / `endMemory` — `memory_get_usage(true)` at the two
  boundaries. It moves in the allocator's blocks (2 MiB steps are typical),
  and nothing between the boundaries is sampled: a spike inside a span that
  is freed before it stops is not seen.

`stop()` closes the most recent open span with that name. Spans opened
after it and still open are closed with it, but they did not stop
themselves: their end is borrowed from the enclosing span, so they are
marked as **not stopped** and their time is kept out of the summary's
totals. A span still open when the profile is read is not stopped either.

`ProfileSession` is the single per-container lifecycle object. It implements
the Core instrumentation port, starts disabled, clears recorded spans whenever
it is enabled or disabled, and exposes separate Profiler-owned control and
report contracts to Console. Legacy holder/no-op types and static late binding
do not exist.

## Usage

### CLI Flags

```bash
# Summary output to stderr
bin/qmx check src/ --profile

# Export to JSON file
bin/qmx check src/ --profile=profile.json

# Export in Chrome Tracing format
bin/qmx check src/ --profile=trace.json --profile-format=chrome-tracing
```

### Summary Output

One line per span name, in the order the names were first seen: the summed
duration of the spans that stopped themselves, and how many there were.
Names that repeat (`aggregation.to_namespaces` runs once per aggregation
pass) are added up. Captured from a run (excerpt):

```
Profile summary:
  analysis: 0.025s | 1x
  discovery: 0.001s | 1x
  collection: 0.007s | 1x
  collection.execute_strategy: 0.007s | 1x
  collection.file: 0.007s | 1x
  aggregation.to_namespaces: 0.001s | 2x
  rules: 0.005s | 1x
```

A name with spans that did not stop themselves says so and leaves their
time out: `discovery: 0.000s | 0x | 1 never stopped, not timed`.

`ProfileSummary` carries exactly what this prints — `total` (milliseconds),
`count` and `unstopped` per name — and nothing that is computed and not shown.

### Collection coverage

`collection.file` spans are recorded only when collection runs in-process
through `SequentialStrategy` (`--workers=0`). `AmphpParallelStrategy` records
none — neither for files processed by workers, which run in other processes
without a profiler, nor in its own sequential fallback below the parallel
threshold. The `collection` and `collection.execute_strategy` totals are
present either way, and in parallel mode the memory figures of those spans
are the coordinating process's, not the workers'.

### Chrome Tracing

The exported `trace.json` can be opened in:
- Chrome DevTools (chrome://tracing)
- Perfetto (ui.perfetto.dev)

Format conforms to [Chrome Trace Event Format](https://docs.google.com/document/d/1CvAClvFfyA5R-PhYUmn5OOQtYMH4h6I0nSsKchNAySU).

## Pipeline Instrumentation

`AnalysisPipeline` instruments the phases (`analysis`, `discovery`,
`collection`, `dependency`, `rules`); aggregation, computed metrics, rule
preparation, file-set inspection, rule execution and reporting add their own
spans. Every pair is written by hand around its work:

```php
$this->profiler->start('discovery', 'pipeline');
$files = iterator_to_array($discovery->discover($paths), false);
$this->profiler->stop('discovery');
```

## Adding Instrumentation

To add profiling to a new component:

1. Inject `Core\Profiler\Contract\ProfilerInterface`.
2. Use `$this->profiler->start()` and `$this->profiler->stop()`, stopping on
   every exit path — `try`/`finally` where the work can throw. A span left
   open is closed by its enclosing span's `stop()` and reported as not
   stopped, without a time of its own.

```php
class MyService
{
    public function __construct(
        private readonly ProfilerInterface $profiler,
    ) {}

    public function doWork(): void
    {
        $this->profiler->start('my-operation', 'my-category');
        try {
            // ... work ...
        } finally {
            $this->profiler->stop('my-operation');
        }
    }
}
```

## Export Formats

### JSON

Always an object with one key, `spans`: the list of root spans, each with its
children. The top level does not change with the number of roots. Captured
from a run (children trimmed):

```json
{
    "spans": [
        {
            "name": "analysis",
            "category": "pipeline",
            "duration_ms": 27.831291,
            "memory_delta_bytes": 4194304,
            "stopped": true,
            "children": [
                {
                    "name": "discovery",
                    "category": "pipeline",
                    "duration_ms": 1.654416,
                    "memory_delta_bytes": 0,
                    "stopped": true,
                    "children": []
                }
            ]
        },
        {
            "name": "reporting",
            "category": "pipeline",
            "duration_ms": 1.007708,
            "memory_delta_bytes": 0,
            "stopped": true,
            "children": []
        }
    ]
}
```

`duration_ms` and `memory_delta_bytes` are `null` for a span that never
ended. `stopped` is `false` for a span that did not stop itself (see
[ProfileSession and Span](#profilesession-and-span)): its duration runs to
its enclosing span's stop.

### Chrome Tracing

A `B` (begin) and an `E` (end) event per span, `ts` in microseconds. A span
that never ended has no `E` event; the `E` event of a span that did not stop
itself carries `"args": {"stopped": false}`. Captured from a run:

```json
{
  "traceEvents": [
    { "name": "analysis", "ph": "B", "ts": 818960963565.291, "pid": 1, "tid": 1, "cat": "pipeline" },
    { "name": "discovery", "ph": "B", "ts": 818960963909.75, "pid": 1, "tid": 1, "cat": "pipeline" }
  ]
}
```

## Definition of Done

- [x] `ProfilerInterface` in `Core/Profiler/Contract/`
- [x] `ProfileSession` — disabled-by-default per-container session
- [x] `Profiler` and `Span` — internal recording implementation
- [x] JSON exporter
- [x] Chrome Tracing exporter
- [x] CLI flags `--profile`, `--profile-format`
- [x] `AnalysisPipeline` instrumentation
- [x] Unit tests

## Related Components

- [AnalysisPipeline](../../Analysis/Run/Pipeline/) — main profiler consumer
- [ProfilePresenter](../Console/ProfilePresenter.php) — summary and export on the command line
- [CheckCommand](../Console/Command/) — CLI integration


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
