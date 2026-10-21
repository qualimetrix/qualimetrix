# 0130. The Finding Gate Keeps Behaviour Tests, Not a Witness Registry

**Date:** 2026-10-21
**Status:** Accepted

## Context

[ADR 0087](0087-the-finding-gate-declares-measured-changes.md) required every
failure class and every raise site the source scanner recognized to have an
observed self-test witness, and kept a mutation-controls harness beside it.
The witnesses ran as `--self-test` in the pull-request gate job (about eight
minutes), the 31 controls as a daily workflow (about 139 minutes on Linux).
Both are checks whose subject is another check: they prove that the gate's
checks fire, not that the product or the gate behaves correctly.

Retiring them in one piece also retired what they carried. Many self-test
scenarios were ordinary tests of the gate's behaviour — how a rename map
translates and refuses, how normalization keeps exact JSON bytes, how a diff
splits into hunks, how a run gives its reference checkout back on a signal —
and several were regression tests of confirmed defects. Eighteen failure
classes the comparator still raises were left with no test asserting them.

## Decision

The gate keeps tests of its own behaviour and drops the machinery that
counted them.

- **Kept, as PHPUnit tests** under `scripts/finding-gate/tests/`: every
  scenario whose subject is gate behaviour, and one test per failure class the
  live comparator raises — a minimal planted input that raises exactly that
  class beside a legal neighbour that does not.
- **The cheapest level that reproduces it**: the check class called directly,
  then a recorded comparison without capture processes, and a whole synthetic
  run only where the check needs one. A test that spawns a run, a repository
  or a signalled child carries the `finding-gate-e2e` group and runs in
  `composer check:gate`, not on the routine `composer check` shard.
- **Dropped**: the witness registry, the raise-site inventory, the
  `--self-test` mode and its CI step, and the mutation-controls harness with
  its workflow.
- **The rule that replaces them**: a change to the comparator proves its new
  check bites once, in that change — plant the defect the check exists for,
  see the check go red, and keep the planted input as its test. Nothing
  re-proves it afterwards.

## Consequences

- Nothing mechanically refuses a failure class without a test. A new class
  is held to the bite-once rule in review, as any other check is.
- Assertions about tracked declaration files — that the tracked maps or field
  moves load, that a tracked normalization row is present — are not kept as
  tests of the mechanism. The gate loads those files on every run and refuses
  one it cannot read. The one tracked normalization row whose precision was a
  measured regression keeps its test, because a too-wide row hides semantic
  text silently rather than failing to load.
- The pull-request gate job no longer pays for the self-test, and `main` no
  longer runs the daily controls. Behaviour tests that need processes add to
  the `finding-gate-e2e` group instead.
- Process supervision in the gate — the deadline of a child that closed its
  pipes, termination of the whole process group, release of a reference
  checkout on SIGINT, SIGTERM, a PHP fatal or a kill during `git worktree add`
  — is pinned by those tests rather than by mutation runs.

## Rejected alternatives

- **Keeping the registry as a PHPUnit test.** Its subject is still another
  check, which the repository's rule for adding checks refuses; a planted
  defect proves a check once at the point it is written.
- **Porting every scenario as a whole synthetic run.** That keeps the
  self-test's cost while dropping its name: most scenarios exercise one class
  and reproduce at that class's level in milliseconds.
- **Deleting the scenarios with the mechanism.** That loses regression tests of
  confirmed defects, which the repository requires to stay.
