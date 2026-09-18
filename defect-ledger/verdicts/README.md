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

**51 distinct tokens, every one of them `commit`.**

Separately, each row's commit was checked to touch the row's file, its
counterpart, or the heir `packages.tsv` gives it. That one is re-derivable and
its command is tracked, because a number no command produces is not a
measurement:

```
python3 defect-ledger/reproduce-commit-reach.py
```

**197 of 201** verdicts claiming a commit reach their row. The four that do not
are `R009`, `R023`, `R171` and `R172`, and they are legitimate: each verdict
describes a rename, and git reports a rename as the new path only, so the name
the ledger recorded never appears in the commit. Measured — `5e11234e` lists
`ProfilerWorkflowTest.php` and not `ProfilerIntegrationTest.php`, `f346979c`
lists `RuleExecutionTest.php` and not `RuleExecutorTest.php`.

An earlier version of this file said "159 of 163". That number came from the
same script run when P1 had not yet written its verdicts and P3's were
incomplete — a figure recorded without the population it was measured over,
which is the defect this campaign keeps finding in other people's work. It is
replaced by the figure above, measured over all seven verdict files, and by the
script that re-derives it.

## What is left to a reader after the squash

The 45 branch-local hashes stop resolving the moment this branch is squashed.
What replaces them as an address is the **pull request** and the single squash
commit it becomes: the work every branch-local hash names went into that one
commit. So a hash in a verdict is read after the merge as a historical pointer
into the branch that produced it, not as something to hand to `git show`. It is
worth knowing before trying, rather than after.

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
