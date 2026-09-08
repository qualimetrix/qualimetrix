# Rule option key recognition

A user key that the product does not recognise is treated differently
depending on where it sits: refused in one position, warned about in another,
dropped in silence in a third. The entry point was measured on `1b225c2e`:

    rules: {complexity.ccn: {callable: {warnign: 1, error: 2}}}

`warnign` is compared against nothing, the threshold it was meant to set is
never applied, and no stream says so. The sibling `error: 2` is honoured, so
the run looks configured.

## What is in here

`measurement/` holds the enumeration this subject has to be planned from, and
it is the artefact, not a summary of one:

| file                        | rows | how it was built                                                              |
| --------------------------- | ---- | ----------------------------------------------------------------------------- |
| `witness-by-code.md`        | 73   | one agent reading `src/`                                                      |
| `witness-by-measurement.md` | 77   | one agent running `bin/qmx`, blind to the first                               |
| `merged-enumeration.md`     | 133  | a third agent reconciling both, resolving every disagreement with its own run |

**The two witnesses are the point.** A single agent that fills in both an
enumeration and the oracle that checks it errs consistently and passes its own
guard. These two never saw each other's work, and the third had to re-measure
anything they disagreed on rather than pick the more convincing report.

The merged file's own numbers: 34 positions both witnesses reported, 74 from
one witness and confirmed by a run, 1 refuted, 7 disagreements resolved by
measurement, 13 found only while reconciling. Verdicts: 66 refuse, 40 silent,
18 warn, 5 published as a finding, 2 crash with exit 1 and no report.

## Read the mechanism grouping, not the 133 rows

`merged-enumeration.md` ends with thirteen mechanisms. Treatment is designed
per mechanism — every row under one of them is the same seam failing in a
different place, so fixing rows one at a time buys thirteen partial repairs
and no closed class.

Two things in there are defects rather than missing diagnostics, and are
tracked as such: two inputs abort the run with exit 1 and no report at all —
one leaking an internal `TypeError`, one routing a configuration refusal
through the tool-crash path.

A third claim did not survive its own remeasure, and the correction is left
visible on purpose. `suppress_namespace_channels` was reported as validated
and inert. It is not: dropping the three entries our own `qmx.yaml` carries
adds seven findings back. The first probe pointed the option at a rule whose
slots are `callable` and `class`, and the option suppresses at `namespace`
level by construction. What remains is narrower and real — a key naming a
channel that never publishes at `namespace` level is accepted in silence and
can never suppress anything.

Two further files in `measurement/` are not part of the two-witness enumeration
and are named separately so that nobody reads them as evidence of the same kind:

| file                         | rows | what it is                                                                                      |
| ---------------------------- | ---- | ----------------------------------------------------------------------------------------------- |
| `level-declared-vs-read.tsv` | 10   | the declared-versus-read question asked of the ten level classes, from the same script          |
| `packages.tsv`               | 128  | package → path, the plan's work-package file sets, so that "disjoint" is checkable not asserted |

`level-declared-vs-read.tsv` was added while revising the plan: the original
table covered the 35 options classes, and the plan applies its rules to the ten
level classes as well. `scripts/enumerate-rule-option-keys.php` emits it as a
fourth section; the other three sections are byte-identical to the files already
here, before and after that change.

## Status

Enumeration complete; treatment planned in this directory. The naming question is not part
of this subject — level names are settled by ADR 0024 and are not reopened.
