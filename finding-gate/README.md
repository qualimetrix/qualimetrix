# finding-gate — measured changes in an external corpus

The finding gate compares a candidate with a Git reference over the same
external PHP corpus. Every observable change must be explained by an exact
rename, a declared record change, a measured value change, a schema change,
an outcome change, a surface change or a bounded structural diff.
A declaration is evidence of what changed; it does not prove the new behaviour
is correct. Product tests make that separate claim.

Run `composer gate -- --reference=<commit>`. A complete comparison exits 0
only when all checks agree under the declarations. Re-run
`composer gate:controls -- --reference=<commit>` whenever the comparator changes:
an earlier controls run is evidence about an earlier comparator.

## Layout

```text
finding-gate/
├── cases/<subject>/
│   ├── case.json              # invocation, claims and expected outcome
│   ├── composer.json          # the analysed project's own root
│   ├── qmx.yaml               # configuration exercising the subject
│   ├── src/                   # independent PHP fixtures
│   └── baseline-src/          # optional earlier source population
├── maps/                      # exact vocabulary translations
├── declared-records.tsv        # selectors for introduced/withdrawn records
├── declared-records.derived.tsv
├── declared-values.tsv         # intentions for fields, metrics, exits and order
├── declared-values.derived.tsv
├── declared-fields.tsv         # added/removed report fields
├── declared-fields.derived.tsv
├── declared-outcomes.tsv        # case transitions and exact refusal snapshots
├── declared-outcomes/
├── declared-surfaces.tsv        # introduced/withdrawn publications
├── declared-surfaces/
├── declared-structural-maps.tsv # translated configuration paths
├── declared-delta.tsv           # exact residual diffs, after record/value work
├── declared-delta/
├── declared-field-moves.tsv     # exact field moves permitted inside a diff
├── normalization.tsv           # exclusions measured from repeated runs
├── equivalence-tuple.tsv       # physical finding fields derived from code
└── enumeration-*.tsv           # measured vocabulary and rename decisions
```

An absent optional declaration table means no declarations. Existing tables
must have their model's exact header. Every authored reason must be nonempty
and must not be `?`. A declaration that nothing consumes is a failure.

The executable and comparator classes live in `scripts/finding-gate/`;
negative controls live in `scripts/finding-gate-controls/`. The shared loaders
read a fixed set of subject-owned `wiring-*.php` files. Unknown files, unknown
keys, duplicate registrations and unloaded checks are refused. The retired
`pending` key is not accepted, even with an empty value. Every failure class
requires a producer and every raise site and caller requires an observed
self-test witness.

## Case definition and coverage

The corpus is external by construction: it must not read this repository's
product source. Both binaries analyse the candidate's fixtures, so changing
product source cannot silently change their common input.

```json
{
  "id": "example",
  "description": "One subject and the input that exercises it",
  "coverage": "authoritative",
  "paths": ["src"],
  "config": "qmx.yaml",
  "args": [],
  "channels": ["complexity.ccn@callable"],
  "explainSubjects": [],
  "layerAssignmentSubjects": []
}
```

`CaseDefinition::SCHEMA` is the exact schema. `id` must equal the directory
name. `args` and the two subject lists are optional. `coverage` defaults to
`authoritative`. An optional `outcome` declares
`{"kind":"refusal","exit":3}` or `{"kind":"incomplete","exit":4}`;
absence means an analysis outcome. `renameChannelsMap` names a contained map
file used by the baseline lifecycle.

Claims are `channel@level` pairs. Bare names, the retired `rule#code` claim
shape and unknown levels are refused. The level comes from the exact subject's
tag through `SubjectLevel`, which is held against the product's
`SymbolLevel`. Each case must fire exactly its claimed pairs.

An authoritative case owns its channels: exactly one authoritative case may
fire a channel, including a channel that fires at several levels. Auxiliary
cases exercise additional inputs and are compared on every surface, but do
not contribute to the coverage or ownership arithmetic. Empty `channels`
are allowed only when `coverage` is explicitly `auxiliary` and the outcome
is explicitly `refusal`; an analysing or incomplete case must claim evidence.

Coverage compares the observed pairs with the candidate's declarations.
Static declarations come from both its container and the tracked channel-level
fixture; their disagreement is `witness-disagreement`. Dynamic computed
channels have the container's resolved definitions as their declaration.
A missing pair is `coverage-shortfall`, an undeclared pair is
`coverage-surplus`, and two authoritative owners are
`coverage-multiplicity`. Losing one level inside an auxiliary case is still
a case-claim failure.

### Paths and input isolation

Analysis paths, config, preset files, PHP files, the channel map and any
`baseline-src/` population must stay inside the case after resolving links.
Missing inputs, absolute paths, escaping links and a linked case directory
are refused. A directory symlink contained within an analysis tree can be
a deliberate incompleteness fixture: the product reports it rather than
traversing it.

`CaseDefinition::inputFiles()` names the document inputs used by containment
and reference translation. Path-valued options follow
`CaseDefinition::INPUT_OPTIONS`; only presets are comma-separated.
`--baseline` in case arguments is refused: use `baseline-src/`, so each
side generates the format its own binary understands. Output, profiling,
cache-directory and working-directory options are refused in case arguments.
The gate owns their destinations and captures their publications.

Reference inputs are mechanically translated by the maps. All config and
preset documents, named PHP input positions, baseline source files, debug
subjects and rename-channel maps pass through the same translation boundary.
There is no separately hand-fitted reference fixture. Unsupported touched
syntax produces a named refusal instead of silently leaving an input unchanged.

Each command has a separate cold XDG cache directory. The gate also removes
the case's legacy `.qmx-cache` before and after invocations and verifies the
baseline cache behaviour it isolates. This prevents the second binary from
reading the first binary's AST as its own measurement.

### Outcomes and diagnostic messages

A refusal is compared as an outcome: stdout, stderr, process exit and any
file publication remain observable. If an authoritative analysis invocation
refuses before it can supply complete ranking metadata, capture stops with
`run-failed`. For publications that reach comparison, undeclared candidate
input refusals and untranslated reference inputs are named
`candidate-input-refused` and `reference-input-untranslated`. An explicit
transition uses the outcome declaration and its exact measured snapshot.

An incomplete case keeps its findings and its incompleteness diagnostics.
`baseline:generate` must exit 4 and publish no baseline file. Both facts are
checked; absence is not treated as an empty successful file.

Known run notices and exact analysis diagnostics are kept outside the finding
projection in GitHub, GitLab and Checkstyle. Their original bytes remain under
surface comparison. Unknown codes, diagnostic levels or shapes do not inherit
that treatment. HTML on two refusing sides is compared as complete refusal
text; analysis and incomplete outcomes require a readable report payload.

## Surfaces and invocation provenance

`CapturePlan` is the finite invocation table. It owns stdout, stderr, exit
and output-file keys, their command class and any ranking source. An absent
required key, an unknown key or an invalid source is refused.

Per case, the table captures the twelve `check` formats:
`summary`, `text`, `text-verbose`, `json`, `checkstyle`, `sarif`,
`gitlab`, `github`, `metrics`, `health`, `html` and `suppressed`.
It also captures `--show-suppressed`, `directives`, `rules`,
`graph:export`, baseline generation and named baseline explanations,
`check --output=<file>`, and named layer-assignment inspections.

A case with `baseline-src/` additionally captures its source JSON before
generation, `check --baseline`, baseline update, cleanup and, when it names
a map, channel renaming. A case with at least 100 distinct PHP inputs captures
a two-worker check in addition to the sequential check. File publications
retain their creating invocation's exit and stderr; they cannot invent another
process outcome.

Once per tree, `rules` and `graph:export` also run from a neutral directory.
The rules publication is a catalogue, not proof that it consumes case
configuration. Graph export follows its CLI paths and working directory;
a declared neutral failure is an outcome, not a successful graph population.

The authoritative record views are:

| Report       | Views                                                    |
| ------------ | -------------------------------------------------------- |
| `json`       | `format:json`, `check:baseline-source`, `check:baseline` |
| `suppressed` | `format:suppressed`                                      |
| `metrics`    | `format:metrics`                                         |
| `directives` | `directives`                                             |

The two baseline JSON views exist only for baseline-source cases.
`check:baseline` owns its records and ranking: accepted-level promotion
changes severity and impact, so it cannot borrow the ordinary check's values.
The output-file and parallel JSON invocations must agree with the ordinary
JSON check's physical records and ranking within the same side.

## Physical records and measured record changes

`ReportRecords::SCHEMAS` classifies every physical field of each report.
An unknown, missing or extra field is refused unless an exact schema
declaration covers it. A producer supplying no data is refused where it is
needed; it does not become an empty record set.

For JSON, the gate obtains complete physical authority even when the published
`violations` list is truncated. It first captures the same invocation with
`--top=<total+1>`. If physical records are capped, it obtains a second
support publication without presentation caps, with `--detail=all` and
`--format-opt=violations=all`. The original visible records must be the
physical prefix; totals, per-rule population, complete ranking, exit and
semantic stderr must agree. Hidden physical fields remain compared through
this complete authority.

A comparative JSON record is the physical record plus
`ranking.impactScore` and `ranking.coupling.class-rank`, joined from that
side's validated complete ranking. Record correspondence and value declarations
use the comparative form. Tuple supply, fingerprints, cross-format projections
and edits of published text use the physical form. Virtual fields are never
searched for as fields in a physical formatter's output.

`RecordCheck::pair` groups by report identity. A group with exactly one record
on each side is paired and compared by fields. Other groups pair only
byte-equal canonical records, preserving multiplicity. Unpaired instances
are introduced or withdrawn records. A value shift in a repeated identity
therefore needs a withdrawn/introduced pair, not a field intention.

The record intention table has columns
`change, case, report, view, selector, reason`.
Selectors are JSON objects of named scalar equality fields; metric selectors
require exact `type` and `name`. Overlapping selectors are refused.
`--derive-declarations` writes
`change, case, report, view, record` into
`declared-records.derived.tsv`. Each row licenses one exact canonical instance;
equal rows are a multiset. A neighbouring record is never licensed by another
record's declaration.

Each format's records are checked against its physical authority before a
declared record is removed or substituted. Checkstyle projections retain
multiplicity. SARIF catalogues and result indices are canonicalized together.
Baseline entries are joined to their complete source groups; counts and
magnitude lists must agree. Product groups that cannot be captured in a
baseline are not invented as entries.

## Ranking values, order and published slices

Ranking values use the ordinary field intentions
`ranking.impactScore` and `ranking.coupling.class-rank`.
There is one record correspondence for physical and ranking values.

Every JSON invocation has an exact ranking source. Ordinary JSON and the two
baseline JSON views each capture their own complete ranking. The output-file
and parallel invocations use the ordinary JSON source and must reproduce it.
A new JSON invocation without a valid source is refused while loading the
capture plan.

Within a side, the ranking schema is fully classified: finding projection,
the `debtMinutes` join to physical `techDebtMinutes`, ranking values and
`rank`. Complete physical records and ranked records must join bijectively
as multisets. A repeated join key carrying different ranking values is
`record-ambiguous`. Published ranks must be 1 through the population, and
published impact scores must not increase down the ranking. Added or removed
ranking fields use the `json/ranking` schema view.

### Order

A paired record has its reference comparative record as its label on both
sides. This keeps an explicitly changed message from creating a false
permutation. Equal labels retain their multiplicity.

Order is judged for unchanged ranking-value pairs whose labels occur in a
published JSON slice on either side. Their sequences in the complete rankings
must have a longest common subsequence containing all those occurrences.
An undeclared movement is `ranking-order-mismatch`.

To declare a permutation, author a value intention
`kind=order, key=ranking, level=*`. Derivation writes one row per moved
occurrence, with its case, view, label, occurrence number and positions among
the judged records. `RankingOrder` chooses the lexicographically smallest
sequence of reference/candidate index pairs among maximum-length subsequences;
judgment and derivation use that same algorithm. An unused intention is stale.

### Prefix and limit

Each published `topIssues` must equal the raw prefix of its own complete
ranking. A shifted or malformed prefix is `ranking-projection-mismatch`.

The limit is known exactly when fewer than all records are shown (`=k`);
when all are shown it is known only to be at least their number (`>=n`).
Across sides, these intervals must intersect. A changed limit is declared as
a field intention `topIssues.limit`, with derived `=k`/`>=n` values for
the exact invocation. `--top=0` is a valid empty slice.

Summary top-issue rows are checked inside each side against the ordered
physical records joined from its complete ranking: count, position, severity
tag, location, message, advice, debt and score. Score rounding follows the
renderer's decimal precision. Through sides, only the surrounding non-record
summary text remains byte-compared.

Internal ranking and physical-support publications are evidence inputs, not
additional cross-side surfaces or structural-diff targets. Their metadata is
checked inside the side. Both candidate passes validate complete physical and
ranked multisets, retaining duplicate counts. Support metadata, unrelated
health values and support formatting are not claimed as cross-side publications.

## Value, schema, outcome and surface declarations

| Table                          | Authored columns                      | What the run measures                                                          |
| ------------------------------ | ------------------------------------- | ------------------------------------------------------------------------------ |
| `declared-values.tsv`          | `kind, key, level, reason`            | Exact values, exits or moved occurrences in `declared-values.derived.tsv`      |
| `declared-fields.tsv`          | `change, report, view, field, reason` | Added values per case and record in `declared-fields.derived.tsv`              |
| `declared-outcomes.tsv`        | `case, transition, file, reason`      | Exact normalized refusal snapshot under `declared-outcomes/`                   |
| `declared-surfaces.tsv`        | `change, surface, file, reason`       | Exact withdrawal refusal under `declared-surfaces/`, or introduced publication |
| `declared-structural-maps.tsv` | `document, from, to, shape, reason`   | Exact translated document paths, retaining the declared value shape            |

Value kinds are `field`, `metric`, `exit` and `order`. Field and metric
intentions use an exact subject level or `*`. Exit intentions name a command
class from `DeclaredValues::COMMANDS` and require `*`; process exit
declarations must agree with report exit carriers such as directives'
`exit_code`. A shared key never authorizes an unmeasured neighbouring subject.

Schema changes are `added` or `removed`. Reports and views are exact,
including `json/ranking`. Derivation must obtain every required supplier;
a missing supplier cannot produce an empty successful table.

An outcome declaration names the transition and its refusal file. Repeated
refusal outputs are still compared. A withdrawn surface is still invoked on
the reference and must meet its exact declared candidate refusal. Declaring a
withdrawal does not suppress a broken reference. Introduced and withdrawn
surface rows never disable neighbouring publications.

Structural maps translate named paths in configuration documents. Their
closed document and shape vocabularies live in `DeclaredStructuralMaps`;
the translated input is validated before reference execution.

## Maps and translated inputs

All maps have `old, new, reason` columns. Self-renames, chains, conflicting
roles, duplicate sources and idle rows are refused. Credit belongs to the
exact row and, for a split input, every image must actually be used.

| Map                 | Direction                                            | Subject                                                               |
| ------------------- | ---------------------------------------------------- | --------------------------------------------------------------------- |
| `channels.tsv`      | Forward                                              | Published channel identities and explained producer splits            |
| `symbols.tsv`       | Both                                                 | Named PHP symbols or paths in supported output/input positions        |
| `metric-keys.tsv`   | Forward                                              | Metric keys and their closed aggregation spellings                    |
| `inputs.tsv`        | Both where invertible; split input rows reverse only | Rule option keys, CLI aliases, selector tokens and YAML key spellings |
| `report-values.tsv` | Forward                                              | Enumerated quoted values of `format:suppressed`                       |

Product baseline rename-table inputs use `old/new/reason`. Only the two
channel cells are reversed; reasons, comments and line endings retain their
original bytes.

Forward restates reference output in the candidate vocabulary. Reverse
restates candidate inputs in the reference vocabulary. A channel collapse can
be many-to-one forward; reverse translation must be a function.
A producer split is judged record by record, using identity and the actual
published move. A matched row that moved nothing stays stale.
Its full physical population supplies the matched producer movements, including
records hidden by a publication cap. The comparative `violationsMeta.byRule`
counts follow only those matched rule-field movements. Original counts must
first agree with that side's complete authority, including output-file aliases;
a declaration cannot repair an incorrect bucket or infer an unmatched move.

Metric aggregation suffixes come from both products. A declared strategy
rename is handled by `AggregationRenames`; an unexplained suffix difference,
ambiguous base spelling or doubled suffix is refused. A metric row applies
to the closed aggregated spellings of that same row, not arbitrary substrings.

Input tokens include `rule:option-key`, `--flag`, a dotted selector name
and a YAML key spelling ending in `:`. Bare unqualified words are refused.
One old input token may map to several candidate tokens separated by `|`;
those are usable in the reverse direction only.

Document translation uses `YamlInputMap`, not a blanket text substitution.
It edits named plain block-key or supported scalar positions and verifies the
parsed document changed only where declared. Comments and unrelated strings
stay intact. A touched flow key, quoted key, alias, unsupported selector reach
or formula is refused. A metric formula is a grammar, so a name map does not
silently rewrite its expressions.

`PhpInputMap` translates named qmx directive targets in real comments and
exact executable FQNs or explicitly aliased imports. Backtick/fenced examples
remain documentation. Touched namespace declarations, implicit names,
unsupported method/declaration shapes, dynamic names and string literals
refuse; they do not receive inferred renames.

Report-value rows apply only to quoted enumerable values in the suppressed
report. A plain word in its prose is not translated. Renaming a padded display
name can also move alignment, which requires a residual structural diff.

## What a fingerprint is compared by

GitLab and SARIF hashes are recomputed from each side's own published identity
before comparison. A channel rename legitimately changes its hash, so the
gate then substitutes a comparable identity in that publication. A hash that
cannot be recomputed is `fingerprint-opaque`, and a wrongly recomputed one is
`fingerprint-mismatch`. Occurrence and edge discriminators stay part of the
identity; equal records do not lose multiplicity.

## What publication order is compared by

The ordinary JSON finding array and baseline entries must already obey their
producer's identity order on the raw publication. A violation is
`published-order-drift`. Reference translation then re-establishes that
identity order; the gate never repairs a raw producer-order defect.
Ranking order is judged separately by the occurrence-preserving rule above.

## Normalization and residual diffs

`normalization.tsv` keeps the header
`surface, locator, kind, reason`. It is measured by
`--derive-normalization` over five passes of one unchanged tree.
Every pass is judged. Failed, empty or semantically different captures refuse
the write; a narrowed corpus cannot derive the list.

An exclusion must be exercised in a whole run or it is
`normalization-stale`. A locator may not reach compared record fields:
both its spelling and its effect on physical records are checked.
A violation is `normalization-overreach`.

The timestamped warning case keeps the stderr clock row live. It excludes
only a valid `[HH:MM:SS]` clock on a `[WARNING]` line. The level, message,
line ending and other stderr bytes remain compared. Output-path exclusions
are measured separately; they cannot replace a whole diagnostic. Internal
support metadata uses its own exact clock handling and does not credit a
public normalization row.

JSON is compared as published bytes. `JsonText` edits named spans without
re-encoding unrelated layout, escaping or number spelling. HTML payload
extraction likewise retains its JSON bytes while excluding the viewer bundle;
two refusal sides retain complete refusal text instead.

`declared-delta.tsv` has `surface, file, reason` columns and exact unified
diffs under `declared-delta/`. A row can name one case surface or a surface
class with the same measured diff across its cases. The measured diff must
equal the declaration. Unused rows, excessive changes and unexplained record
field moves are respectively `delta-stale`, `delta-too-large` and
`delta-overreach`. Size counts actual changed lines, not context padding.
A diff whose decomposition cannot be computed within its limit is refused.

`declared-field-moves.tsv` has `surface, field, from, to, reason` columns.
It permits one exact typed field move inside a separately declared diff;
it does not authorize a record population change. The field must actually be
published and readable on that surface. An unused row is `field-move-stale`.

Declarations belong to a particular reference comparison. Retire consumed maps
and declarations when the next reference already contains their change;
carrying them forward creates stale exceptions.

## Derivation and verdicts

Author intentions and reasons, then run
`composer gate -- --reference=<commit> --derive-declarations`.
It is the single writer for measured record, value, field, outcome, surface
and residual-diff tables. Unexplained record, value and schema changes still
fail. A failed run writes nothing. Inspect the generated data, supply any new `?` reasons, and
run the ordinary comparison to obtain a verdict.

`--derive-tuple` derives physical finding fields from publishing code.
`--derive-normalization` measures repeatability. They are separate operations
because neither is derived from cross-side change intentions.

| Exit  | Meaning                                                           |
| ----- | ----------------------------------------------------------------- |
| 0     | GREEN: the complete comparison agrees under its declarations      |
| 1     | RED: a comparison check failed                                    |
| 2     | PARTIAL: no failure, but no complete equivalence claim            |
| 3     | The gate could not run its declared comparison                    |
| 4     | A derivation wrote data; this is not a verdict                    |
| 5     | A derivation's measurement failed and nothing was written         |
| 128+n | A signal stopped the run, including refusal of a derivation write |

`--cases=<names>` makes a successful narrowed comparison PARTIAL.
Unknown names are refused even beside valid names.
`--incomplete-corpus` downgrades a coverage shortfall to a warning and makes
that run PARTIAL. With complete coverage, the flag does not change the verdict.
Neither option is accepted for declaration or normalization derivation.
Use `--report=<file>` for the machine-readable outcome.

## Execution, controls and independent checks

The three waves remain ordered: candidate 1, candidate 2, reference.
Cases have independent working directories and a bounded pool
(`--jobs=1..16`, default 4); commands inside one case retain their order.
Completed artifacts are merged in corpus order.

Progress is emitted on the gate's stderr, separately from captured product
stderr. Product commands have bounded deadlines, descendant cleanup and
heartbeats. The runner requires its process-supervision extensions and tools.
Temporary reference worktrees and scratch data are released on normal exits,
failures and handled interruptions. SIGKILL cannot run cleanup.

`composer gate:controls` runs each declared mutation on an independent clone
with its own repository. It resolves the reference before cloning and leaves
the developer's tree unchanged. A red control requires its declared failures
at declared scopes and rejects everything else. Tolerations must be exercised;
idle tolerations fail. Corpus controls require exact full scopes, preventing
a `text` expectation from absorbing `text-verbose` or another case.
Green controls are held to all declaration counts, not just exit 0.

`composer gate:self-test` runs the gate's observed witnesses and the
controls harness's mechanics. Every raise site is enumerated with its nearest
caller and observed through a whole synthetic run. An unexplained source
occurrence or stale source exception fails. Controls add corpus evidence;
they do not replace those observed self-test witnesses.

`composer gate:phar` compares an existing nonempty `build/qmx.phar` with
its committed tree. It copies the same archive bytes into a private candidate
with real dependencies and a launcher requiring that archive; it does not
build a substitute. Archive digest checks bind the supplied and compared
artifact. Packing defects outside captured publications remain outside this
comparison.

The corpus is also read by the channel-level drift governance control and
the rename/runtime-channel generators. `composer enumeration:renames`
measures current vocabulary and preserves authored rename decisions and
executed history. Its three output inventories are excluded from occurrence
counts: otherwise a new metric counts its own newly written row on the next
run. All three inventories still have freshness consumers.

## What GREEN does not prove

Each limit needs its own product or delivery check:

- Correctness of newly declared values or added field values: product tests and
  direct fixture runs must establish it.
- Git scope and `--report=git:*`: the external corpus is not a Git repository;
  use Git integration tests.
- Cache correctness: comparisons deliberately use cold caches; use cache tests.
- FIFO, permissions and file-system races: a contained directory symlink is
  covered, other file-system cases need dedicated discovery tests.
- Hooks, worker cgroup or disabled-function environments, and packaging outside
  captured surfaces: use their integration or delivery checks.
- Positions of records with changed ranking values, or introduced/withdrawn
  unpaired records: ranking order judges unchanged paired values only.
- Ranking order outside both published slices: use product ranking tests.
- Algorithmic correctness inside an explicitly changed ranking value: use impact
  and coupling tests.
- Changing which findings participate in complete ranking is not declarable
  here: the physical/ranking join refuses it. A feature needing this change must
  introduce and prove its own form.
- Internal support metadata, health values, aggregates and presentation are not
  independent cross-side surfaces; only their named source-consistency and
  repeatability invariants are checked.
- Capturability of a new baseline channel: the gate checks published source
  groups, not whether the product chose every eligible group; use baseline tests.
- Configuration consumption by `rules` or `graph:export`: their catalogue
  and path/cwd semantics do not prove configured rule execution.
- Selector reach or metric-expression grammar after a split: unsupported touched
  forms refuse; use selector and computed-metric tests.
- Dynamically assembled source method names, overridden dispatch and distinct
  execution paths with the same nearest caller: source enumeration does not
  resolve those runtime behaviours.

A GREEN run against identical product code proves the corpus, capture and
normalization are consistent. To claim a product change, compare with the
commit before that change and also supply the independent checks above.
