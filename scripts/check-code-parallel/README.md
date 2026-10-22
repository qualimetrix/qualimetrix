# Parallel validation checks

`check-code-parallel.py` starts the existing `composer phpstan` and
`composer check:remaining` commands at the same time. The latter runs the
documentation, generated-artifact and self-analysis groups in their existing
order. `composer check` first finishes style, all verified PHPUnit shards,
the name census, JavaScript and cross-tool tests. It then runs this pair.
Standalone `composer check:code` retains both those code checks and PHPStan.
The change is their schedule. All commands retain their arguments, test
selection, deadlines and failure rules. Once both start, the coordinator waits for both even when
one exits nonzero; a launch refusal cancels any started peer. The first failing
command in the fixed order determines the nonzero exit code.

The full-validation CI job also uses this coordinator for its independent
Composer, documentation and report-template dependency installations, after
setting up their runtimes. It waits for all three and fails if any installation
fails. The default CLI still runs the two validation commands above.

Each child's stdout and stderr go to separate temporary regular files. As each
child exits, the coordinator publishes that child's complete streams, with
section labels and its exit code. Regular files
avoid a pipe writer blocking while the coordinator waits for the other command. The
temporary directory is removed after publication. The coordinator does not
change the process umask, environment, or cache settings.
The remaining-check Composer process disables its own process timeout, as
the previous complete check did. That setting is local to each Composer
process, so the child declares it explicitly; leaf-tool deadlines remain.

On SIGINT or SIGTERM, the coordinator sends TERM to both owned process groups,
waits up to 30 seconds for the complete groups, sends KILL to any survivors, reaps
both direct children, and publishes each child's captured output when that child
exits. It exits 130 or 143 when
cleanup returns without an OS error, or 2 with the OS error when group inspection or
signalling is refused. On this macOS host, inspection can return EPERM after
a leader exited even though no running descendant was observed; the
coordinator reports that uncertainty instead of declaring cleanup successful. The
PHPUnit aggregate runs before this pair and owns its separate suite process
groups and cancellation. The coordinator does not replace that lifecycle.
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

The coordinator is a scheduling tool within the existing complete validation;
it is not an additional validation gate or a result cache. A green standalone
unit suite does not prove that the real Composer commands pass together.
PHPStan no longer overlaps the six PHPUnit shards. It can still compete with
the remaining checks for CPU and memory; contention or resource exhaustion can
slow the run or produce a false red that a sequential check would not.
The schedule should be adopted only after a complete same-source green run and a measured budget improvement. Output from a running child remains buffered until
that child exits. Cancellation requires POSIX process groups; a hard kill of the coordinator
cannot run that cleanup.
