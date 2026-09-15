# What the aggregation fix actually moved

Same corpus (seventeen projects), same counting method, before and after
restoring the global namespace to the project aggregate. Counted from the
captures directly, children resolved by name containment.

|        | parent **above** max(children) | parent **below** min |
| ------ | ------------------------------ | -------------------- |
| before | 251                            | 1329                 |
| after  | 249                            | 1329                 |

Two rows disappeared, both CodeIgniter:

| subject     | dimension | before | after | its only namespace |
| ----------- | --------- | ------ | ----- | ------------------ |
| codeigniter | coupling  | 100.00 | 76.07 | 76.07              |
| codeigniter | overall   | 69.51  | 64.73 | 64.73              |

No new violation appeared. The bench, counting with its own child rule, reports
1487 to 1485 two-sided and 192 to 190 one-sided — different totals, same two
rows, for the reason recorded in `07-monotonicity-direction.md`.

## What this says about the fix

It is correct and it is narrow. The mechanism was established in code, the
predicted direction held, and the project no longer outscores the only part it
is made of. But **two of 251 upward violations are gone, not the class of
them.** The remaining 249 have other causes, and this change does not touch
them.

The plan presented M1 as repairing monotonicity. It repairs one instance —
the instance that exposed the problem, and the only one whose mechanism has been
traced. The rest are still unexplained and are not claimed as fixed.

## A number of mine, corrected

Earlier this plan cited "233 further parent/dimension pairs across the corpus".
That count was taken on the fifteen-project corpus, before the version bump and
before the anchors — a different population, quoted as if it were the current
one. On the corpus as it stands the figure is 251 before the fix and 249 after.

## A finding surface moved with it

`ClassCountRule` skips non-leaf namespaces. The global namespace was not a leaf,
so it was never judged; now it is. CodeIgniter consequently reports a
`size.class-count` error it did not report before — 139 classes against a
threshold of 25. That is the same defect class being repaired, and it changes
what the product publishes, so it belongs in the gate run and the changelog.
