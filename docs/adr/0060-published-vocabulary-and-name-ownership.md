# 0060. Published Vocabulary and Name Ownership

**Date:** 2026-09-13
**Status:** Accepted

## Context

Qualimetrix publishes identifiers that users store in configuration, baselines,
formulas, command lines, and reports. Earlier changes settled individual names
or kept dated enumerations as a decision record. That made current spelling
depend on migration documents rather than on the product declarations that own
the names.

## Decision

Published identifiers have distinct semantic owners:

- Each producer's capability owns its producer name and channel declarations;
  Finding owns the channel namespace grammar, selectors, and composed identity
  views used by cross-capability consumers.
- Measurement owns catalog metric keys; Computed Metrics owns the validated
  open family of user-defined computed metric names.
- Each rule options class owns the keys and value shapes it accepts;
  Configuration owns root configuration keys.
- Console command definitions own static CLI names. Rule producers own their
  dynamic aliases through Finding's `CliAlias` contract.

Names use lower-case kebab segments under a subject family. A catalog metric is
spelled `family.metric`; an aggregation suffix is a projection of that key, not
a new family. A channel that judges one unshared catalog magnitude uses that
metric key. A channel with no single catalog magnitude, a shared magnitude, or
an occurrence names the verdict or fact it reports. Producer display families
are derived from the producer name rather than declared in a parallel category
enum.

Selectors compare complete names. `family.*` is the explicit family wildcard;
a bare family name is a producer selector only where the command contract says
so. Producer configuration and emitted-channel selection remain different
operations.

Vocabulary completeness is derived from the owning declarations at the point
where run-time families are configured. Static registries that deliberately
omit run-time computed channels are not completeness oracles. A dated inventory
may explain a migration, but it is neither the source of truth nor a permanent
dependency of an ADR.

A rename is breaking. An old input spelling is not retained as a compatibility
alias: validating surfaces refuse or diagnose it, the consumer migration is
recorded in the changelog, and the finding equivalence gate declares the
intended mapping. Suppression names describe findings that were produced and
then hidden; exclusion names are reserved for inputs that never enter
production.

This ADR consolidates the current vocabulary and name-ownership policy. ADRs
0024, 0029–0037, 0044, 0046, and 0047 retain the detailed rationale for the
underlying identity, presentation, shape, and suppression contracts.

## Consequences

- A new published name is added at its semantic owner, not to an independent
  master vocabulary table.
- Completeness checks enumerate all owning declarations, including configured
  run-time names, and state any intentionally open set.
- Reports, configuration, and CLI adapters consume the same owned identities
  without inferring semantics from a prefix or an obsolete category.
