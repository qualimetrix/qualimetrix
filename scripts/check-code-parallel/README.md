# Parallel code checks

`check-code-parallel.py` starts the existing `composer phpstan` and
`composer test:aggregate` commands at the same time. It changes only their
schedule. Both commands retain their own arguments, test selection, deadlines,
and failure rules. Once both start, the coordinator waits for both even when
one exits nonzero; a launch refusal cancels any started peer. The first failing
command in the fixed order determines the nonzero exit code.

Each child's stdout and stderr go to separate temporary regular files. Once
both finish, the coordinator publishes the complete streams in PHPStan then
aggregate order, with section labels and each exit code. Regular files avoid a
pipe writer blocking while the coordinator waits for the other command. The
temporary directory is removed after publication. The coordinator does not
change the process umask, environment, or cache settings.

On SIGINT or SIGTERM, the coordinator sends TERM to both owned process groups,
waits up to 30 seconds for the complete groups, sends KILL to any survivors, reaps
both direct children, and publishes captured output. It exits 130 or 143 when
cleanup returns without an OS error, or 2 with the OS error when group inspection or
signalling is refused. On this macOS host, inspection can return EPERM after
a leader exited even though no running descendant was observed; the
coordinator reports that uncertainty instead of declaring cleanup successful. The
PHPUnit aggregate owns separate suite process groups and must propagate its
own cancellation to them. The coordinator does not replace that lifecycle.
An unreadable group stays unknown through the full grace period so it cannot
force an accessible peer to be KILLed before that peer finishes its own cleanup.

The regression tests use short fake commands to cover the default CLI scheduling
both Composer checks concurrently, both failure positions, complete large
stdout/stderr, launch refusal, inherited umask, SIGINT and SIGTERM, a
TERM-ignoring descendant, and a refused group signal that must not prevent
cleanup of the peer. Run them with:

```sh
python3 -m unittest discover -s scripts/check-code-parallel/tests -p 'test_*.py'
```

The coordinator is a scheduling tool within the existing `check:code` group;
it is not an additional validation gate or a result cache. A green standalone
unit suite does not prove that the real Composer commands pass together.
Running PHPStan beside six PHPUnit shards increases concurrent CPU and memory
use; contention or resource exhaustion can slow the run or produce a false red
that a sequential check would not. It should be adopted only after a complete
same-source green run and a measured budget improvement. Child output is held
until both commands finish, so an ongoing run does not display their own
progress. Cancellation requires POSIX process groups and the aggregate's own
signal cleanup; a hard kill of the coordinator cannot run that cleanup.
