# Stage 03 — tell "could not measure" from "measured zero"

## The question

An unresolvable ancestor scores as a root class. So does a class that genuinely
has no parent. The report has one number for both, no separate state, and the
collectors have no logger. Stage 02 splits the outcomes internally
(`ReachedRoot`, `NoMapForIt`, `BrokeAt`); this stage decides what, if anything,
a user sees.

## Why it is last, and deliberately unfinished here

Designing the channel now would fix a shape before its distribution is known.
The one measurement in hand says the states are not evenly spread and depend on
the install: for qmx on its own `src/`, 7 of 7 chains reach a root and the other
two states are empty; for a library inside a shared vendor, 20 reach a root,
1 breaks partway and 1 has no map. A channel justified by the second shape may
be noise in the first.

So the first work item is a count, not a design: after stage 02 lands, run the
corpus and record the distribution of the three states per project. Then choose.

## The candidates, and what each costs

| candidate                              | what it costs                                                                                                                               |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| a project-level counter in the report  | touches `finding-gate` enumeration, the html-report metric-key catalog test and `website/docs/reference` — a new published key is a surface |
| a debug log line                       | needs a logger the collectors do not have; cheapest to add, invisible unless asked for                                                      |
| an existing run-incompleteness surface | `coverage.complete` and the directives command already carry a "run incomplete" verdict; reusing a shape beats inventing one                |

Before inventing a key, sweep `Analysis\Run` and the formats for the existing
"incomplete" shape and say whether DIT's case fits it. A new key that
duplicates an existing verdict is a second vocabulary for one idea.

## One distinction stage 02 hands over

`NoMapForIt` means the run found no autoload map at all — a normal state for a
project analysed without an install. "The map exists and has no entry for this
name" is a different answer and belongs to `BrokeAt`. Whatever channel is
chosen must not merge them back together.

## Constraint carried from stage 02

Whatever is chosen must not make `NoMapForIt` look like a defect in the
analysed project. A project with no `vendor/` installed is a normal thing to
analyse; the honest statement is about what this run could see, not about the
code.

## Open, for the owner rather than for the implementer

Whether DIT should report a value at all for a chain that leaves the analysed
path and cannot be followed. Reporting a shallow number that is knowably
incomplete is the behaviour this campaign started from; the alternative is
withholding the metric for that class and saying why. That is a product
decision about the metric's contract, not an implementation choice, and it is
named here rather than settled.
