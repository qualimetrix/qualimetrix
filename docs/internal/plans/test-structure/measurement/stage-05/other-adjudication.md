# The 62 `other` rows, judged once

`other` is the ledger's "mixed observations, triage before acting". Seven
packages triaging it independently would produce seven standards, so it is
judged here, once, and the result is
[`other-adjudication.tsv`](other-adjudication.tsv): one row per ledger row,
`row_id` / `assigned_class` / `reason`.

**No row is left `other`.** The ledger's `class` column is frozen — the ledger is
a measurement taken at `585b7c72` — so the reassignment lives here and the
packages read it beside the ledger.

Every row was re-confirmed against the body it describes today, not against the
note. Ten did not survive that reading and are closed as `already-fixed`; their
`assigned_class` records what the row would have been, so "no row is still
`other`" is provable for them too.

## The vocabulary, and why it is eleven words and not seven

Seven of the ledger's eight classes are used as they stand. Four rows in ten
needed a word the ledger does not have, and forcing them into the seven would
have misdescribed the repair rather than shortened the vocabulary — a
`chdir()` with no restore is not "labelled unit but does I/O", and a file with
no `#[CoversClass]` is not a docblock contradicting its code.

The alternative considered and rejected was to spend `wont-fix` on them. That
would have dismissed real defects to keep a table tidy, and it is exactly the
drift the recorded prediction exists to make visible.

| Word                 | From    | Means                                                                                                          |
| -------------------- | ------- | -------------------------------------------------------------------------------------------------------------- |
| `dupe`               | ledger  | same assertion as a named counterpart                                                                          |
| `misplaced`          | ledger  | the file, or the namespace it declares, names a subject its path does not                                      |
| `category-wrong`     | ledger  | the level the file is filed under is not the level the body works at                                           |
| `tautology`          | ledger  | cannot fail                                                                                                    |
| `name-lies`          | ledger  | the body does not exercise the subject the name or `#[CoversClass]` declares                                   |
| `stale-doc`          | ledger  | the docblock contradicts the code                                                                              |
| `never-runs`         | ledger  | not executed                                                                                                   |
| `weak-oracle`        | **new** | the assertion can fail, but not for the defect the name or docblock promises to catch                          |
| `brittle-pin`        | **new** | the expectation is an incidental literal — message wording, a name list, a structural snapshot — not behaviour |
| `state-leak`         | **new** | the case mutates process or static state and does not restore it                                               |
| `undeclared-subject` | **new** | the file carries no `#[CoversClass]`, so it declares no subject at all                                         |

`weak-oracle` is deliberately not `tautology`: a tautology cannot fail at all and
is `high` for that reason, while a weak oracle fails for the wrong things. Rows
that turned out to be tautologies on reading would have been moved into the
`high` set; none did.

## Where the 62 landed

| Class                | Rows |
| -------------------- | ---: |
| `category-wrong`     | 13   |
| `misplaced`          | 11   |
| `wont-fix`           | 8    |
| `weak-oracle`        | 8    |
| `brittle-pin`        | 7    |
| `name-lies`          | 5    |
| `state-leak`         | 4    |
| `dupe`               | 3    |
| `undeclared-subject` | 3    |

## The `wont-fix` share against the prediction

`orchestrator-predictions.md` predicted **12-20 rows (20-32%), centre 16**, and
asked for a row-by-row account of any overshoot. The observed share is **8 rows,
12.9%** — below the band, so the account owed is the opposite one.

Three readings of the same 62 rows:

| Denominator                                                 | `wont-fix` | Share |
| ----------------------------------------------------------- | ---------: | ----: |
| all 62                                                      | 8          | 12.9% |
| the 52 whose observation is still live                      | 8          | 15.4% |
| "rows that reach no package" (`wont-fix` + `already-fixed`) | 18         | 29.0% |

The prediction's own reasoning names the risk it was guarding: an adjudicator
clearing a queue drifts toward dismissal. The third row of that table lands
inside the predicted band, which suggests the prediction was about *rows that
reach no package* and that `already-fixed` — a category the prediction does not
mention — absorbed the difference. Ten rows closed that way, and nine of the ten
are one shape: a control invariant living inside a unit file, which is precisely
what `c49fc0b4` moved out of the test tree. That shape dominates the `other`
notes and would otherwise have been the largest source of dismissals.

The eight dismissals are not one shape and are argued individually in the TSV's
`reason` column. Five of them (`R015`, `R016`, `R041`, `R275`, and `R101` for the
adjacent reason) share one ruling, stated once here:

**A control's oracle self-test is the design, not a defect.** This repository
requires a control to be shown able to refuse — `composer gate:controls`,
`composer directives:controls`, `composer directives:controls:coverage` — and
gets that by putting a hand-built case beside the detector. Four `other` notes
describe exactly that arrangement as "a test of the file's own helper, not the
product". The arrangement is what the repository asks for. `R101` is the same
argument seen from the other side: its oracle re-implements a grammar rather than
calling a product parser, and there is no product parser to call, so the
independence the note calls a divergence risk is the only thing keeping the case
from being a tautology.

The remaining three are unrelated to each other: `R047` pins a refusal's key list
on purpose, and `R138`/`R139` assert that a method is absent, which has no
behaviour to observe.
