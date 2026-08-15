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
    public function start(string $name, string $category = 'default'): void;
    public function stop(string $name): void;
}
```

### ProfileSession and Span

Each `start()`/`stop()` creates a `Span` — a record of a single measurement:
- `name` — operation name (e.g., `collection`, `aggregation`)
- `category` — category (e.g., `pipeline`, `file`)
- `startTime` — start time in milliseconds
- `duration` — duration in milliseconds
- `memoryStart` / `memoryPeak` — memory usage

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

```
Profile summary:
  analysis       : 0.452s ( 50%) | 18.0 MB  | 1x
  collection     : 0.394s ( 44%) | 16.0 MB  | 1x
  discovery      : 0.029s (  3%) | 0.0 B    | 1x
  aggregation    : 0.019s (  2%) | 0.0 B    | 1x
  rules          : 0.005s (  1%) | 0.0 B    | 1x
  dependency     : 0.002s (  0%) | 0.0 B    | 1x
  global         : 0.002s (  0%) | 2.0 MB   | 1x
Peak memory: 32.0 MB
```

### Chrome Tracing

The exported `trace.json` can be opened in:
- Chrome DevTools (chrome://tracing)
- Perfetto (ui.perfetto.dev)

Format conforms to [Chrome Trace Event Format](https://docs.google.com/document/d/1CvAClvFfyA5R-PhYUmn5OOQtYMH4h6I0nSsKchNAySU).

## Pipeline Instrumentation

`AnalysisPipeline` automatically instruments the main phases:

```php
// Phase 1: Discovery
$this->profiler->start('discovery', 'pipeline');
$files = iterator_to_array($discovery->discover($paths), false);
$this->profiler->stop('discovery');

// Phase 2: Collection (longest phase)
$this->profiler->start('collection', 'pipeline');
$collectionResult = $this->collectionOrchestrator->collect(...);
$this->profiler->stop('collection');

// Phase 3: Aggregation
$this->profiler->start('aggregation', 'pipeline');
$this->aggregator->aggregate($repository);
$this->profiler->stop('aggregation');

// Phase 4: Global collectors
$this->profiler->start('global', 'pipeline');
$this->globalCollectorRunner->run($graph, $repository);
$this->profiler->stop('global');

// Phase 5: Rule execution
$this->profiler->start('rules', 'pipeline');
$violations = $this->ruleExecutor->execute($context);
$this->profiler->stop('rules');
```

## Adding Instrumentation

To add profiling to a new component:

1. Inject `Core\Profiler\Contract\ProfilerInterface`.
2. Use `$this->profiler->start()` and `$this->profiler->stop()`.

```php
class MyService
{
    public function __construct(
        private readonly ProfilerInterface $profiler,
    ) {}

    public function doWork(): void
    {
        $this->profiler->start('my-operation', 'my-category');
        // ... work ...
        $this->profiler->stop('my-operation');
    }
}
```

## Export Formats

### JSON

```json
{
  "spans": [
    {
      "name": "collection",
      "category": "pipeline",
      "start_time": 1234567890.123,
      "duration": 394.5,
      "memory_start": 16777216,
      "memory_peak": 33554432
    }
  ],
  "summary": {
    "collection": {
      "total": 394.5,
      "count": 1,
      "avg": 394.5,
      "memory": 16777216
    }
  },
  "peak_memory": 33554432
}
```

### Chrome Tracing

```json
{
  "traceEvents": [
    {
      "name": "collection",
      "cat": "pipeline",
      "ph": "X",
      "ts": 1234567890123,
      "dur": 394500,
      "pid": 1,
      "tid": 1
    }
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

- [AnalysisPipeline](../../Analysis/Pipeline/) — main profiler consumer
- [CheckCommand](../Console/Command/) — CLI integration


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
