# 0123. Worker Process Boundary and Strategy Port

**Date:** 2026-10-15
**Status:** Accepted

## Context

Parallel collection has a coordinator and a child process. A task, its
serialized composition, bootstrap, startup diagnostics and the Amphp runtime
necessarily cross that boundary. A proposed `Worker` submodule would classify
files by process side, but process side is a transport role, not an independent
subject. Changes to the task payload or child diagnostics commonly change
code on both sides of IPC, so raw co-change alone cannot identify a stable
subject split there.

The current Parallel root has substantial outgoing coupling within the task,
note and Amphp protocols. An apparently smaller root obtained by moving its
child-side classes would transfer the same protocol breadth to a new owner.
Namespace CBO also abstains when a proposed namespace has fewer than its
configured minimum number of own classes; such a move would not prove that
coupling improved.

## Decision

Keep the worker transport together in `Infrastructure/Parallel` until a
candidate has its own subject, lifecycle and independently changing code.
Counterfactual ownership decides an ambiguous IPC seam: the bootstrap is the
receiving end of Parallel's task protocol and belongs with that protocol.
A split must survive focused code-line co-change evidence across its boundary,
meet the namespace rule's own-class population, and keep both resulting
subjects' quality signals visible. The current evidence does not establish
one. Treat the root's coupling as a known modeling and implementation
limitation under its existing finite ratchet; a new edge that breaches it
requires a fresh design decision. Do not add a facade or move files solely to
lower the reported count.

The Run-owned execution strategy contract has one operation,
`execute(files, processor)`. Its existing batch-permission argument,
availability query and parallel-capability marker add no decision: the
production caller always permits parallelism, and worker selection belongs to
the strategy selector. Remove those redundant surfaces. The parallel strategy
checks process capability during execution, and `WorkerPool` probes actual
startup. Keep `WorkerRequest` as the carrier of the accepted count and its
explicitness. The first worker probe, process-start
deadline and bounded diagnostic drain retain the startup contract in
[ADR 0121](0121-parallel-collection-judged-by-worker-start.md): explicit
parallel selection refuses a failed start, while automatic selection may warn
and fall back to sequential collection.

## Consequences

The strategy API executes the selected model; `WorkerPool` answers whether
workers actually start. Sequential and parallel output and refusal paths
remain governed by the same run contracts. Future transport changes must
preserve the task and note protocols and test both explicit and automatic
selection. A new subject boundary needs independent evidence beyond a smaller
root metric or a green run that abstains on the proposed namespace.
