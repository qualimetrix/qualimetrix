# Profiler — Performance Instrumentation

Span-based profiler for measuring analysis pipeline performance.

## Architecture

```
Core/Profiler/
├── ProfilerInterface.php    # Profiler contract
├── NullProfiler.php         # No-op implementation (default)
├── ProfilerHolder.php       # Holder for runtime configuration
└── Span.php                 # Value object for a single measurement

Infrastructure/Profiler/
├── Profiler.php             # Working implementation
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
    public function isEnabled(): bool;
    public function getSummary(): array;
    public function getSpans(): array;
    public function export(string $format): string;
}
```

### Span

Each `start()`/`stop()` creates a `Span` — a record of a single measurement:
- `name` — operation name (e.g., `collection`, `aggregation`)
- `category` — category (e.g., `pipeline`, `file`)
- `startTime` — start time in milliseconds
- `duration` — duration in milliseconds
- `memoryStart` / `memoryPeak` — memory usage

### ProfilerHolder

Holder pattern for late binding. Allows switching the profiler at runtime:

```php
// DI registers NullProfiler by default
$holder = new ProfilerHolder();

// CLI enables profiler with --profile
if ($input->getOption('profile')) {
    $holder->set(new Profiler());
}

// Pipeline uses current profiler
$profiler = $holder->get();
$profiler->start('collection', 'pipeline');
```

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
$profiler->start('discovery', 'pipeline');
$files = iterator_to_array($discovery->discover($paths), false);
$profiler->stop('discovery');

// Phase 2: Collection (longest phase)
$profiler->start('collection', 'pipeline');
$collectionResult = $this->collectionOrchestrator->collect(...);
$profiler->stop('collection');

// Phase 3: Aggregation
$profiler->start('aggregation', 'pipeline');
$this->aggregator->aggregate($repository);
$profiler->stop('aggregation');

// Phase 4: Global collectors
$profiler->start('global', 'pipeline');
$this->globalCollectorRunner->run($graph, $repository);
$profiler->stop('global');

// Phase 5: Rule execution
$profiler->start('rules', 'pipeline');
$violations = $this->ruleExecutor->execute($context);
$profiler->stop('rules');
```

## Adding Instrumentation

To add profiling to a new component:

1. Get ProfilerHolder via DI
2. Use `$profiler->start()` and `$profiler->stop()`

```php
class MyService
{
    public function __construct(
        private readonly ProfilerHolder $profilerHolder,
    ) {}

    public function doWork(): void
    {
        $profiler = $this->profilerHolder->get();

        $profiler->start('my-operation', 'my-category');
        // ... work ...
        $profiler->stop('my-operation');
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

- [x] `ProfilerInterface` in `Core/Profiler/`
- [x] `NullProfiler` — no-op implementation
- [x] `Profiler` — working implementation
- [x] `Span` — value object for measurements
- [x] `ProfilerHolder` — holder for runtime
- [x] JSON exporter
- [x] Chrome Tracing exporter
- [x] CLI flags `--profile`, `--profile-format`
- [x] `AnalysisPipeline` instrumentation
- [x] Unit tests

## Related Components

- [AnalysisPipeline](../../Analysis/Pipeline/) — main profiler consumer
- [CheckCommand](../Console/Command/) — CLI integration
