# Stage 05 verdicts

One file per package, named for the package: `P0a-population.tsv`,
`P1-high.tsv`, `P3-evidence.tsv`, and so on. The completeness control reads the
**union** of every `*.tsv` in this directory.

One file per package rather than one shared file, because five packages work one
tree at once and an editing tool rewrites a file whole: two packages appending to
one file lose each other's lines silently. Separate files turn that loss into a
question nobody has to ask.

Columns: `row_id`, `verdict`, `evidence`.

`row_id` joins to `defect-ledger.tsv`. The verdict vocabulary is exactly three
values and nothing else is a verdict:

| Verdict         | Means                                              | `evidence` carries  |
| --------------- | -------------------------------------------------- | ------------------- |
| `fixed`         | the defect was there and is gone                   | the commit          |
| `already-fixed` | stages 01-04 closed it in passing                  | the commit that did |
| `wont-fix`      | the row does not describe a defect worth repairing | the reason          |

`evidence` is never empty, and on `fixed` and `already-fixed` it names the
commit: both are checked, so "fixed, see the PR" closes a row on nothing the
repository can read. A hash is seven to forty lowercase hex digits carrying at
least one digit; one that happens to be all letters is rejected, so write it
longer.

Every row of the ledger needs exactly one verdict across the whole directory. A
second verdict for the same `row_id`, a verdict naming a `row_id` the ledger does
not carry, an unknown verdict word and an empty `evidence` cell are each their own
refusal.
