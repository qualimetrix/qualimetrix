# Scanner Validation Round 1 — Findings

**Status:** complete
**Base:** `origin/main` at `6bfcb561`
**Plan:** [SCANNER_VALIDATION_ROUND_1_PLAN.md](SCANNER_VALIDATION_ROUND_1_PLAN.md)

## Environment

| Item               | Value                                                                                                                        |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| Worktree           | Linked Codex worktree; runtime proven to load this checkout                                                                  |
| Branch             | `codex/scanner-validation-round-1`                                                                                           |
| Initial worktree   | Clean                                                                                                                        |
| Base commit        | `6bfcb5611fc1a896b2b415c725b853d41ec3d87c`                                                                                   |
| Root dependencies  | Locked install complete with `--no-scripts`; lock SHA-256 `35c681033c5196d9a641dd0ef6f52b36d0adb3beaecb137e2c35ef3678392df0` |
| Runtime            | PHP 8.5.9; Qualimetrix `dev-main`; 666 self-analysis files                                                                   |
| Competitors        | PDepend 2.16.2; PhpMetrics 2.9.1; global lock SHA-256 `a645c5038148477d62b83cba364e66b9dbd805c9b03bba50152dee823dd409b0`     |
| P0 scratch         | `/private/tmp/qmx-scanner-r1-p0.3nHF9k`                                                                                      |
| Initial validation | `composer check` exit 0; 6,785 tests, 1 skipped; PHPStan and selfcheck green                                                 |

## Finding Index

| ID    | Status       | Severity | Surface                     | Summary                                                                    |
| ----- | ------------ | -------- | --------------------------- | -------------------------------------------------------------------------- |
| F-001 | Fixed        | Medium   | CLI tooling/docs            | Legacy `analyze` references invoked a command that is no longer registered |
| F-002 | Fixed        | High     | AST cache                   | Same-size content replacement with preserved `mtime` reused a stale AST    |
| F-003 | Fixed        | High     | Dogfood architecture policy | Root `Qualimetrix\\Metrics` primitives were outside every declared layer   |
| F-004 | Fixed        | Medium   | Cross-tool harness          | `classLoc` comparisons overclaimed contract equivalence                    |
| F-005 | Fixed        | Medium   | AST cache                   | Cache key and parsed AST could come from different file snapshots          |
| F-006 | Contract gap | High     | PHP 8.4 property hooks      | Hook bodies and signatures disappear from callable metrics and rules       |
| F-007 | Confirmed    | High     | PHP 8.5 clone-with          | Language-level clone is reported as a normal external function call        |
| F-008 | Confirmed    | Medium   | Nullsafe NPath              | Atomic nullsafe access loses the documented +1 path contribution           |
| F-009 | Contract gap | Medium   | First-class callables       | Callable capture is indistinguishable from invocation in RFC/Halstead      |
| F-010 | Confirmed    | Medium   | PHP 8.5 pipe                | Halstead records `|>` as generic `binary_op`                               |
| F-011 | Contract gap | Medium   | Initializer ownership       | First-class callable ownership depends on initializer position             |
| F-012 | Confirmed    | Medium   | Closure aggregation         | Collected closure/arrow metrics are discarded before repository roll-up    |
| F-013 | Confirmed    | Medium   | Degree-zero coupling        | Isolated symbols have absent coupling metrics instead of known zeroes      |
| F-014 | Confirmed    | Low      | Metric deviation docs       | Modern CCN deviation notes do not use the required durable format          |
| D-001 | Deferred     | Unknown  | Symbol identity             | Conditional duplicate FQNs collapse to one QMX symbol                      |

## F-001 — Legacy `analyze` command references

**Status:** fixed and independently verified.

Pre-fix static evidence:

- `CheckCommand` registers `check` and declares no alias in its attribute.
- `scripts/benchmark-comparison.sh` invokes `bin/qmx analyze`.
- `src/Infrastructure/Console/README.md` describes `analyze` as a deprecated
  alias.

Pre-fix runtime confirmation:

1. `bin/qmx list --raw` contains `check` and no `analyze` command;
2. `bin/qmx analyze --help` exits 1 with `Command "analyze" is not defined`;
3. `scripts/benchmark-comparison.sh` still invokes `bin/qmx analyze`;
4. `src/Infrastructure/Console/README.md` still promises a deprecated alias.

The registered command and current public CLI consistently use `check`, so the
root cause is stale executable tooling/documentation after the command rename,
not a runtime registration failure. The fix must update every tracked stale
reference and add a static regression that rejects command-surface drift.

Fix: both executable comparison scripts now call `check`, the obsolete alias
claim was removed, and an integration regression scans the bounded executable
consumers for command-surface drift. The test failed before the script edits;
six tests with 22 assertions and targeted PHPStan pass afterward.

## F-002 — Same-size/same-mtime AST cache invalidation

**Status:** fixed and independently verified.

Pre-fix static evidence:

- `CacheKeyGenerator` hashes `realpath`, second-resolution `mtime`, file size,
  and runtime/parser version; it does not hash file content.
- Its class docblock nevertheless says keys are based on file content.
- The existing content-change test changes both size and normally `mtime`, so
  it does not exercise a same-size rewrite inside the same timestamp value.

Pre-fix runtime confirmation:

1. two valid 70-byte PHP variants were written to the same path with the same
   epoch `mtime`;
2. both produced cache key `25b290ca38a17c69f80a291af16caa7b`;
3. after priming with the branching variant, the cached replacement still
   reported `ccn=2`, `cognitive=1`, `npath=2`, and two statements;
4. the no-cache replacement reported `ccn=1`, `cognitive=0`, `npath=1`, and one
   statement;
5. a clean repeat with a fresh cache reproduced the same stale/fresh split.

Evidence: `/private/tmp/qmx-scanner-r1-r1.5Zh8ss/e9`.

Root cause: `CacheKeyGenerator` claims content-based invalidation but hashes
only path, second-resolution `mtime`, size, and runtime/parser version. Those
inputs are identical for a same-size rewrite with preserved/coarse `mtime`.

Fix: cache keys now include an `xxh128` content fingerprint, key-schema marker,
and runtime/parser version. Missing or unreadable files return an empty key so
`CachedFileParser` bypasses cache. Same content at another path can safely share
the AST entry; metadata-only `mtime` changes no longer cause misses.

Verification:

- test-first regression failed before the production edit;
- 34 focused cache/AST tests passed in the orchestrator repeat;
- fresh process-level E9 produced different keys for the two contents and
  identical cached/no-cache metrics for the replacement.

This authority-blocking fix does not consume the round's three ordinary
fix-package limit.

## F-003 — Root Metrics primitives bypass dogfood architecture policy

**Status:** fixed and independently verified.

Pre-fix evidence:

- the `metrics-{Category}` template matches only
  `Qualimetrix\\Metrics\\{Category}\\**`;
- `AbstractCollector`, `ResettableVisitorInterface`, and
  `VisitorMethodTrackingTrait` live directly under `src/Metrics` and match no
  layer;
- `debug:layer-assignment` reports `(no layer)` for the reset interface;
- the dependency graph contains 22 incoming edges for that interface alone,
  all source-backed by imports/implements plus one runtime `instanceof`;
- `coverage: ignore` converts the omission into a green run with zero layer
  findings.

Root cause: the per-category split removed the former flat Metrics coverage but
did not declare the finite cross-category metric primitives that remain at the
root. These three types are a legitimate shared metric foundation under the
subject-cohesion duplication test; a broad catch-all Metrics layer would mask
future placement errors and is not an acceptable fix.

Evidence: `/private/tmp/qmx-scanner-r1-r3.mfwoQa`.

Fix: an exact `metrics-foundation` layer covers only the three reviewed shared
primitives. Metric categories may depend on it; the reverse direction is
forbidden. A topology regression enumerates every root `src/Metrics/*.php` so a
future root type cannot silently inherit coverage.

Verification: test-first topology checks failed before the YAML edit; 19 tests
with 97 assertions pass afterward; all three assignments resolve to the new
layer; sequential no-cache selfcheck is green for 666 files.

## F-004 — Cross-tool `classLoc` contract overclaim

**Status:** fixed and independently verified.

Pre-fix evidence:

- PhpMetrics `loc` counts lines from a pretty-printed AST, not the physical
  source span that Qualimetrix `classLoc` reports;
- only 10 of 220 paired live/hand values matched and six trait values were
  missing;
- PDepend `loc` matched all 215 qualified plain named classes but diverged by
  exactly one on all 13 attributed classes because its span starts at the
  `class` keyword while the PHP-Parser node includes the attribute line;
- the tracked harness has no declaration-shape qualifier yet marks both pairs
  globally `comparable`.

Root cause: `COMPARISON_SPECS` equated similar field names with a stronger
artifact contract than the tools implement. Both `classLoc` pairs must be
contextual in the global harness; scratch-only qualified checks remain useful.

Evidence: `/private/tmp/qmx-scanner-r1-r2.AHT8sb`.

Fix: both rows are now `contextual` with tool-specific rationale; NOC is the
only globally comparable row. Two new regressions failed before the spec edit;
17 cross-tool tests and a no-cache Monolog smoke pass afterward, with NOC as
`agreement` and both LOC pairs as `contextual`.

## F-005 — AST cache snapshot consistency

**Status:** fixed and independently verified.

The first cache fix still generated a content key and parsed the mutable source
path in separate reads. A deterministic ABA probe replaced content A with B
between those operations, cached AST B under key A, restored A, and then
observed cached AST B. An intermediate temporary-file implementation closed the
ABA race but made syntax diagnostics name the temporary snapshot instead of the
original source path; the address review rejected that regression.

Fix: `FileParserInterface::parseContent()` now accepts immutable source bytes
plus the original `SplFileInfo` identity. `CachedFileParser` reads the source
once, derives the key from those bytes, and parses those same bytes on a miss;
`PhpFileParser` retains the original path for diagnostics. No temporary source
file is created.

Verification:

- both the ABA and diagnostic-path regressions failed before their respective
  fixes;
- 32 focused parser/cache/graph tests with 80 assertions pass;
- cached and direct syntax errors identify the same original source path;
- targeted PHPStan and CS checks pass; the final address review found no
  residual correctness issue.

## Extended Diagnostic Provenance

| Artifact                 | Path                                                                         | SHA-256                                                            |
| ------------------------ | ---------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| E10 frozen static oracle | `/private/tmp/qmx-scanner-r1-e10-static.oObQRG/E10_STATIC_REPORT.md`         | `3938e644a439b337ee1530f85385221cbe8e4f28528e0c152539e5699a335e47` |
| E10 clean dynamic report | `/private/tmp/qmx-scanner-r1-e10-dynamic-clean.ZrdvV4/E10_DYNAMIC_REPORT.md` | `3448900c334d16d75311ddc622c2f5c40f8cd5326fc33b50c33471674bf225ee` |
| E11 frozen matrix        | `/private/tmp/qmx-scanner-r1-e11-boundary.3P5HgN/EXPECTED_FROZEN.md`         | `cfcbc2ed20366eb912b54ead1056a14e974bd39a0ab1a090b18cdd5cf643edfe` |
| E11 final report         | `/private/tmp/qmx-scanner-r1-e11-boundary.3P5HgN/E11_REPORT.md`              | `497d3eddb6956a25336f8946ecc2a1b85c2948b97a882681a4a8de4598d38e2c` |

## F-006 — PHP 8.4 property hooks have no callable metric model

**Status:** confirmed contract gap; diagnosis only, not fixed.

Five valid `PropertyHook` nodes produced no hook identity, complexity,
Halstead, statements, RFC, parameter/type coverage, boolean-argument, or
sensitive-parameter result. An ordinary method control produced the expected
rule findings. Dependency traversal still retained a `new` edge from a hook
body, so hooks are not uniformly excluded: dependency work is class-owned while
callable work and signatures disappear.

The product must first choose stable hook ownership and decide which callable
metrics/rules apply. Evidence: P84-HOOK-BODY in
`/private/tmp/qmx-scanner-r1-e10-dynamic-clean.ZrdvV4/E10_DYNAMIC_REPORT.md`.

## F-007 — PHP 8.5 clone-with is treated as a function call

**Status:** confirmed implementation defect; diagnosis only, not fixed.

PHP 8.5 and php-parser accept `clone($object, [...])`, but its callable-shaped
AST reaches generic call handling. The probe emitted Halstead `call` rather
than `clone` and increased RFC external by one for a fictitious global `clone`
response. Complexity remained correctly linear. Primary and clean repeat were
identical.

## F-008 — Nullsafe NPath loses one path

**Status:** confirmed implementation defect; diagnosis only, not fixed.

The documented/frozen extension is +1 NPath per nullsafe access. CCN, Halstead,
cognitive, and RFC behaved as expected, but a single nullsafe method/property
access stayed at NPath 1, equal to the direct control; a two-access chain was 2
rather than the expected 3. The contribution is lost at the
`max(1, expressionNPath)` boundary.

## F-009 — First-class callable capture is counted as invocation

**Status:** confirmed contract gap; diagnosis only, not fixed.

Three captures (`function(...)`, instance method, and static method) plus two
real applications produced five external RFC targets. Halstead likewise used
ordinary call operators. Capture may still create a dependency, but execution
and capture need separate RFC/Halstead semantics or an explicit broader
contract.

## F-010 — PHP 8.5 pipe loses Halstead operator identity

**Status:** confirmed implementation defect; diagnosis only, not fixed.

`BinaryOp\Pipe` is traversed and contributes an operator occurrence, but the
operator is serialized as generic `binary_op`, not `|>`. CCN, cognitive,
NPath, statements, and RFC parity with the direct-call control all passed.

## F-011 — Initializer callable ownership is position-dependent

**Status:** confirmed contract gap; diagnosis only, not fixed.

Property and class-constant first-class callable captures create no method
work, while the same capture as a promoted-constructor default is charged to
constructor Halstead and RFC despite zero constructor statements. Actual
closure initializers are invalid constant expressions on PHP 8.5 and were
correctly classified runtime-inapplicable.

## F-012 — Closure metrics are collected and then discarded

**Status:** confirmed pipeline defect; diagnosis only, not fixed.

Visitors calculate closure and arrow-function CCN, cognitive, NPath, Halstead,
MI, and statement metrics, but `MethodWithMetrics` has no symbol path for them
and `FileProcessor` drops every null path before repository aggregation. A
closure-only file exposed neither callable identities nor aggregate inputs; a
mixed namespace counted only its named function and methods. This contradicts
the Size README's closure-level aggregation contract and silently understates
closure-heavy code. WMC/non-leakage for named class methods remains correct;
the defect is persistence and roll-up, not leakage into WMC.

Evidence: E11-F01 in
`/private/tmp/qmx-scanner-r1-e11-boundary.3P5HgN/E11_REPORT.md`; two normalized
repeats were byte-identical.

## F-013 — Degree-zero symbols have absent coupling metrics

**Status:** confirmed pipeline defect; diagnosis only, not fixed.

A real isolated class exists in the metric repository but not in the dependency
graph's edge-derived node set. `CouplingCollector` therefore emits no
`ca/ce/cbo/instability` for the class or namespace instead of known zeroes.
Downstream defaults diverge: class coupling health becomes 100, while missing
namespace A/I yields distance 1 and coupling health 75. The existing
"isolated" unit case is actually an incoming-edge target and does not exercise
an empty graph.

Evidence: E11-F02 and `isolated.metrics.json` under
`/private/tmp/qmx-scanner-r1-e11-boundary.3P5HgN/`.

## F-014 — Modern metric deviation notes are not policy-complete

**Status:** confirmed documentation gap; diagnosis only, not fixed.

EN/RU website text accurately describes CCN extensions such as `??`, nullsafe,
xor, and match, but does not use the required
`!!! info "Deviation from original spec"` block, and the component README does
not carry the required `> **Note:**` deviation block. Halstead documentation is
compliant. New hook/pipe/clone-with/callable behavior is not documented because
its contract is not yet settled.

## D-001 — Conditional duplicate-FQN identity model

**Status:** deferred product-contract question, not classified as a defect.

PHP-Parser and Doctrine ORM contain valid conditional declarations with the
same FQN. Qualimetrix reports both declaration counts but exposes one symbol
identity, while PDepend emits duplicate identities and the fail-closed harness
rejects them. The observed behavior is real, but the intended identity contract
for mutually exclusive declarations is unspecified. This exceeds the round's
three ordinary fix packages and is preserved for a later design round.

## Execution Log

| Phase                        | Status   | Evidence                                                                                                     |
| ---------------------------- | -------- | ------------------------------------------------------------------------------------------------------------ |
| Planning reconnaissance      | Complete | Static CLI, metric/oracle, and test-harness inventories delegated read-only                                  |
| Plan review                  | Complete | Approved after two correction rounds; no unresolved issues                                                   |
| Environment baseline         | Complete | `/private/tmp/qmx-scanner-r1-p0.3nHF9k`; locked runtime proof and green `composer check`                     |
| Mode matrix                  | Complete | E1-E9 have evidence; post-fix cold/warm/no-cache parity restored; format/graph/layer overlaps pass           |
| Metric/oracle probes         | Complete | 1,240 focused metric tests, 56 hand probes, four-corpus no-cache health regression; F-004 found              |
| Manual architecture audit    | Complete | Top ten namespaces/classes and graph probes assessed; F-003 found                                            |
| Fixes                        | Complete | F-001 through F-005 fixed test-first; D-001 deferred as an unmade contract decision                          |
| Integrated validation/review | Complete | Final `composer check`: 6,794 tests / 17,125 assertions, 1 skipped; cross-tool, PHPStan, and selfcheck green |
| PHP 8.0-8.5 construct matrix | Complete | Frozen official-source oracle plus 10 clean repeated probes; F-006 through F-012 and F-014 recorded          |
| Boundary/anomaly matrix      | Complete | 24 frozen rows; 9/9 coverage, finite JSON, deterministic repeats; F-012 and F-013 recorded                   |

## Review Summary

The actual extended-review composition was one `native-codex` comprehensive
reviewer. Qwen was installed but excluded because the environment did not
provide the independently verified read-only OS/container sandbox required by
the review procedure; external Codex was not explicitly selected.

The reviewer was useful on cache reliability seams: it found both the
hash/parse ABA race and the source-identity regression in the first attempted
fix. It also found stale durable-status wording. Architecture configuration,
CLI migrations, comparator semantics, and their tests were reviewed without
additional confirmed findings. Two address rounds verified the final
in-memory snapshot contract; no confirmed review issue remains.

## Final Disposition

- **Fixed:** F-001 through F-005. The cache correctness fixes are one
  authority-restoring mechanism and do not consume an ordinary discovery slot.
- **Confirmed but intentionally unfixed:** F-006 through F-014. Per the user
  boundary, E10/E11 were diagnosis-only; remediation belongs to a separate
  round driven by this file.
- **Rejected as scanner defects:** documented/contextual CCN, NPath, Halstead,
  MI, DIT, ClassRank, LCOM, and external LOC divergences; hand and metamorphic
  QMX oracles passed.
- **Deferred:** D-001 requires a product contract for conditional duplicate
  FQNs before behavior can be called correct or incorrect.
- **Passed modern/boundary probes:** DNF types, asymmetric visibility,
  new-expression dereference, empty declarations/files, zero/one cohesion and
  type-cardinality boundaries, singleton/empty aggregation, inheritance
  boundaries, unresolved/dynamic targets, and partial health inputs. All E11
  JSON values were finite and health scores stayed in `[0,100]`.
- **Type-coverage interpretation:** explicit `mixed` counts as typed because the
  metric measures declaration coverage, not inferred precision. Dynamic targets
  correctly remain unknown/absent rather than creating fictitious dependencies;
  a separate precision/confidence signal would be a product feature, not a bug
  fix to the current metric.
- **Aggregation coverage:** regression, golden-pipeline, hand-algebra, and
  health-score checks cover method-to-class-to-namespace-to-project behavior,
  including parent namespaces and weighted averages. This round did not
  independently re-derive every metric-by-level `AggregationStrategy` registry
  entry, so it is not a formal exhaustive audit of the whole aggregation
  matrix.
- **Architecture interpretation:** graph and layer assignments were credible
  on representative source-backed probes after F-003. Health and hotspot scores
  remain evidence for human architectural decisions, not automatic refactoring
  commands.
