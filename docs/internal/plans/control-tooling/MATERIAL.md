# Control tooling: measurement, and the question of a budget

## What is measured (this tree, 2026-09-14)

|                                                                                     |                                     |
| ----------------------------------------------------------------------------------- | ----------------------------------- |
| `src/`                                                                              | 108 461 LOC                         |
| `tests/`                                                                            | 207 126 LOC                         |
| `scripts/` (php+py+sh)                                                              | **47 947 LOC — 44% of the product** |
| of which second-order (controls proving an instrument bites)                        | **13 472 LOC — 28% of the tooling** |
| root data dirs (`finding-gate`, `promise-effect`, `input-doors`, `directive-audit`) | 1.7 MB, 232 files                   |
| `docs/internal/generated/`                                                          | 12.9 MB, 887 files                  |

Runtime is not the constraint: the whole standing rig (`check:artifacts` + `check:self`)
measures 48s — leaks 1s, architecture:check 8s, three enumerations <=1s each,
suppression-snapshot 12s, input-doors:grid 1s, promise-effect p1-set/grid <=1s,
gate:self-test 1s, directives:audit 23s.

## Growth

Thirteen tool families. Four of them (promise-effect, input-doors, directive-narrow-control,
directive-audit-controls) were built in the last three weeks. Two of those families
(`promise-effect:p1-set:check`, `promise-effect:grid:check`, `input-doors:grid:check`)
sit inside the mandatory `composer check` and are named **zero times** in AGENTS.md.

57 composer commands (excluding the two `post-*` lifecycle hooks); **24 have no
`scripts-descriptions` entry**. All **18** promise-effect / promise-ledger /
input-doors commands are among them — every one of that family is undescribed.
(Two earlier readings of this row said 13 and 16; both were wrong. The count is
reproducible from `enumeration-commands.tsv`, which is why it is stated by
reference and not from memory.) There is no index of the rig anywhere.

`promise-effect` is 12 090 LOC of instrument plus 1.4 MB of data (`promise-ledger.tsv`
is 882 KB in git). **Correction to an earlier reading of this row:** its cure *has*
landed — PR #63 (`02a6ca66`, 72 src files, 1778 lines), #64 (`1210b037`, 16 files,
476 lines) and #65 (`062cb5c3`, 14 files, 1239 lines) are that investigation's product
changes. What has no cure is the *open* axis-B round (X24) alone. The family has paid
for itself; one round of it is still outstanding.

## Duplication has already happened

`scripts/generate-input-door-table.php` (input-doors, week 1) and
`scripts/promise-effect/InProcess.php` + `promise-effect.php` (week 3) each walk
Symfony `InputDefinition` over every command independently, with no shared code.
promise-effect reuses `input-doors-bootstrap.php` for container boot but rolls its
own enumeration. Several scripts independently reflect over `ConfigSchema`.

## Churn: does a tool break more often than the code it guards?

Commits touching each family, split by whether the same commit also touches `src/`:

| family               | commits | tool-only | with src | `fix(...)` with no src line |
| -------------------- | ------- | --------- | -------- | --------------------------- |
| finding-gate         | 74      | 44        | 30       | 8                           |
| modular-architecture | 86      | 45        | 41       | 1                           |
| directive-audit      | 31      | 13        | 18       | 5                           |
| rename-enum          | 15      | 5         | 10       | 0                           |
| suppression-snapshot | 5       | 1         | 4        | 0                           |
| promise-effect       | 6       | 2         | 4        | 1                           |
| input-doors          | 2       | 0         | 2        | 0                           |

Caveat that must not be dropped: since 2026-09-10 history is squash-merged per PR,
so promise-effect and input-doors show one commit per whole round. Their low counts
are a measurement artefact. Only finding-gate and directive-audit have comparable
fine-grained history.

## The policy drafted so far (an entry filter)

Before writing an instrument, answer with numbers:
1. How many sites? A closed, enumerable set means fix the bug and close it with a
   regression test. An instrument is justified only when the set grows with the code.
2. Who consumes it, and when does it die? Either a standing consumer (`composer check`)
   or an owning plan. Neither means it is deleted when the round closes.
3. Freeze or re-run? An answer that can be frozen into an artefact and compared is a
   freshness generator — cheap, may stand. An answer requiring the product to be run in
   N configurations is a stand — expensive, round-scoped by default.

Plus: a control dies with its round once its instrument stops changing; a standing check
must have a line in AGENTS.md saying what it asserts and what to do when it reddens.

## The objection this material exists to answer

**That policy is a per-tool entry filter. It does not bound the aggregate.**
The rig is already 44% of the product and grew by four families in three weeks.
A filter that each individual tool passes still yields unbounded total growth.

Candidate mechanisms, not yet chosen:
- a ratchet on measured tooling size (the product is a static analyser; it can measure itself)
- one-in-one-out for standing checks
- a TTL: every round-scoped instrument carries its owning plan and is deleted or promoted when the plan closes
- a hard share cap (`scripts/` <= X% of `src/`)
- a shared substrate for the rig (container boot, command/ConfigSchema walking, TSV, in-process product runs), so the next stand costs 300 lines instead of 3000
- replacing a control's code with its frozen verdicts once it has proved the instrument
  bites — only under a hash/version binding to the instrument, or a frozen green outlives
  the instrument it was measured against and proves nothing

## Question

What is wrong here, and what actually bounds this?
