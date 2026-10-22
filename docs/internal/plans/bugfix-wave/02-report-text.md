# Session 02: report text that misstates the data or grows without bound

Issue: #160 items 4, 5, 6. Branch: `fix/report-text`.
Read `00-overview.md` first.

All three items change published text only. No finding, metric value or exit
code changes.

## Files this session owns

| File                                                                             | Item |
| -------------------------------------------------------------------------------- | ---- |
| `src/Analysis/Finding/Contract/Population/KeyThreshold.php`                      | 4    |
| `tests/Analysis/Finding/Unit/Contract/Population/PopulationPredicatesTest.php`   | 4    |
| `src/Reporting/Formatter/Summary/OffenderListRenderer.php`                       | 5    |
| `tests/Reporting/Unit/Formatter/Summary/OffenderListRendererDensityTest.php`     | 5    |
| `src/Reporting/Contract/Report/ReportProjectScope.php`                           | 6    |
| `tests/Reporting/Integration/ProjectScopePublicationTest.php`                    | 6    |
| `website/docs/usage/output-formats.md`, `.ru.md` (shared: project scope section) | 6    |
| `src/Reporting/README.md` (shared: only if it describes the scope line)          | 6    |
| `CHANGELOG.md` (shared)                                                          | all  |

## Item 4 — zero own classes reported as a missing metric

**Cause.** A namespace that only holds sub-namespaces has no
`size.class-count` key in its bag (`ClassCountCollector` publishes a bag only
for namespaces that declare something). Gates read it through `KeyThreshold`
with `missing: 'zero'`: an absent key is a measured zero by declared policy,
and `PopulationGate::isDataGap()` already treats it as no data gap. Only the
text disagrees: `KeyThreshold::evaluate()` (its final `return`, around line 53)
names the first absent key as "Missing metric" whenever one was absent, even
under `'zero'`.

Users of `KeyThreshold` with `'zero'` (all change wording together):
`CboRule` (gate `own-classes`), `DistanceRule` (gate `own-types`),
`ClassCountRule`, and `DataClassExclusionCheck` (two gates). Confirm the list
with `grep -rn "KeyThreshold\|keyThreshold(" src`.

**Change.** Under `missing: 'zero'`, a failed comparison returns
`Metric population boundary was not met.`; an absent operand is never named as
missing. The `'exclude'` path (around line 42) and the `'refuse'` path stay as
they are.

Do **not** add the measured value or the minimum to the text: abstention rows
are grouped by identical reason text, and values in the text would split one
row into one row per value.

**Tests.** `PopulationPredicatesTest::itKeepsZeroSubstitutionEligibilityButNamesTheFirstMissingFailedOperand`
pins the old behaviour on purpose. Replace it with a test whose name states the
new behaviour (for example `itTreatsAZeroSubstitutedOperandAsMeasuredWhenTheBoundaryFails`):
on an empty bag with `'zero'`, the reason is the boundary sentence and does not
contain `Missing metric`. Keep the sibling assertion that a present zero gives
the same sentence. Then `grep -rn "Missing metric" tests governance` and update
any test that asserts the old abstention reason for a `'zero'` gate; list each
in the report.

## Item 5 — duplicate declarations print identical rows

**Cause.** `OffenderListRenderer::renderWorstOffender()` prints only
`$offender->symbolPath->toString()`, the logical name, so three declarations
of `App\Dup` print three identical `100 App\Dup` rows. The row object
(`WorstOffender`) already carries the exact identity:
`$offender->subject->declarationPath()` gives the file (`->file->value()`) and
the ordinal (`->ordinal`, with `isFirst()` and `value`).

**Change.** Inside `OffenderListRenderer` only. In `renderOffenderList()`,
find the class names that repeat **in the full ranked list, before it is
sliced to the top N**, so whether a row gets a suffix does not depend on how
many rows are shown. Every rendered row whose name repeats gets the suffix
`(<file>)` for the first declaration in its file and `(<file>#<n>)` otherwise —
the convention of `DeclarationPath::toCanonical()`, where the first ordinal has
no `#`. Rows with a unique name are printed exactly as today. Namespace rows
have no declaration path and never get a suffix.

Out of scope: the JSON `worstClasses` records (they have `file` but no
ordinal — that is #169) and `HintRenderer`.

**Tests (OffenderListRendererDensityTest, same fixture style).** Three
offenders `App\Dup` at `src/one.php` ordinal 0, `src/two.php` ordinal 0 and
`src/two.php` ordinal 1 render as three different rows, the third with `#1`.
A unique name renders without a suffix.

## Item 6 — "Project scope narrowed" lists every unjudged selector

**Cause.** `ReportProjectScope::describeUnjudgedValues()` joins all
`unjudgedValues`; on this repository that is one 22 KB line. The line reaches
text, summary, health, SARIF, GitHub Actions and HTML through `describe()`.
The `json`, `metrics` and `suppressed` formats already carry the complete list
through `toArray()`.

**Change.** `describeUnjudgedValues()` lists at most the first three values
(keep today's order and spelling) and, when more exist, appends
"and N more; the json, metrics and suppressed formats list every value". Use a
private constant for the limit. `describe()` stays context-free: do not thread
`FormatterContext` or `--detail` into it (`--detail` governs the violation
list, and changing `describe()`'s signature would touch every narrator).
`toArray()` is unchanged.

**Tests (ProjectScopePublicationTest).** The existing tests use one skipped
value and must stay green unchanged. Add a test with 20 unjudged values across
the notice formats the file already iterates (`noticeFormats`): the output names
the first three, says `and 17 more`, and does not name the twentieth; and the
`json` publication still lists all 20.

**Docs.** In `website/docs/usage/output-formats.md` ("Project scope in every
format", around lines 1312–1365) and its `.ru.md` mirror: the text-like formats
name at most three skipped values; the JSON-family formats list every value.
Do not touch the namespace-selection sentence near line 127 (session 04).

## CHANGELOG (`### Fixed`)

- A namespace or class that falls short of a population minimum because a count is zero is reported as below the minimum, not as missing a metric.
- Repeated class names in "Worst classes" show their declaring file and ordinal.
- The project scope line names at most three skipped selectors instead of all of them.

## Session gate

After the overview ladder steps 1–5:

1. `composer docs:check` once (website changed).
2. `composer gate ...` once (overview, "The finding gate"). **Expected gate
   differences:** abstention `reason` texts that read `Missing metric "..."` for
   a `'zero'` gate now read `Metric population boundary was not met.` (JSON and
   metrics views); the project scope line in text-like formats, where a case
   has more than three unjudged values; "Worst classes" rows, where a case has a
   repeated class name. Nothing else.
3. `composer check` once (overview step 7).

## Definition of Done

- `grep -n "Missing metric" src/Analysis/Finding/Contract/Population/KeyThreshold.php`
  finds only the `'exclude'` branch.
- The three behaviours above are pinned by the named tests.
- On this repository,
  `bin/qmx check src/Analysis/Policy --no-cache --workers=0 --format=text | awk 'length > 2000' | wc -l`
  prints `0`.
- The printed exit code of the final `composer check` is 0.
