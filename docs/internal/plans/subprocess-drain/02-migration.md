# Stage 02 — every deadlock-capable call site

## The table

`enumeration.md` in this directory: one row per `proc_open` call site in tracked PHP, with
its descriptor spec, its read shape, the child resolved back to its construction site, and
a disposition. It carries its own "how obtained / what this method cannot see" line. Read
it there; it is not reproduced here, and no count is stated in this file.

It was taken twice independently and reconciled. Every disagreement between the two
takings is recorded in the table as a row, not averaged away.

## Why "can this child's stderr exceed 64 KB?" is the wrong question

Ten rows were previously left unresolved because their child's worst-case output could not
be settled. The question is unfalsifiable in the direction that matters: a child's stderr
volume is a property of the *child's future*, not of anything visible at the call site.
`generate-modular-architecture.php` did not look like a 2.6 MB writer either, until a
schema-invalid manifest made it one.

So no row is dispositioned by arguing about volume. Every row gets a disposition that
removes the **shape**, and "unresolved" stops being a state a row can be in.

## The dispositions

Every row in `enumeration.md` carries exactly one, in its own column. A row with none is the
ownerless-file hazard: it is in no package, so no package's Definition of Done covers it.
The oracle is mechanical — the count of empty cells in that column must be zero, and the
command is printed beside the table.

1. **`ChildProcess::run()`** — the default, for a caller that starts a child, captures both
   streams and waits.
2. *(withdrawn)* — a public interleaving `drain()`. Walking the rows showed it would have no
   executable caller; see `01-runner.md`. Nothing is dispositioned to it.
3. **Remove the extra blocking stream** — redirect it to a file, or do not open the
   descriptor at all. Impossible by construction rather than by discipline. This is the only
   disposition available to `src/Infrastructure/Git/GitRepositoryLocator.php`: it is
   production code, and the ban on `src/` importing a development namespace is exactly what
   stage 01 deliberately keeps working. Precedent: `scripts/collect-benchmark-data.php`.
   **Both** extra descriptors go, not just stderr — `GitRepositoryLocator` opens a stdin pipe
   too, and the precedent does not open one at all.
4. **Keep its own `proc_open` under a declared entry** — the module and the named
   specializations, each with a reason a reviewer agreed with.
5. **Not a call in this file** — embedded PHP source or rule data. Still needs an entry: a
   nowdoc can be executed by a spawned child, and in this repository one is.

A row's disposition does **not** decide whether it needs an allowlist entry. Stage 03 refuses
every surviving `proc_open` occurrence regardless of shape, so a row that keeps its own call
earns an entry even after disposition 3. An earlier draft claimed disposition 3 removed the
need for one; that was wrong, and it was wrong for `GitRepositoryLocator` specifically.

## What error handling actually changes

`run()` throws `\RuntimeException` when the child cannot be started or a stream cannot be
read, where several callers today return a string, assert, or return `null`. So the promise
is narrower than "each caller keeps its own error shape": **the failure *type* at those
sites changes**, and each migrating package must say what its caller now does with it.
Non-zero exit codes are unaffected — they stay in the result.

**Three sites wrap `run()`'s failure back into their own exception type, and the plan
originally named only one of them.** Round 1's review found `ProbeFailure` and stated it was
"the only narrowing `catch` in the migrating set". The plan repeated that. Executing the
package disproved it — the set is:

| Thrown from                               | Type                   | Caught by name at                                                                        |
| ----------------------------------------- | ---------------------- | ---------------------------------------------------------------------------------------- |
| `scripts/promise-effect/ProcessProbe.php` | `ProbeFailure`         | `promise-effect/Stand.php:206`, `:1199`                                                  |
| `scripts/input-doors/Runner.php`          | `DeclarationError`     | `input-doors.php:139`, `input-doors/Stand.php:95`, `input-door-controls.php:520`, `:606` |
| `scripts/promise-effect-corpus.php`       | `ProbeProtocolFailure` | `promise-effect-corpus.php:362`                                                          |

Each extends `RuntimeException`, so inheritance runs the wrong way: a bare
`\RuntimeException` from `run()` sails past every one of those handlers, turning a handled
verdict (`INCOMPLETE TRIPLE`, a clean exit 2) into an uncaught crash.

**The lesson is about the enumeration, not the catches.** A claim that some set has exactly
one member is a claim about a set, and this one was never enumerated — it was asserted by a
reviewer, repeated by the plan, and believed because it was specific. The honest form is the
table above, derived by `grep -rn 'catch (<Type>'` for each type a migrating file throws.
Six catch sites, not one.

## Two rows a rule does not settle

- **`tests/Analysis/Policy/Baseline/Integration/BaselineChannelRenamerTest`** — the parent
  holds a lock while the child blocks on it, so the deadlock window opens *before* the parent
  is free to read anything. No read discipline closes it; disposition 3 does. Give stderr a
  file and read that file into the failure message so nothing the child said is lost. It
  keeps its own `proc_open`, so it earns an entry.
- **`scripts/finding-gate/SelfTest.php`** — **disposition 3, and only 3.** The function reads
  two announcement lines from stdout with a deadline and must return the child *alive*, with
  its stdout handle, while the child runs `sleep 30`. `run()` waits for exit; the withdrawn
  public `drain()` read to EOF. Neither is executable here. An earlier draft said "either
  disposition is available" and offered a choice a package could close its DoD against
  without it working. Redirect stderr to a file, keep the existing bespoke loop and its
  `SIGKILL` backstop, and take an entry.

Both keep their deadline. A migration that removed one would make that caller worse while
claiming to make it safer.

## Packages

Grouped by file set, not by theme, so no two packages touch one file.

| Package | Files                                                   | Parallel with |
| ------- | ------------------------------------------------------- | ------------- |
| P1      | `governance/` rows                                      | P2, P3, P4    |
| P2      | `scripts/` rows (excluding the stage-01 three)          | P1, P3, P4    |
| P3      | `tests/` rows                                           | P1, P2, P4    |
| P4      | `src/Infrastructure/Git/GitRepositoryLocator.php` alone | P1, P2, P3    |

P4 is its own package because it is the only production file in the migration and the only
one whose disposition is forced by the import ban. Bundling it with tooling would let a
tooling-shaped fix reach it.

Each package re-runs the tests its files belong to. The full aggregate belongs to the
orchestrator, once, after all four.

## Definition of Done

- The disposition column in `enumeration.md` has no empty cell — the command is printed
  beside the table, and the expected count is zero.
- The tree matches every row's disposition.
- No disposition anywhere is justified by an argument about the current child's output size.
- Every caller whose failure *type* changed says what it now does with a `\RuntimeException`.
- Every caller that had a deadline still has one.
- `composer check` green as a whole — a green group is evidence about that group only.
- `composer gate:controls` re-run if any finding-gate file was touched: a gate that proved
  itself before a change says nothing about the changed one.
