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
ownership of the link. Group or other write permission permits placement;
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

Existing regular targets open without truncation. The opened identity must
match both the judged inode and the current name before writing. New names use
a random exclusive sibling and hard-link publication, refusing a competing
entry rather than falling back to overwrite. Held writes and append perform
complete writes and flush; append locks the file and seeks to its current end.
Replacement writes a complete sibling first, preserves the old mode unless an
explicit mode is supplied, and distinguishes exclusive creation from declared
last-writer-wins publication. A held lock checks its named inode after locking;
releasing the lock does not remove its name. Its acquisition deadline uses a
monotonic clock, so a system-clock adjustment cannot shorten or extend it.

Descriptor duplication preserves the supplied stream's offset and avoids
truncation. A path-opened handle with the e mode is close-on-exec. PHP's
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
