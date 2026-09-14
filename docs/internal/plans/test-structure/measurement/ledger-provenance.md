# Provenance of `defect-ledger.tsv` rows

**276 rows, 219 unique files. By class: dupe 104, other 62, misplaced 46, category-wrong 32, tautology 14, name-lies 9, stale-doc 8, never-runs 1. By severity: medium 150, low 102, high 24.**

## What each source contributed

The "rows from source" count is by occurrence of the source in the `source_report` field; a row
found by two sources counts for both, so the sum exceeds 276.

| source                             | rows | of which new | comment                                                                                                              |
| ---------------------------------- | ---: | -----------: | -------------------------------------------------------------------------------------------------------------------- |
| `identical-bodies.txt`             | 57   | 56           | 20 groups of byte-identical bodies; 56 rows — one per group member, plus 1 addition to an existing row               |
| `10-infrastructure-core`           | 57   | 0            | wave 1                                                                                                               |
| `07-measurement-computed`          | 24   | 0            | wave 1                                                                                                               |
| `01-finding`                       | 21   | 0            | wave 1 (synthesis of groups A–D)                                                                                     |
| `08-evidence-rest`                 | 19   | 0            | wave 1                                                                                                               |
| `09-configuration-run-vocabulary`  | 19   | 0            | wave 1                                                                                                               |
| `04-console`                       | 18   | 0            | wave 1                                                                                                               |
| `05-reporting`                     | 17   | 0            | wave 1                                                                                                               |
| `06-codesmell-security-complexity` | 13   | 0            | wave 1                                                                                                               |
| `02-baseline-inline`               | 10   | 0            | wave 1                                                                                                               |
| `01c-groupC`                       | 11   | 2            | 8 additions, 1 pre-existing row, **2 new**; the "correct directory" and "defects in brief" columns were read in full |
| `03-architecture-system`           | 8    | 0            | wave 1                                                                                                               |
| `01b-groupB`                       | 9    | 3            | 5 additions, 1 pre-existing row, **3 new**; all three table columns were read in full                                |
| `01a-groupA`                       | 6    | 0            | every group-A finding is already merged into the `01-finding` synthesis; added as a second witness                   |
| `01d-groupD`                       | 2    | 0            | 2 additions; the directory and defects columns were read in full, no new defects in them                             |
| `cross-slice`                      | 5    | 0            | wave 1                                                                                                               |
| `deep-05-03`                       | 3    | 1            | **1 new** (name-lies) + an addition to both rows of group 6 (`SummaryEnricherTest` 295 and 487)                      |
| `deep-06b`                         | 2    | 0            | confirms the same `SummaryEnricherTest` duplicate, no findings of its own beyond it                                  |
| `deep-02`                          | 1    | 1            | **1 new** (a `ChannelRenameMapTest` tautology)                                                                       |
| `untested-methods.txt`             | 1    | 0            | the single method matched an already-existing `never-runs` row                                                       |
| `deep-06a`                         | 0    | 0            | **zero findings**: the author examined all 7 borderline cases and explicitly rejected them                           |

Total rows: was 213, is now 276, **+63**. Of these, 56 are `identical-bodies.txt` group members,
7 are new findings from the reports (`deep-02` 1, `deep-05-03` 1, `01b-groupB` 3, `01c-groupC` 2).

In the group A–D tables, not only the "Duplicates and contradictions" sections were read, but also
the "correct directory" and "defects in brief" columns in full. For group A and group D, no new
rows arose from these columns: everything named a defect there is already in the ledger from
`01-finding` (the remaining cells are "matches"/"no" or an explicit "not a defect" note).

**Changed severity: 13 rows**, all in the `tautology` class, `medium` → `high`.
`name-lies` (9) and `never-runs` (1) were already `high`, no change needed.

## Conventions adopted (otherwise the ledger cannot be checked mechanically)

1. **`identical-bodies.txt` → one row per group member**, not one per group.
   `class=dupe`, `counterpart` is the nearest group member (the second one, for the first member),
   `note` names the method, the group size, and the nearest member. This is the only way to make
   the requirement "every file from `identical-bodies.txt` is present in the ledger" checkable: a
   group of 13 methods lives in 13 different files, and a single-path row would leave 12 files out
   of the ledger.
2. **A group already fully described by an existing row does not spawn new rows**, it instead adds
   itself to that row's `source_report`. There is exactly one such case:
   `ComputedMetricRuleOptionsTest` (lines 16 and 32) — the wave-1 row "four tests check one fact,
   'enabled by default'" describes exactly this defect. The other 13 files that appeared both in
   the earlier ledger and in `identical-bodies.txt` were there for **different** defects (different
   methods, different lines) — their groups were entered as new rows. Of these 13, only 10 stood
   in the `file` field, another 3 only in `counterpart`; that is, in the earlier ledger, 11 of 33
   were present as a row's own file, and 22 were absent.
3. **One defect — one row, but two classes — two defects.** The `class` field is single-valued,
   and the DoD forbids a class outside the list, so
   `SummaryEnricherTest::itTypingNotAddedWhenNoDimensions` stands as two rows: `dupe` (487, sources
   `identical-bodies,deep-05-03,deep-06b`) and `name-lies` (487, `deep-05-03`, high). This is not a
   ledger duplicate: the duplicate is cured by deleting one of the two bodies, the lying name by
   rewriting the name, and one without the other leaves the defect in place.
4. **The severity rule was applied to all rows at once**: `tautology`, `name-lies`, `never-runs` =
   high. This contradicts the literal scale in the brief, where "tautology" is listed under medium;
   an explicit caveat was applied ("name-lies and tautology are high by definition"), because it
   matches the definition of high, "incapable of catching its own defect". The contradiction is
   named here so review sees the choice, not a silent decision.
5. **`dupe` from `identical-bodies.txt` = medium**, even when the group is clearly legitimate.
   Example: the 13 members of `itDeliberatelyDoesNotProvideCallableMetrics` are deliberate parallel
   coverage of 13 different collectors, and the cure here is not deletion but a shared
   trait/abstract base case with a single assertion. The same goes for `itReturnsValidJson` (3
   formatters) and `itExportsValidJson` (2 exporters): by the same logic with which `deep-06a`
   rejected the `itKeepsLegacyFingerprints…` pair (different SUTs, parallel coverage of one
   contract through two formatters), these groups also read as an honest pattern repeat. The brief
   prescribes the `dupe` class for every group — the prescription is followed, the discrepancy is
   recorded here. Caveat: `itExportsValidJson` was not part of the `deep-06a` slice at all (that's
   `Infrastructure/Profiler`), its bodies were not read in wave 2, and there is no report judgment
   on it — only this reading of `identical-bodies.txt` of mine.

## What was deliberately NOT entered

- Seven borderline cases examined and rejected in `deep-06a` (the `FormatterRegistryTest` magic
  literal, paired GitLab/Sarif fingerprint tests, two `JsonFormatterTest` pairs, the
  `SarifRuleDescriptorCoverageTest` constants, `MetricsJsonFormatterTest`,
  `SarifFormatterPosixSeparatorTest`).
- `OutputFormatResolverTest` ↔ `OutputFormatRefusesUnexecutableValuesTest` (`deep-05-03`): the
  overlap is real, but it's one assertion out of two, the author declined to call it a duplicate.
- `itReturnsUnchangedReportWhenNoMetrics` / `itNullMetricsReturnsUnchangedReport` in
  `SummaryEnricherTest` (`deep-06b`): the second is a strict subset of the first, but not a
  byte-for-byte duplicate.
- `readExcludedFixtureKeys()` in `ChannelCoverageTest`/`ChannelEmissionStaticGuardTest`
  (`01c-groupC`): the duplication is declared a deliberate trade-off in the product's docblock.
- The `Drift/Assembly/Refusal`, `Completeness/Drift`, `OccurrenceKind/OccurrenceLeaf` triads —
  examined and found to be different facets of the invariant (except `Completeness/Drift`, which
  stands in the ledger as a dupe by run cost, not by assertion).

## What this method cannot see

- **The ledger is a union of reports, not a measurement.** It sees nothing the reports didn't see;
  the ledger's completeness equals the completeness of wave 1 + four deep readings, not the
  completeness of the test tree.
- **The columns in the group A–D tables are the group author's judgment, not a measurement.** A
  "no" cell means "found nothing", and for the largest files (`RuleOptionsFactoryTest`, 2194 lines)
  group A's author states outright that the middle of the file was read by method names, not line
  by line.
- **`deep-05-03` did not close out Reporting completely**: it did not read the bodies of 36 files
  (`deep-06a`/`deep-06b` finished reading them, but only for three defect classes —
  duplicate/tautology/lying name). The `misplaced`, `category-wrong`, `stale-doc` classes for these
  files rest on wave 1's structural signal alone.
- **`identical-bodies.txt` catches only byte-for-byte equality** of normalized bodies. A duplicate
  that differs by one line, assertion order, or how the same case is constructed does not enter it
  by construction — and, as the borderline case in `deep-06b` shows, such pairs do exist in the
  tree.
- **Ledger-row deduplication was done by `(file, line, class, counterpart)` plus manual note
  matching.** Two reports that described one defect in different words and without a matching line
  number (the `line` field is empty for most wave-1 rows) will remain two rows — the check for this
  is not mechanized.
- **No test was ever run.** Every class except `never-runs` is derived by reading the code.
  `never-runs` is the only one taken mechanically (`untested-methods.txt`), and it covers only one
  mechanism of non-execution (a method without `#[Test]`), not all of them.
- **Adding a second source (`01a`–`01d` to `01-finding` rows) was done by manually matching
  wording.** This is an assertion of "the same defect", not proof: if the wave-1 synthesis narrowed
  a group's finding, the addition hides the narrowing instead of showing it.
