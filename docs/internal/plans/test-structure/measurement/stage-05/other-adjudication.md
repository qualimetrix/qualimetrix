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

## What a repaired row looks like, for each of the four

The stage file carries a repair rule for each of its seven classes. A word
without one is five packages inventing five standards, which is the failure this
whole adjudication exists to prevent, so the four new words get theirs here. Each
is written so a package can say "done" against it rather than against taste.

**`weak-oracle` is repaired when the case fails for the defect its name
promises.** The package must be able to name **one edit to the production code
that the repaired case rejects and the old one accepted**, and say what it is in
the commit. Two shapes recur and settle differently:

- The claim is checkable and was not checked: assert it. `R058` — a fixture that
  sets `memory` and `peak_memory` and no assertion that reads either — is done
  when a rendered field changes and the case notices.
- The claim runs only sometimes. `R170` guards its assertion with
  `if (!isAvailable())`, so on a machine with parallel available nothing is
  checked. Done means the condition is gone from the body: either the fixture
  forces the branch, or the case is explicitly skipped when it cannot run.
  A claim that is silently satisfied is the defect; a claim that is loudly
  skipped is not.
- The promise is not checkable at all. Then the name is what is wrong, the row
  becomes `name-lies`, and it is repaired as one. **Renaming a case to match a
  weak oracle is a legitimate repair only when the package says so in the
  commit** — otherwise it is the defect with a new label.

**`brittle-pin` is repaired when the expectation is derived from behaviour
rather than copied from a literal.** Concretely, by shape:

- An exception message: assert the exception type, plus the subject it names
  (the rule id, the key, the path) as data — not the sentence around it.
  `R105`, `R112`, `R113`.
- A reflection snapshot of property and method names (`R107`): exercise the
  property instead. Immutability is proved by attempting the mutation, not by
  listing the members that would have to exist for it to be possible.
- A pinned description or literal (`R194`, `R195`): assert what the literal is
  for. If nothing downstream reads it, the case goes; if something does, assert
  through that thing.

**The exception is written into the class, not left to judgement: a pin whose
literal *is* the promise is not brittle.** A refusal that must name every known
key is promising that list to a user, so the list is the subject and pinning it
is the only oracle there is — that is why `R047` is `wont-fix` and not
`brittle-pin`. The test a package applies: would a reader of the product notice
this literal changing? Then it is a promise. Would only this test notice? Then it
is a pin.

**`state-leak` is repaired when the same construct that mutated the state
restores it, and the restore runs on failure too.** `tearDown()`, or
`try`/`finally` inside the case — never a restore at the end of a happy path,
which is exactly the line a failing assertion skips. For the three `chdir()` rows
(`R042`, `R043`, `R044`) that means capturing `getcwd()` in `setUp()` and
returning to it in `tearDown()`; for `R182` it means restoring the swapped static
the same way.

Done is checkable and the package runs it: **execute the leaking case and then,
in the same process, a case that depends on the pre-state, in that order.**
`--filter` over both, or `--order-by=defects`. A repair proved only by the
repaired file passing alone has proved nothing — the leak was never about that
file.

**`undeclared-subject` is repaired when the file declares, through
`#[CoversClass]`, the class its body exercises.** `R166`, `R167`, `R187` each
want one attribute. The one thing a package may not do is pick whichever class is
convenient: if the body exercises more than one subject, the missing attribute
was the symptom and the file's placement is the defect. Then the package says so
in the commit and the row is worked as `misplaced`, not closed by declaring one
of two subjects and leaving the other unnamed.

## The ruling five `wont-fix` rows stand on

Stated once, as a rule a package can quote, because five rows resting on five
separately-worded reasons that happen to agree is not a standard.

> **A case that feeds a control's own detector a hand-written input and asserts
> the verdict is that control's refusal proof, and refusal proofs are required
> here.** It is not a defect, and it is not to be deleted or merged into the
> control it proves.

`R015`, `R016`, `R041`, `R275`, and — from the other side — `R101`.

**The rule has to be usable against the thing it most resembles, so here is the
line.** Both a legitimate self-test and a tautology assert about the test's own
machinery. They differ in one place only: **where the expected side comes from.**

|                                | Refusal proof                                   | Tautology                                       |
| ------------------------------ | ----------------------------------------------- | ----------------------------------------------- |
| Expected side                  | written by a person, by hand, in the case       | computed by the code the actual side comes from |
| An edit to the production code | moves one side, so the case fails               | moves both sides together, so the case passes   |
| Deleting it                    | loses the only evidence the detector can refuse | loses nothing                                   |

**The question a package asks, and answers in the commit: can you name an edit
that this case rejects?** If yes, it is a proof and it stays. If every edit you
can think of moves both sides at once, it is a tautology and this stage is here
to repair it — `ChannelRenameMapTest::itAnswersTheSharedCorpusAsDeclared` is the
specimen, comparing `array_keys($map->renames)` with `oldNames()`, which returns
`array_keys($this->renames)`.

One narrower trap, since it is the cheap way to fake the proof: **a self-test
carrying only positive cases proves the detector fires, not that it refuses.** It
needs the input that must match and the input that must not. `R041`'s case has
both, which is why it is a proof rather than a decoration.

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
`reason` column. Four of them — `R015`, `R016`, `R275`, and `R101` for the
adjacent reason — share one ruling, stated once here; `R041` is the fifth case of
it, ruled the same way by the population adjudication rather than here:

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
