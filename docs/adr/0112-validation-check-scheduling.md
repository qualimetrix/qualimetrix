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
The remaining-check child explicitly disables Composer's process timeout,
preserving the previous complete check's policy in the new process.
Leaf tools keep their existing deadlines.

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

## Amendment, 2026-10-22: publish completed commands immediately

Publish each command's complete captured streams and exit code as soon as that
command exits, including during interrupt cleanup. Publication follows
completion order. The first failing command in declaration order still
determines the coordinator's result.

## Amendment, 2026-10-23: group completion precedes publication

This amendment supersedes the 2026-10-22 decision to publish on leader exit and
during interrupt cleanup. A command's capture is ready only after its leader has
exited and its whole process group is gone. Normal execution publishes each
ready command in completion order. Interrupted execution stops and reaps every
owned group before publishing any capture, so publication failure cannot abort
cleanup. Output write failures are diagnosed when stderr remains writable and
do not replace the existing command, signal or cleanup-refusal result.

An OS refusal to inspect a group routes normal execution through the same
bounded cleanup and refusal used for interruption. During cleanup an unreadable
group remains unknown for the full grace period; its output is delayed rather
than declared complete from the leader's exit alone. If cleanup still cannot
prove the group gone, the coordinator reports the refusal and withholds that
interrupt publication; publishing the batch as complete would repeat the same
unsupported claim.
