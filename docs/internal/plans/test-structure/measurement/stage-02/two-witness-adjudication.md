# Stage 02 — the `mixed` split, judged by two witnesses

`controls-verdict.tsv`'s `scope` column and the split map derived from it share
an author. A single reader who both enumerates a population and writes the
oracle for that enumeration errs in both consistently and passes its own check.
So the seventeen `mixed` files were read twice:

- **Witness 1** — given the TSV, produced `split-map.md`: which methods leave,
  what each half needs, whether the halves can be separated at all.
- **Witness 2** — given the criterion and the seventeen paths and **nothing
  else**, produced `witness2-method-verdicts.tsv`: a verdict for every one of
  the 127 `#[Test]` methods in those files, derived from code. The verdict
  files, the taxonomy, the notes and the plan were named as unreadable.

Witness 1's arithmetic reproduces this session's own census exactly — 33 cases
leaving, 189 staying — so the two agree on *how much* moves. What follows is
where they disagree on *what* moves.

## Disagreements that are not stage 02's

In 8 files witness 2 classified methods as `tooling-test` that the TSV counts in
the product remainder. Stage 02 moves `repo-control` only; whether the rest is
product or tooling is stage 03's question, and none of these changes a stage-02
move. They are recorded and left alone.

## The real disagreements: 11 methods, 5 files

| file                               | methods | witness 2    | TSV     | adjudicated      |
| ---------------------------------- | ------- | ------------ | ------- | ---------------- |
| `ConfigSchemaTest`                 | 3       | repo-control | product | **TSV**          |
| `RatchetKeyGrammarTest`            | 2       | repo-control | product | **witness 2**    |
| `BaselineCommandOptionSurfaceTest` | 4       | repo-control | product | **TSV**          |
| `DirectiveAuditReportReadingTest`  | 1       | repo-control | tooling | **witness 2**    |
| `ThresholdPopulationAgreementTest` | 1       | repo-control | tooling | **TSV**, flagged |

### `ConfigSchemaTest` — TSV stands

`itListsAllExpectedRootKeys`, `itIncludesDottedRootsAmongSectionKeys`,
`itReturnsOnlyListTypeKeys` check what `allowedRootKeys()` and `sectionKeys()`
return for named keys. The taxonomy's table-class hint adjudicates this exact
shape: an assertion about output logic on specific keys is product-test, an
assertion about closure over all rows is repo-control. Witness 2 applied
`census` to the whole file.

### `RatchetKeyGrammarTest` — witness 2 stands, and the file stops being mixed

The TSV keeps `itSplitsADeclarationKeyIntoItsFileAndItsOrdinal` and
`itRejectsAKeyThatStillCarriesAPosition` as product-test, reasoning "key grammar
on the test's own inputs". **The grammar is not in `src/`.** It is the file's own
`private static function parse()`, whose docblock is where the last-`@` rule is
decided at all; the two methods feed it synthetic keys to prove it bites, and
`itFindsNoPositionInAnyDeclarationKeyOfTheRepositoryRatchet` then runs the same
`parse()` over the keys of the tracked `qmx-baseline.json`. An instrument's
self-check inherits the instrument's class, so all three are one control.

Consequence, and the reason this correction is worth its cost: witness 1 had
listed this file as one of four needing `parse()` duplicated across the split.
Under the correction there is no split — the file moves whole to
`RatchetArtifact`, and one of the four duplications disappears.

### `BaselineCommandOptionSurfaceTest` — TSV stands

`FORBIDDEN_OPTIONS` and `REQUIRED_CONFIGURATION_OPTIONS` are lists the test
writes out of ADR 0017, not lists copied from the repository. Add
`--suppress-path` to a baseline command and the test reddens because the product
changed. Only `itKeepsRepositoryEntrypointsOnTheBaselineLifecycleSurface`, which
reads `action.yml`, `docker-compose.yml` and the hook, is a control.

### `DirectiveAuditReportReadingTest` — witness 2 stands

`itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday` walks
`DirectiveEffect::cases()` and requires the tooling's `MeasuredEffects` to agree
on every one. That is the same claim — agreement between two repository
vocabularies — that the TSV's own `reason` gives for the three sibling methods
it does name. The TSV is inconsistent with itself here, not with the criterion:
the method joins its three siblings in `DirectiveVocabulary`, making four.

### `ThresholdPopulationAgreementTest` — TSV stands, with the dispute recorded

`itNamesEveryFormTheFixtureDeclares` requires the hand-written provider to name
exactly the methods a fixture declares. It *is* a census by shape, but both
sides of it live in the test tree: it keeps a tooling test's provider honest
against that tooling test's own fixture. Moving it would separate a provider
from the census that guards it. This is the "control over `tests/`" boundary the
earlier notes called formally silent, and it is decided here by that reasoning
rather than by the criterion, which does not reach it. The file's tooling
remainder is stage 03's, and the method should be re-asked there.

## Effect on the prediction

| file                              | was | now | suite       |
| --------------------------------- | --- | --- | ----------- |
| `RatchetKeyGrammarTest`           | 1   | 8   | Integration |
| `DirectiveAuditReportReadingTest` | 3   | 4   | Unit        |

`prediction.md` carries the corrected totals. `controls-verdict.tsv` is a
measurement artifact of the audit that produced the plan and is **not** edited:
this file is where its two corrections live, and the packages read both.

## What this pairing cannot see

- Both witnesses read the same seventeen paths. A `mixed` file that the
  original audit classified whole — in either direction — is invisible to both,
  and nothing here re-opens the other 64 verdicts.
- The adjudication above is a third reading by the orchestrator, with a blind
  spot of its own. Each row states the criterion clause it turns on so review
  can disagree with the clause rather than with the conclusion.
- Witness 2 counted a `#[Test]` inside a heredoc — the decoy PHP file
  `ModularArchitectureGovernanceIntegrationTest` writes for the inventory
  generator to find — as a method of the enclosing class. It is not one. No
  count above includes it; PHPUnit never listed it.
