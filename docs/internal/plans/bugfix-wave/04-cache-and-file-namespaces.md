# Session 04: AST cache depth and namespaces of declaration-free files

Issues: #162, #163. Branch: `fix/cache-depth-and-file-namespaces`.
Read `00-overview.md` first, including "Environment facts" (Xdebug). The two
items share no file; do #162 first.

## Files this session owns

| File                                                                                                                          | Item |
| ----------------------------------------------------------------------------------------------------------------------------- | ---- |
| `src/Infrastructure/Cache/AstEntryCodec.php`                                                                                  | #162 |
| `src/Infrastructure/Cache/FileCache.php`                                                                                      | #162 |
| `src/Infrastructure/Cache/README.md`                                                                                          | #162 |
| `tests/Infrastructure/Cache/Unit/AstEntryCodecTest.php`                                                                       | #162 |
| `tests/Infrastructure/Cache/Unit/AstEntryProducerDigestTest.php`                                                              | #162 |
| `tests/Infrastructure/Cache/Unit/AstEntryDepthTest.php` (new)                                                                 | #162 |
| `src/Analysis/Evidence/Measurement/Contract/FileNamespaceIndex.php`                                                           | #163 |
| `src/Analysis/Evidence/Measurement/FileMeasurement/CompositeCollector.php`                                                    | #163 |
| `src/Reporting/FindingProjection/FindingNamespaces.php`                                                                       | #163 |
| `src/Reporting/DrillDown/DrillDownBinding.php` (shared with 01: the `NAMED_LEVELS` docblock sentence only)                    | #163 |
| `src/Analysis/Evidence/Measurement/README.md`                                                                                 | #163 |
| `src/Reporting/README.md` (shared: ~187–199 and ~637–640)                                                                     | #163 |
| `tests/Analysis/Evidence/Measurement/Unit/FileMeasurement/CompositeCollectorTest.php`                                         | #163 |
| `tests/Analysis/Evidence/Measurement/Unit/Contract/FileNamespaceIndexTest.php` (new)                                          | #163 |
| `tests/Reporting/Unit/DrillDown/FindingFilterTest.php`                                                                        | #163 |
| `tests/Infrastructure/Console/Functional/Check/CheckCommandDrillDownFormatTest.php`                                           | #163 |
| `website/docs/usage/output-formats.md`, `.ru.md` (shared: the sentence near line 127)                                         | #163 |
| `website/docs/usage/cli-options.md`, `.ru.md` (the sentence near line 1397)                                                   | #163 |
| `docs/adr/0105-finding-publication-and-drill-down.md`, `docs/adr/0106-declaration-metric-records-and-declared-publication.md` | #163 |
| `docs/adr/0102-detector-verdicts-and-finding-identity.md`, `docs/adr/README.md` (shared with 03)                              | #163 |
| `CHANGELOG.md` (shared)                                                                                                       | both |

Not yours: `FileCacheTest.php`, `CacheDirectoryContents.php` (session 01),
`CheckCommandBaselineTest.php` (session 06).

## #162 — deep ASTs are written but always refused on read

**Facts (measured).**
- `AstEntryCodec::validValue()` refuses values nested deeper than the literal
  `512`. Each node-to-child hop costs two levels (the node object, then its
  property array), so a left-nested concatenation of about 250 operands is
  refused. The encoder has no bound, so `FileCache::set()` writes such entries,
  `get()` discards them with a warning, and the cycle repeats every run.
- The validator (`validValue()` ↔ `validObject()`) is **recursive PHP**. Under
  Xdebug's 512-frame limit it throws on trees far shallower than any useful
  bound; the throw is caught and the entry is discarded.
- `unserialize()` is called without `max_depth`, so the ini default
  `unserialize_max_depth` (4096) applies; it counts fewer levels than the
  validator for the same tree. `serialize()` and `unserialize()` are C code and
  are not limited by Xdebug.
- `FileCache::set()` is the only production caller of `encode()`;
  `AstEntryCodecTest` (three calls) and `AstEntryProducerDigestTest` call it too.
  Full decode validation costs about 3 ms per file (one reflection per node);
  `encode()` costs about 0.1 ms.

**Decision.** One depth bound, shared by both directions and owned by
`AstEntryCodec`: a private constant of 4096 levels, counted as the validator
counts today (a node object and its property array are one level each, so one
nested `Concat` costs about two levels; all depths in this file are in these
validator levels). Reason (state it in one comment next to the constant): it equals
PHP's default `unserialize_max_depth`, so decoding never accepts more than PHP
does by default, and passing it explicitly keeps decoding independent of the
ini setting. A tree deeper than the bound is simply not cached. Every walk over
the tree is **iterative** (an explicit stack), so the result never depends on
PHP call depth or Xdebug.

**Contract changes.**
- One private iterative walk serves both directions. It visits values in the
  same depth-first order with the same visited-object rule as today's
  validator (a node reached twice is checked once; a node met again while it is
  still being checked is a cycle and refuses), and it has two modes: depth-only
  for `encode()`, and depth plus the class allow-list and initialization checks
  for `decode()`. Because both modes share one walk, anything `encode()` accepts
  passes `decode()`'s depth rule.
- `AstEntryCodec::encode(array $nodes): ?string` — returns `null` when the
  depth-only walk finds the tree deeper than the bound. It does not run the full
  validation (keeping the write path fast, and keeping a producer defect such as
  a foreign object visible as a discard on read). Because the walk runs first, a
  tree that reaches `serialize()` is far below the depth that exhausts the C
  stack; do not add a `catch (Error)` around `serialize()`.
- `decode()` passes `'max_depth' => <the constant>` to `unserialize()` and
  validates with the full mode of the walk.
  `AstEntryCodecTest::itRetainsSharedNodesWithoutTreatingThemAsAnActiveCycle`
  must stay green.
- `FileCache::set()` writes nothing when `encode()` returns `null`; it logs
  nothing and increments no discard counter.
- `FORMAT_VERSION` stays 1: every tree that was storable before encodes to the
  same bytes.

**Tests.**
- New `AstEntryDepthTest` (same directory and fixture style as
  `AstEntryCodecTest`). Build deep trees **in memory** (a loop nesting
  `BinaryOp\Concat` nodes), never from source files, so collectors are not
  involved and the tests behave the same with and without Xdebug:
  - a tree of about 300 nested `Concat` nodes (about 600 levels: deeper than
    the old 512, and beyond Xdebug's frame limit for any recursive walk)
    round-trips through `encode()`/`decode()`. Compare the result by
    `serialize()` bytes (`assertSame(serialize($tree), serialize($decoded))`),
    **not** with `assertEquals()` or a `NodeTraverser`: both recurse in PHP and
    fail under Xdebug at this depth;
  - a tree deeper than the bound makes `encode()` return `null`;
  - serialized bytes of a tree deeper than the bound (produce them with
    `serialize()` directly) make `decode()` return `null`;
  - `FileCache::set()` with a too-deep tree leaves no entry file, and a later
    `get()` returns `null` with `discardedEntryCount()` at 0.
- `AstEntryCodecTest` and `AstEntryProducerDigestTest`: adapt the `encode()`
  calls to the nullable return without weakening any assertion.
- No functional test with a deep source file: under Xdebug the collectors
  themselves fail on such a file (exit 4) before the cache is involved, which is
  outside this issue.

**Docs.** `src/Infrastructure/Cache/README.md`, codec paragraph (~lines
90–105): name the bound, that deeper trees are not cached, and that validation
is iterative.

## #163 — file-level findings in a file with no declarations lose the namespace

**Facts.**
- `FileNamespaceIndex::fromRepository()` builds `file → namespaces` from
  declarations only. `FindingNamespaces::of()` reads it for file-subject
  findings and falls back to `['']` when it is empty. So `eval()` in
  `namespace App\Svc;` without a class is published under `""` and dropped by
  `--namespace='subtree:App\Svc'` (the filter itself binds: LOC records the
  namespace, so it is not refused).
- The same index feeds namespace **aggregation**
  (`ClassToNamespaceAggregator`, `TreeAwareNamespaceAggregator`,
  `NamespaceMetricContributions`). Changing `namespacesOf()` would change
  namespace metric values; `NamespaceMetricContributionsTest::itDoesNotAttributeAnUndeclaredFileToANamespaceAggregate`
  pins that it must not.
- Per-file facts reach the coordinator inside the file `MetricBag`
  (`SuccessfulFileProcessing::$fileBag`), which crosses the worker boundary by
  serialization, data entries included (`MetricBag::__serialize()`), and is
  stored as the `SymbolLevel::File` symbol of the repository. Structured
  per-subject facts already travel as bag entries (`RfcCollector::FACTS`,
  `LcomCollector::FACTS`). `MetricBag::all()` returns numeric metrics only, so
  an entry is never published.
- The AST cache stores only AST nodes; every fact is recomputed from the
  (possibly cached) AST on each run. No cache format change is needed.

**Decision.** Record the lexical namespaces of every measured file as file-bag
entries, and let reporting read them, without touching the declared index that
aggregation uses and without adding a field to `SuccessfulFileProcessing`.

**Contract changes.**
- `FileNamespaceIndex` (Measurement contract, already imported by Reporting):
  - `public const string LEXICAL_NAMESPACE_ENTRY` with the value
    `'internal.file-lexical-namespace'`; entry shape `['namespace' => string]`.
    The key lives on this class because the index is its only reader and the
    collector of the same owner its only writer; `MetricName` also holds one
    internal entry key (`NAMESPACE_FILE_CONTRIBUTION`), so either place would
    be legal — this plan picks the subject-local one.
  - `fromRepository()` also reads, for every `SymbolLevel::File` symbol, the
    entries under that key from its bag.
  - `public function lexicalNamespacesOf(RelativePath $file): list<string>` —
    the namespaces the file's `namespace` statements open, distinct, in source
    order; `''` for a braced global block `namespace { ... }`; empty for a file
    with no `namespace` statement.
  - `namespacesOf()` is unchanged.
- `CompositeCollector::collect()` adds one entry per distinct top-level
  `PhpParser\Node\Stmt\Namespace_` to the file bag it returns, on both return
  paths (including the early return when no collectors are configured).
  `namespace` statements exist only at the top level of a PHP file, so no
  traversal is needed. Use a private method; do not add a collector class.
- `FindingNamespaces::of()`, file-subject branch: the sorted union of
  `namespacesOf()` and `lexicalNamespacesOf()`; `['']` only when both are
  empty. The union keeps index instances built from declarations only (as many
  tests build them) working, and keeps a global class's `''` where today it
  appears.

Before relying on the new entry, check that nothing enumerates every entry key
of a file bag (`grep -rn "DataBag\|->entries(" src`); report what you found.

**Behaviour this changes, all intended.** A file-level finding gains every
namespace its file opens: a declaration-free file goes from `""` to its
namespace(s); a file that declares a class in `A` and has a file-level finding
in `namespace B` goes from `["A"]` to `["A","B"]`.

**Out of scope, keep as is:** attribution by line (a finding in a file with
namespaces `A` and `B` carries both, exactly as findings in multi-namespace
files with declarations do today); the three namespace fallback readers ADR
0102 names (`NamespaceExclusionFilter` via `FindingNamespace::declared()`,
`SuppressionCompositionBuilder`, `FindingExclusionLedger`).

**Tests.**
- `CompositeCollectorTest`: lexical entries for `namespace A;`; braced `A` and
  `B`; two statements `namespace X; ... namespace Y;`; a braced global block
  (`''`); no namespace statement (no entry).
- New `FileNamespaceIndexTest` in
  `tests/Analysis/Evidence/Measurement/Unit/Contract/`: `lexicalNamespacesOf()`
  reads the entries from a repository's file symbols; `namespacesOf()` ignores
  them; a file bag with the entries survives `unserialize(serialize(...))`.
- `FindingFilterTest::itSelectsUndeclaredFileFindingsAsGlobalWithoutChangingTheirIdentity`
  pins the old behaviour: split it into "a declaration-free file with a
  namespace statement is selected by that namespace" and "a file with neither
  stays global", with names that say so.
- `NamespaceMetricContributionsTest` must stay green **unchanged**.
- `CheckCommandDrillDownFormatTest`: the issue's `script.php` and
  `withclass.php` both publish `namespace: "App\Svc"` in `--format=json` and
  both survive `--namespace='subtree:App\Svc'`; a braced `A`/`B` file's findings
  publish `namespaces: ["A","B"]`; a namespace-less script still publishes `""`.

**Docs.**
- ADR 0105 states "a file without declarations compares as the global
  namespace" (~line 22, ~64–70): append `## Amendment, <date>: a file's namespace statements select its file findings`
  (overview rule 7) with the new rule and the out-of-scope parts above.
- ADR 0106 (~115–121) and ADR 0102 ("Consequences", the file-subject note at
  ~47–48): add a one-line amendment pointing to the 0105 amendment (append a
  short `## Amendment, <date>` section to 0102; session 03 appends its own).
- `docs/adr/README.md`: `amended <date>: ...` clauses on the 0105, 0106 and
  0102 lines.
- `website/docs/usage/output-formats.md` (~127), `website/docs/usage/cli-options.md` (~1397) and
  their `.ru.md` mirrors carry the old sentence; correct it.
- `src/Reporting/README.md` (~187–199 and ~637–640) and
  `src/Analysis/Evidence/Measurement/README.md` (~40–44): describe the lexical
  record and who reads it.
- `DrillDownBinding` `NAMED_LEVELS` docblock: the sentence "A file without
  declarations uses the global namespace only when filtering" becomes false;
  correct that sentence only.

## CHANGELOG (`### Fixed`)

- Files with deeply nested expressions are cached; warm runs no longer warn about a discarded cache entry on every run.
- File-level findings carry every namespace their file opens, so namespace filters and grouping keep findings in files that declare no class.

## Session gate

After the overview ladder steps 1–5:

1. `composer docs:check` once.
2. `composer gate ...` once (overview, "The finding gate"). **Expected gate
   differences:** file-level findings whose published `namespace`/`namespaces`
   gain a namespace their file opens, and the groupings, counts and selections
   that follow from that. The corpus's known declaration-free files already
   carry a namespace through anonymous-class methods, so GREEN is likely.
   Anything else is a defect.
3. `composer check` once (overview step 7).

## Definition of Done

- `grep -n "512" src/Infrastructure/Cache/AstEntryCodec.php` finds nothing, and
  neither the encoder's measure nor the decoder's validation calls itself
  recursively.
- `XDEBUG_MODE=off` run of the issue's #162 reproduction prints no warning on
  the warm run; the #163 reproduction keeps both findings under the namespace
  filter.
- `git diff origin/main --stat -- tests/Analysis/Evidence/Measurement/Unit/Aggregation/ src/Analysis/Run/Contract/Collection/SuccessfulFileProcessing.php`
  is empty.
- The printed exit code of the final `composer check` is 0.
