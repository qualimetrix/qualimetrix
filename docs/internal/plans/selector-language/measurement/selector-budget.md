# P0 selector budgets and PCRE probes

## Decision

Stage 1 will enforce these limits on one authored selector definition:

| Limit                | Value                 | Reason                                                                                                                                                                                                                      |
| -------------------- | --------------------: | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `MAX_PATTERN_LENGTH` | 4096 bytes            | 54.6 times the 75-byte pre-migration maximum; leaves substantial room for a reviewed glob-to-regex translation while preventing configuration-sized PCRE programs.                                                          |
| `MAX_SELECTOR_COUNT` | 256 per selector list | 16 times the largest tracked list of 16 values; preserves configuration ordering without allowing an unbounded `candidates x selectors` cost.                                                                               |
| `(*LIMIT_MATCH=...)` | 100000                | One tenth of this runtime's process-global backtrack limit. It forces the measured nested-quantifier failure into a controlled PCRE error in under 0.2 ms, independently of the host ini setting.                           |
| `(*LIMIT_DEPTH=...)` | 1000                  | An explicit cap for interpreter-mode recursion. The fallback probe reaches a controlled recursion error in 0.026 ms with JIT disabled; ordinary exact, subtree, and representative patterns all compile and match under it. |

The common renderer must put the two PCRE verbs before its fixed full-subject
wrapper. A runtime limit error is a `SelectorMatchFailure`, not a non-match.

There are no explicit regex fragments before this migration. The 75-byte value
below is consequently a **pre-migration raw-selector ceiling**, not a claim
about the final fragment maximum. P8 must rerun the checked-in probe after it
rewrites tracked configuration. Acceptance fails if a migrated definition is
over either chosen authored limit; otherwise 4096 remains more than ten times
the observed maximum required by Stage 1's gate.

## Census scope and result

The reproducible probe scans tracked `*qmx.yaml` documents and the three
versioned presets. From those documents it collects only the open-universe
common-selector keys:

- `suppress_paths`, `suppress_namespaces`, and the value lists below
  `suppress_namespace_channels` at every configuration depth;
- root discovery `exclude`;
- `framework_namespaces` and `include_namespaces` when a tracked configuration
  supplies them.

It intentionally excludes Architecture `patterns` and nested Architecture
`exclude` blocks: their capture/binding DSL is the named P7 exception. It also
excludes CLI invocations, Markdown examples, arbitrary workflow YAML, ignored
consumer configuration, PHP-constructed test input, and future selector doors.
The Stage 4 registry is the control that closes the future-door blind spot.

On this base the probe found:

| Measure                         | Result                                                                                                                                    |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------: |
| Tracked configuration documents | 39                                                                                                                                        |
| Common-selector lists           | 46                                                                                                                                        |
| Non-empty authored values       | 135                                                                                                                                       |
| Longest value                   | 75 bytes: `src/Analysis/Evidence/DependencyModel/Contract/DependencyGraphInterface.php` in `qmx.yaml` `rules.coupling.cbo.suppress_paths` |
| Largest list                    | 16 values: `qmx.yaml` `rules.coupling.distance.suppress_namespaces`                                                                       |

The census does not claim external consumer coverage. It is deliberately a
repository dogfood baseline, and P8's post-migration rerun is part of
acceptance.

## Measurement method

The retained probe is
[`selector-budget.php`](selector-budget.php). It receives no input and is run
from the repository root. It uses `git ls-files` so untracked and ignored
configuration cannot accidentally determine a committed public limit.

Each warm operation uses `preg_match(..., PREG_OFFSET_CAPTURE)`, matching the
planned full-span check rather than a cheaper boolean-only call. Each cold
operation adds a unique PCRE comment to an equivalent pattern so PHP cannot
reuse its process-local pattern cache. The representative regex contains both
alternation and character classes. The nested-quantifier probe is `(a+)+` over
4096 `a` bytes followed by `!`, repeated 100 times. The depth probe disables
JIT only to exercise PCRE's interpreter recursion accounting; the product
itself does not let an authored fragment inject the start-only `(*NO_JIT)` verb
after the fixed wrapper has begun.

Commands run:

```bash
php -l docs/internal/plans/selector-language/measurement/selector-budget.php
php docs/internal/plans/selector-language/measurement/selector-budget.php
```

Observed output on PHP 8.5.9, PCRE2 10.47, `pcre.jit=1`:

```text
Census: 39 configuration files, 46 selector lists, 135 non-empty string values
Longest value: 75 bytes at qmx.yaml:rules.coupling.cbo.suppress_paths: src/Analysis/Evidence/DependencyModel/Contract/DependencyGraphInterface.php
Largest list: 16 values at qmx.yaml:rules.coupling.distance.suppress_namespaces
exact: cold=4.668 us/op; warm=155.2 ns/op
subtree: cold=3.655 us/op; warm=152.4 ns/op
representative-regex: cold=5.630 us/op; warm=160.2 ns/op
Current controls: exact=14.5 ns/op; boundary-subtree=100.4 ns/op; fnmatch-glob=208.6 ns/op
Nested quantifier (4096 a + !): min=0.106 ms; median=0.106 ms; p95=0.125 ms; max=0.195 ms; errors={"2":100}
Depth fallback (JIT disabled, 2048 recursive pairs): result=false; error=3 (Recursion limit exhausted); elapsed=0.026 ms
accept: result=1; error=0 (No error); span=["",0]; full-span=no
reset-start: result=1; error=0 (No error); span=["bc",1]; full-span=no
```

PCRE error `2` is `PREG_BACKTRACK_LIMIT_ERROR`. `(*ACCEPT)` and `\K` both
return a successful PCRE match while failing the intended whole-subject span;
the planned offset-and-length verification is therefore required even with
`\A...\z` around the fragment.

## Performance interpretation and portability

These timings are not a cross-machine CI threshold. JIT availability, CPU
frequency, PCRE build options, and PHP's cache make nanoseconds per operation
non-portable. They establish two local guardrails for implementation review:

- the representative PCRE path must remain no slower than the current `fnmatch`
  glob path by more than 25 percent on the same machine and revision baseline;
- the added full-span PCRE work for exact/subtree may be at most twice the
  current boundary-prefix control on that same comparison.

The recorded sample passes both: representative regex is 0.77 times the
`fnmatch` control, while exact/subtree are about 1.55 times the prefix control.
This is not a claim that regex is universally faster. Portable CI verifies the
semantic/resource cases and reruns the probe for visibility; a timing breach is
review evidence only unless the project later provisions a pinned benchmark
runner.

## Alternatives rejected

- `MAX_PATTERN_LENGTH=1024` and `MAX_SELECTOR_COUNT=160` satisfy the literal
  ten-times rule but leave too little headroom for reviewed translations and
  make a future valid configuration change unnecessarily likely to become a
  contract change.
- Process-global `pcre.backtrack_limit` and `pcre.recursion_limit` do not give
  a stable product contract: host ini values vary. Inline PCRE limits bind each
  rendered selector instead.
- A wall-clock timeout cannot make PHP PCRE matching portable or deterministic;
  it would also be process-wide rather than selector-specific.
