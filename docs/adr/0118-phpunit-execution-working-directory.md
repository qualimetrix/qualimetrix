# 0118. PHPUnit Execution Working Directory

**Date:** 2026-10-15
**Status:** Accepted

## Context

Repository-root launches conceal tests using relative fixture paths or the
host checkout's Git state. Successful command tests can also leave the
product's default cache in cwd. Changing cwd in PHPUnit's bootstrap breaks
relative test arguments because PHPUnit resolves those after bootstrap.
Data providers are resolved before the execution-start event.

## Decision

Use one parent-owned process root and a PHPUnit execution window.
`tests/bootstrap.php` loads the autoloader, creates a random private
`QMX_TEST_PROCESS_ROOT`, publishes it for isolated children, and registers
parent-only cleanup and an exit guard. It leaves the launch cwd unchanged.
The extension creates `<root>/cwd` and enters it on `ExecutionStarted`.
On `ExecutionFinished` it judges residue and restores the launch cwd before
PHPUnit writes relative JUnit or coverage reports. A class-suite finish checks
cwd restoration and names the class that leaked it. PHPUnit itself restores
cwd after each test.

Tests intentionally requiring repository configuration opt into
`RunsFromRepositoryRoot`. Tests about relative-path semantics select a known
fixture directory. Product Git tests initialize and commit their own
disposable repositories. Repository governance retains loud refusal when
the checkout's Git population is unknown.

The window permits only entries declared with a reason in
`declared-residue.tsv`. The current `.qmx-cache` entry describes the product's
relative default cache, rather than naming a test. It need not appear in
every suite. The aggregate combines observations from all six successful
suite shards and refuses a declaration absent from their union. Each shard
launches in a separate empty directory: residue there belongs to discovery,
providers or top-level code, and the window exception does not apply.

Subscriber exceptions become PHPUnit warnings. The parent shutdown guard
forces exit 1 for a dirty window or an extension that did not bootstrap,
including `--do-not-fail-on-phpunit-warning`, `--no-extensions`, and
`--no-configuration --bootstrap tests/bootstrap.php`.
The guard and cleanup are armed only for the actual PHPUnit parent entry
script. Isolated children execute from stdin, inherit the parent's root and
do not remove it, with or without preserved global state. Dirty window roots
remain for diagnosis; unbootstrapped empty roots are removed.

The bootstrap is the sole owner of process-root cleanup. Future process
concerns add independent subdirectories. An XDG cache concern uses
`<root>/xdg-cache` with mode `0700` and publishes `XDG_CACHE_HOME` using
`putenv()`, `$_ENV` and `$_SERVER`; the window inspects only `<root>/cwd`.

The suppression-loading regression already uses deterministic evidence:
2048 canonical subject reads plus an in-process measured map-copy memory
comparison, in an isolated child. It does not judge elapsed time or load.
The present change preserves that test.

## Verification and Limits

The native subprocess regression exercises a clean run, relative test argv,
listing, suite selection, filtering, random order, relative JUnit, declared
cache residue, both isolated-child global-state modes, left files, a class
hook cwd leak and the exit-guard bypasses. The control protects repository
configuration wiring and the native parent/child lifecycle; focused product
tests alone cannot prove that wiring. Its measured local cost is about two
seconds, with subprocess-startup variance. Missing PHPUnit extensions,
unwritable temporary directories and unsupported isolated execution refuse
the run instead of producing a green check.

Relative coverage report restoration follows the same finish-event boundary
as JUnit; the regression does not install a coverage driver. Discovery and
data providers remain outside the window; the aggregate's empty launch
directory detects their leftover files, not transient writes they remove.
A direct PHPUnit run without this bootstrap has no window or guard. A direct
run does not judge declared-residue freshness, which requires all suites.
Checks about the repository's tracked population still require Git metadata.

## Consequences

Focused relative invocations keep working. Unintended cwd or host-repository
dependencies become visible during execution. Temporary default-cache
tolerance retires when the aggregate observes that no suite uses it.
There is no product contract or user-facing behavior change.
