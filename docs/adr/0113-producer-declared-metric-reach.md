# 0113. Producer-declared metric reach

**Date:** 2026-10-11
**Status:** Accepted

## Context

Moving declaration-local cohesion calculation after file collection must not
change which baseline ceilings can be compared. The aggregation service inferred
Run reach from a producer's execution phase, so a check of one file could no
longer apply a whole-project cohesion baseline, even when every member of the
subject was present. An increased local value could therefore evade its ceiling.

## Decision

GlobalContextCollectorInterface requires getMetricReach(): MetricReach. Each
producer declares Members or Run for every key it provides, including facts
without MetricDefinition aggregation metadata. Measurement records that
declaration without naming capabilities, classes or metric-specific exceptions.
Declaration-local cohesion declares Members; existing graph-dependent producers
retain Run. Execution order and aggregation metadata remain separate concerns.

A new role port or a metric-name map would duplicate the existing producer
contract. Inferring reach from symbol level would also be wrong: a class metric
can depend on the whole graph.

## Consequences

Custom global collectors must implement the method and choose reach by evidence
dependency. A producer whose outputs have different reach must separate those
producer declarations. Baseline and global comparison policies stay unchanged;
only the facts they receive are corrected. Changing a collector's phase alone
cannot broaden the declared dependency of its outputs.
