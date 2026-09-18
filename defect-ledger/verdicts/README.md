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
repository can read.

**That the commit exists is a frozen measurement, not a running check, and the
difference matters.** A control did resolve every hash-shaped token through
`git cat-file` and require type `commit` — it caught a verdict carrying
`1c6ec21e`, eight hex digits naming nothing. It cannot run where this repository
is published. The `check` job checks out at the default depth of one, and `main`
is a chain of squash-merges: on a `--depth 1` clone all 51 tokens read `missing`,
and 45 of the 51 are branch-local and stop existing the moment the branch is
squashed. Red on `main` from the day of the merge is worse than vacuously green:
it teaches a reader to stop looking at red in Governance.

Recording the fact is honest here because the ledger is frozen — 276 rows, all
answered, no verdict row will ever be added. Measured once, on `c40b1981`,
against the full history:

```
cut -f3 defect-ledger/verdicts/*.tsv | grep -oE '\b[0-9a-f]{7,40}\b' | sort -u \
  | xargs -n1 git cat-file -t | sort | uniq -c
```

**51 distinct tokens, every one of them `commit`.** Separately, each row's commit
was checked to touch that row's file or its counterpart: 159 of 163, the four
misses being renames where the commit changed the file's name itself.

What still runs is the half that needs no history: a `fixed` or `already-fixed`
verdict must carry a hash-shaped token. That survives a shallow clone and a
squash.

A hash is seven to forty lowercase hex digits carrying at least one digit; one
that happens to be all letters is rejected, so write it longer. If a reason
genuinely needs a hex token that is not a commit, reword it — in a verdict, eight
hex digits mean a commit, and the control reads them that way.

Every row of the ledger needs exactly one verdict across the whole directory. A
second verdict for the same `row_id`, a verdict naming a `row_id` the ledger does
not carry, an unknown verdict word and an empty `evidence` cell are each their own
refusal.
