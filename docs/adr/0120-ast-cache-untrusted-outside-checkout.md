# 0120. AST Cache Is Untrusted Input Outside the Checkout

**Date:** 2026-10-15
**Status:** Accepted.

## Context

A project-local cache mixes generated data with the checkout and makes CI restore paths, workers and multiple worktrees disagree about ownership. Deserializing old or foreign entries must never turn a cache hit into trusted program input.

## Decision

The default cache lives below the platform user cache directory in `qmx`, shared by that user's projects. The Docker image uses `/cache`; persistence requires a mounted volume owned by the same uid that runs qmx. Location eligibility is judged when caching is enabled or explicit clearing uses the location: an explicit root inside the checkout refuses, while an unusable implicit default disables caching with one warning. `--no-cache` without `--clear-cache` does not judge an unused root. Cache root eligibility checks the root's effective owner and group/other write bits. It does not establish trust for every ancestor. Explicit relative paths resolve from the project root before judgement; the resolved path uses the existing POSIX absolute-path grammar. Windows drive and UNC paths are not supported by this contract. When the project root is `/` (for example, a cron invocation from `/`), containment is not checked; all other location judgements still apply. An unsupported explicit path refuses when the location is used; an unsupported default disables caching with a warning.

An entry key includes the source bytes, PHP minor version, exact parser version and parsing state, including `short_open_tag`, `zend.multibyte` and `zend.script_encoding`. The cache format version selects the `ast-v<N>` directory instead of entering the key. The codec accepts only its current format and an allowlisted AST shape; a missing, corrupt or foreign entry costs a parse miss. The cache does not use igbinary and does not read earlier formats. A worker receives the accepted directory and opens its own cache instance; the coordinator retains the run's cache lifecycle.

`--clear-cache` removes qmx entries from the chosen root, across projects sharing it, without deleting unrelated files. Each open sweeps 16 of 256 shards, removing entries not read for 30 days and evicting oldest entries from shards over 8 MiB. After 16 successful sweep steps, the per-format target is 2 GiB plus entries written since each shard's last sweep and one oversized newest entry per shard; it is not an immediate hard quota. Sweep and unreadable-entry reporting are bounded; failures to remove a requested clear entry are environment refusals.

## Consequences

Old `.qmx-cache` entries can be deleted. A CI cache must be outside the checkout, and GitLab `cache:paths` inside the checkout cannot express this layout. The root check and POSIX path grammar are explicit limits; this ADR does not claim secure ancestors or Windows-drive support.

Cache publication, pruning and clearing share Core's temporary-sibling name
predicate. Old temporary siblings in entry shards and version metadata are
removed after the same 30-day age limit; recent siblings survive quota eviction
and their bytes are additional to the cache-entry target. Clearing preserves
foreign data but reports refusal whenever a detached directory remains, so a
retained directory cannot accompany a successful "Cache cleared" message.
