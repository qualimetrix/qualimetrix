# 0091. Declared rule options and one enablement snapshot

**Status:** Accepted

**Date:** 2026-10-01

## Context

Rules had a temporary raw configuration boundary after the document engine had
already judged other owners. Separate merges, name walks and constructor-driven
option dictionaries lost lower authored failures and provenance. Selector filters,
option enabled answers and expensive preparation could disagree. A producer's
metadata entry also does not identify every channel it may emit.

## Decision

Finding declares rules, only_rules and disabled_rules as owner sections of the
ordered configuration document. Options/level declarations own accepted keys,
value forms, shorthand targets and band metadata. Every authored layer is read
before merging, retaining its source and written position. A shorthand expands
in the layer that wrote it; same-layer conflicting forms refuse, distinct layers
merge their leaves. Empty/null bodies write nothing and rule booleans write only
enabled. Reset without enumerating defaults is deliberately not expressed.

One immutable channel universe over candidate computed definitions is used for
authored decide, typed build and final conclude. Every producer is built before
Discovery, including inactive producers. Effective bands retain written/default
halves and their contributors. The completed FindingConfiguration is published
only after all preflight succeeds. Execution and preparation consume final runs;
publication consumes the same decisions and declared per-channel roles.

Public operations live in Finding's Contract namespace:
RuleOptionsBuild in Contract/Configuration, and RuleEnablementResolver,
RuleNameJudge and StatedEnablement in Contract/Selection. Console preflight,
listing and CLI name judgement consume these exact subjects; DI composes them.
The stateless suppression decoder and retired-name advice stay private. Build
accepts only RuleExecutionInterface, so an internal decoder is not exposed as
a replaceable public constructor dependency. Moving the concrete contracts is
smaller than extra facade interfaces/delegation with no second implementation.

FindingConfiguration requires ResolvedDocument and provides four typed copies:
withResolvedOptions, withChannelUniverse, withEnablement and withDiagnostics.
The document/loader/layer ports retain positioned authored nodes, source
diagnostics and Composer's non-authored discovery facts. Raw rule contribution
getters and the five staging/recognition/undeclared-normalizer classes are removed;
their purpose is served by declarations and resolved history rather than aliases.
RULES remains in the canonical DOCUMENT_ROOTS dictionary as the Finding-owned
root, not another validator or a legacy alias.

Producer identities and channel identities are separate. Level-qualified selectors
use declared channel codes, with one witness for code, membership and level.
Namespace-channel suppression does not borrow a sibling's level. Metadata readers
share RuleOptionSurface and ChannelLevelAddressing, not independent raw-name walks.
Listings keep all tied decisive writers with their real origin/layer and distinguish
accepted options from aliases; directive JSON keeps the existing string-list shape.

Refusals preserve the authored spelling, path and source. YAML and CLI diagnostics
therefore have independent source-specific sentences. Retired-option advice names
one replacement through the same template: document keys use canonical names,
while CLI advice retains the authored spelling. Literal equality between those
source-specific sentences is not promised.

A secondary channel can explicitly decline the producer's configured warning
boundary. That is eligibility, not a second numeric declaration or an inference
from judged catalog membership. LCOM's secondary unmatched-method warning remains
project magnitude 1 with no primary LCOM boundary. GodClass can have a configured
boundary while declaring no judged catalog metric.

The public finding fingerprint deliberately excludes the internal addressedProducer
used for admission; its independent invariance observation is separate from the
public identity/boundary field lists. No algorithm or numeric default is changed
by the configuration migration.

## Limits and alternatives

A permissive raw contribution adapter, compatibility constructor, no-op selector
or second static catalogue would preserve two authorities and is rejected. A
blanket enabled:true removal would change authored precedence and is rejected.
Resetting empty maps would require an explicit owner-default representation;
consumers currently write defaults they intend to restore.

The existing threshold completeness guard establishes per-path call count and
known-band membership, not a distinct-band/call bijection. Repeated known bands
can satisfy it. Builder differential/drift regressions observe effects; this
record does not overstate that guard's proof. Producer metadata counts are not
fixed channel counts; computed definitions and levels remain invocation-specific.

## Consumer migration

The CHANGELOG records all 36 outward surfaces individually. Replace fromArray
with fromResolved and supply a completed FindingConfiguration from the registered
document and the actual invocation snapshot. Remove raw selector/merge adapters,
use explicit typed CLI YAML values, fix impossible namespace/level keys, and keep
all diagnostic sources. InlineDirectiveValidator takes policy and identity only.
Produced selection removals and never-run metadata remain distinct in suppressed
output. The existing shared document/run doors and mandatory project-scope measurement remain.

## Exact public import migration

| Old FQCN                                                          | New FQCN                                                                 |
| ----------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild` | `Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild`   |
| `Qualimetrix\Analysis\Finding\Selection\RuleEnablementResolver`   | `Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver` |
| `Qualimetrix\Analysis\Finding\Selection\RuleNameJudge`            | `Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge`          |
| `Qualimetrix\Analysis\Finding\Selection\StatedEnablement`         | `Qualimetrix\Analysis\Finding\Contract\Selection\StatedEnablement`       |

Remove the former optional RuleSuppressionSelectorDecoder constructor argument to RuleOptionsBuild. Its decoding remains internal; there is no decoder contract export or compatibility constructor.
