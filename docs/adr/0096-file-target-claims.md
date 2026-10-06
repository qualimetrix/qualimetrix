# 0096. File Target Claims

Date: 2026-10-03

## Status

Accepted

## Context

A path value does not establish who controls its directory entries or which
inode a later open reaches. Opening an existing report with truncation destroys
its bytes before that distinction can be checked. Logs, cache entries, baseline
documents and hooks need the same filesystem judgement while retaining their
own publication and failure policies.

## Decision

Core owns neutral FileTarget primitives and an Environment failure marker.
Path remains a lexical value subject. FileTarget owns component traversal,
entry-control judgement, held resources, temporary siblings and publication.
Capability policy and Console delivery stay outside Core.

A symbolic link is trusted by control of its parent directory entry, not by
ownership of the link. Other write permission permits placement. Group write
also permits placement unless complete membership evidence proves that the
group is the effective user's primary group, with no other primary or
supplementary member. Core owns the membership port: static filesystem callers
need the same judgement without depending on an Infrastructure adapter. The
native producer supports per-source `files` and `systemd` enumeration, checked
against keyed POSIX records. Unknown NSS sources, explicit `initgroups`, failed
or incomplete enumeration and unsupported platforms keep the conservative
refusal. The selected port follows the resolved target through every recheck;
sticky mode protects some existing entries against replacement but does not
protect a previously absent name. Root ownership of a closed directory is
trusted. The effective process uid comes from POSIX, never the script owner.
Without POSIX, an empty diagnostic temporary file supplies the uid; immediate
identity-safe cleanup is mandatory, and failure to clean up refuses the request.
This transient probe is the explicit exception to preflight without persistent
target effects.

Resolution follows links before interpreting subsequent parent components,
bounds link traversal, and recognizes the current process's descriptor paths
before traversing their filesystem links. Absolute file URLs are supported;
unknown wrappers and relative file URLs refuse.

Held stream/log targets open without truncation. Their opened identity must
match both the judged inode and the current name before writing. Held writes and
append perform complete writes and flush; append locks the file and seeks to
its current end. `PreparedTarget` instead creates an exclusive private sibling
before a long operation and leaves the final name untouched until publication.
Full writes, flush, mode and identity checks precede atomic rename or exclusive
hard-link publication. Replacement preserves the old mode unless explicitly
changed, and distinguishes exclusive creation from declared last-writer-wins
publication. `FileReplacement` uses this primitive for immediate publication.
An inherited child cannot discard a parent's sibling or held target. A held lock checks its named inode after locking;
releasing the lock does not remove its name. Its acquisition deadline uses a
monotonic clock, so a system-clock adjustment cannot shorten or extend it.
Only native would-block contention waits; another native lock error refuses
immediately with its reason.

Descriptor duplication preserves the supplied stream's offset and avoids
truncation. Descriptor and stream writes enable blocking so an inherited
nonblocking pipe cannot report success after losing buffered bytes.
A path-opened handle with the e mode is close-on-exec. PHP's
php://fd wrapper does not preserve that guarantee for its duplicate: it may
survive proc_open. Worker descriptor maps are a separate protection and do not
make descriptor duplication universally close-on-exec.

## Consequences

Consumers use the shared judgement and resource primitives rather than inferring
safety from a normalized path. They retain decisions about allowed target kinds,
exposure diagnostics, modes, locks, cache misses and CLI refusal presentation.
Every cross-owner consumer must be registered as an observed exact import.

The threat model remains bounded. Mode bits do not model macOS ACLs; authorized
writers can place hard links; non-local filesystem semantics may differ.
Component swaps still have windows between inspection and use. In particular,
a FIFO opened with we can be replaced by another process of the same effective
uid before open, causing truncation before the subsequent identity refusal.
No guarantee of zero filesystem effects is made for these residual races.

The baseline:generate command prepares both absent and existing destinations before analysis and
requires an existing writable/searchable parent. Its writer receives that
`ResolvedTarget`, keeps the loaded content hash for compare-and-swap and holds
a judged sibling lock across validation and replacement. Closed links retain
the link entry and publish their resolved referent; there is no second blanket
symlink policy. Hook backup publication preserves the source mode; restoration
uses a subject-owned rename to preserve its inode and consume the backup name.
These native unlink/rename operations retain an inspection/use race.

Cache resolution inspects without creating directories. An unusable explicit
path refuses with authored provenance; an unusable default disables caching.
Clear returns completion, remaining recognizable entries, directory and reason;
uninspectable shards cannot establish completion. Entry replacement delegates
complete-write and cleanup semantics to Core. Serializer-marker publication
retains its separate implementation.

Console owns `RunTargets`, the lifecycle for report, profile and log destinations.
It judges targets without opening them, includes implicit report stdout in
collision checks, and claims them after configuration, scope, selector and
baseline input checks. Named regular report/profile/graph destinations prepare a
private sibling before analysis, replacing the final inode only after a complete
result is ready. Other hard links retain their old bytes. Equal authored inodes
and equal absent names refuse, including explicit configuration and baseline
inputs; a shell-inherited fd2=fd1 is not a second authored target. Character
devices may coincide. `--clear-cache` refuses a destination or its sibling inside
the physical cache root. Teardown releases held resources and discards owned
siblings; an unsuccessful removal remains a reported failure.

Console's `StagedSignalGuard` owns SIGINT/SIGTERM only for the current staged
operation, latches interruption across worker recovery, checks it at publication
and restores handlers and async mode after cleanup. Staged regular output requires
pcntl, default SIGINT/SIGTERM handlers and no registered Revolt signal callbacks,
otherwise an early
environment refusal precedes sibling creation and analysis. Descriptor/stream
output and log-only runs remain available. Interrupted commands return 128 +
signal after cleanup. Raw asynchronous handlers interrupt a blocking worker
receive; cancellation bypasses per-file recovery and kills pending workers.
An event-loop signal watcher can replace a raw handler and defer interruption
until workers finish, so the guard installs no such watcher. Public callback
inspection cannot identify its signal number; any registered signal callback
therefore refuses preparation. Replacing handlers later in the operation is
unsupported. SIGKILL, cleanup failures and already published targets are
outside this guarantee. Publication is atomic per target; later profile failure
does not roll back an already published report.

Check and Graph share a `RunTargetSession` with those same targets and the
terminal presenter. It retains the primary throwable, attempts cleanup, then
classifies both causes. After a completed report or graph publication, diagnostics
use stderr without a second stdout envelope. Internal failures take exit 1;
environment cleanup takes exit 3 over findings exits. An unsuccessful cleanup
can leave an unwritten target behind and reports that fact. The marker covers
successful presenter return, not partial output followed by an exception inside
the presenter. The existing inner claim cleanup and the application's terminal
presenter fallback retain their separate failure boundaries.

The per-run `LoggerFactory` creates a buffering file logger without opening the
path. After claim it attaches the same held target, publishing the name even
when no record passes the configured minimum level. Append failures latch their
first cause and count lost records rather than escaping into parser recovery
handlers. Console settles logging before report publication and after profile
delivery, and resets the factory on teardown. Graph status uses stderr.

Descriptor existence is judged on both macOS and Linux. Linux fdinfo also permits
a pure preflight refusal of a descriptor opened only for reading. PHP on macOS
does not expose that original flag: duplicate stream metadata describes its
requested mode, so an unknown access mode is left to the real write and its typed
environment refusal. No speculative write is made to establish writability.
