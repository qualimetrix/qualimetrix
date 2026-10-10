# 0112. Validation check scheduling

**Date:** 2026-10-11
**Status:** Accepted

## Context

PHPStan and the six verified PHPUnit suite processes both perform substantial
CPU and memory work. Starting them together can increase contention without
shortening complete validation. The generated-artifact checks, documentation
build and product self-analysis do not depend on PHPStan results and do not
write its cache or analysed source files.

## Decision

Complete validation finishes the code tests before starting PHPStan alongside
the remaining documentation, artifact and self-analysis checks. Their existing
internal order and failure rules remain. Standalone `check:code` still runs
all code checks, including PHPStan; it does not run the other groups.

Reuse the existing two-child coordinator and its process-group cleanup. Do not
add a gate, result-cache shortcut, exclusion or larger deadline. Both child
commands finish even when one fails, then their complete captured streams and
exit codes are published in command order. The first failing child in that
order determines the result.

## Consequences

The scheduling choice requires complete same-source validation and measured
runtime improvement; concurrency alone is not evidence of a faster check.
Remaining CPU and memory contention can still slow the run or cause resource
failures. Child progress is buffered until both finish. Cleanup requires POSIX
process groups and cannot run after a hard kill of the coordinator. These
limits and the current commands are documented with the scheduling tool.
