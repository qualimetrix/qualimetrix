# Findings left open after the implementation review

Round 3 of review — the first that looked at code rather than at the plan —
returned seventeen findings. Four were repaired before the merge: the offline
bench's C4 passing vacuously, the guard losing an unmeasured metric from its exit
code, half the hint vocabulary left on pre-calibration thresholds, and a corpus
entry without a path analysing the repository root.

The rest are real, none blocks the merge, and each is recorded here rather than
folded into a repair round that was already open. Widening a fix package
mid-repair is how a closed defect gets replaced by a new one.

| id        | severity | subject                                                                                                                                     |
| --------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| claude-03 | medium   | the HTML report's `(no namespace)` node shows numbers that contradict the published global namespace                                        |
| claude-05 | medium   | "the inputs it was computed from" holds only for the default configuration; a user `computed_metrics` override is not reflected             |
| claude-06 | medium   | the global namespace now reaches aggregation but not finding attribution, and `--namespace` cannot address it                               |
| claude-07 | medium   | "under the project's own configuration" in practice means "under no configuration", and the product's warnings about that go to `/dev/null` |
| claude-08 | medium   | the agreement test is one-directional, and a comment claims a project-level inheritance the definitions do not have                         |
| claude-09 | medium   | the bench accepts formulas the product refuses — it has no equivalent of the per-level key-existence check                                  |
| claude-10 | medium   | a partially filled `expectations` block on an existing project silently takes the seeding branch                                            |
| claude-15 | low      | the `label` field in the contributor table is read by nobody and is wrong where it exists                                                   |
| claude-16 | low      | small producer/consumer mismatches between the two corpus scripts                                                                           |
| claude-17 | low      | the bench coerces declared thresholds silently and accepts an empty analysis as a successful measurement                                    |

## The one worth picking up first

**claude-06.** The global namespace is now a measurement subject at project level
and still is not one for attribution: a finding raised inside it has nowhere to
point, and `--namespace` cannot select it. That is the same shape as the defect
this branch repaired — a subject admitted in one place and filtered in another —
and leaving the two halves inconsistent invites the next reader to "fix" the
half that is now correct.

## A correction this review produced

The plan and ADR 0062 both said `HealthFormulaExcluder` reads the weights out of
`health.overall` with a regular expression. It does not: `WeightedHealthFormula`
walks the parse tree and explicitly refuses a partial read. The claim built on
it — that the formula must stay a canonical weighted sum or `--exclude-health`
refuses — is unaffected and stands. The mechanism was named wrongly, in three
places, and repeated into two review briefs before anyone checked it.

---

## Round 3 additions, and one correction to this file

The external reviewer, run in parallel with the native one, added eight
confirmed findings. Four were repaired in the same round as the four above:

- **C1 was two-sided in the instrument while declared one-sided in the plan.**
  Proven on one capture: the two-sided form reported one violation and exited 1,
  the one-sided form reports none and exits 0. A green monotonicity verdict was
  unreachable by construction until this was fixed.
- **C4's zero was indistinguishable from "not pooled".** The drift now reports
  `n/p` where no derived key reaches a dimension's formula, and the docblock
  states which single key is derived and why the others are read verbatim. A
  residue remains: a pooled aggregate with exactly one member is arithmetically
  identical weighted or not, so `0.00` can still mean "nothing to weigh".
- The hint vocabulary's second half and the pathless corpus entry, as above.

Still open from round 3, in addition to the table above:

| id        | severity | subject                                                                                                                                       |
| --------- | -------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| codex-01  | high     | the criteria command checks neither C5 nor C6's verdict                                                                                       |
| codex-04  | high     | the HTML report and the health summary lose the global namespace's metrics and findings — a regression from this branch's own aggregation fix |
| codex-05  | medium   | targets come from a static catalog rather than the active formula, so a user override is not reflected                                        |
| codex-06  | medium   | the calibration capture directory carries no manifest or provenance                                                                           |
| claude-11 | medium   | the JavaScript decomposition tests compare against a hand-written copy of the PHP catalog, with no guard between them                         |
| claude-12 | medium   | the bench's leaf rule is not tied to `NamespaceTree`, and two of its tests do not bite                                                        |

**codex-04 joins claude-06 as the first thing to pick up.** Together they say the
same thing twice: the global namespace became a measurement subject and did not
become a reporting subject.

## A correction to my own account

I told the session that ADR 0062 claimed `HealthFormulaExcluder` parses the
formula with a regular expression, and that the ADR therefore stated something
false. Checking the committed text: **it does not.** The ADR says
`WeightedHealthFormula` "does not parse" a non-canonical formula, which is
correct. The wrong mechanism lived in two plan files, in this file's predecessor
note, and in both review briefs — not in the decision record.

Claiming an error one did not make is the same failure as missing one: both are
assertions made without checking the text.
