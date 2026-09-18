# Stage 06 — `SolePrimitiveOwnership` is split by guarded subject

`governance/` is grouped by the subject each control guards. One group is not:
`SolePrimitiveOwnership` is named for the *form* of its assertion — "this
primitive has exactly one owner and no second hand-written list" — and holds seven
controls guarding seven unrelated subjects.

## The verdict is settled; only the naming is open

Applied on the three cohesion evidences, so that the next session does not
re-litigate it:

- **Name.** "This directory is about ___" completes honestly only as "the controls
  asserting that a primitive has one owner". That is a form of assertion, not a
  subject.
- **Co-change.** A change to `NamespaceMatcher` touches this group and `Core`; a
  change to Coupling's framework classification touches this group and
  `Analysis/Evidence/Coupling`. Seven controls, seven different co-change partners.
- **Counterfactual ownership.** Under independent development each control moves
  wholesale to the subject it guards. Nothing would have to be copied into several
  subjects, which is the test a legitimate cross-cutting group passes.

**Two groups were examined on the same evidences and are not defects**, recorded
because they were suspected and cleared:

- `RepositoryEntrypoints` — "the executable entrypoints of this repository" is a
  subject, not a role. Its weakest member is `MemoryCeilingManifestTest`, which is
  an entrypoint concern only indirectly.
- `FindingVocabulary` — "the closed vocabularies of the Finding subject" is a
  subject, and it has six siblings in an established family
  (`Configuration`, `Measurement`, `Directive`, `Health`, `LayerPolicy`, `Symbol`).
  Renaming it alone would break the family to fix nothing.

## What each of the seven guards

| Control                                    | Subject it guards                                                              |
| ------------------------------------------ | ------------------------------------------------------------------------------ |
| `AnalysisContextScopeArgumentGuardTest`    | `Analysis.Run` — no context inherits project scope by default                  |
| `FrameworkClassificationSiteCountTest`     | `Analysis.Evidence.Coupling` — the mirror of the two call sites stays a mirror |
| `GlobAlphabetSoleEnumerationTest`          | `Core` — one list of glob characters, not two                                  |
| `NamespaceMatcherNormalizationSurfaceTest` | `Core` — every call site leaves normalization to the primitive                 |
| `SuppressionOptionKeyReaderCensusTest`     | `Analysis.Finding` — one reader of suppression option keys                     |
| `TraversalCompletenessTest`                | `Analysis.Evidence.Measurement` — no visitor cuts traversal short              |
| `VersionRootPackageIndependenceTest`       | `Core` — `Version` does not depend on the root package                         |

Three land on `Core`, but on three different primitives, so "a `Core` group" would
reproduce the same defect one level down. Naming the receiving groups is the work
of this stage and is deliberately not decided here: it needs the owner, and mixing
it into stage 04's 114 mechanical moves would hide a naming decision inside a move
package.

## Why this is a separate stage rather than part of 04

Stage 04's subject is that a test file's path declares its owner. These seven files
are not tests and do not live under `tests/`; their path already declares a
governance group, and the defect is which group. Different subject, different
evidence, different risk. It depends on 04 only because 04 rewrites
`testSuitePrefixTable()`, which every new governance group must gain a row in.

## Definition of Done

- `governance/SolePrimitiveOwnership/` does not exist.
- Each of the seven controls sits in a group named for the subject it guards, and
  each group name passes the "this directory is about ___" completion without
  naming a form of assertion.
- Every new group is registered in both places that must agree: a `<directory>`
  under the `Governance` suite in `phpunit.xml.dist` and a row in
  `testSuitePrefixTable()`. An unregistered group reddens
  `composer architecture:check` by name.
- Governance case count unchanged **from whatever it is when this stage starts** —
  this stage moves controls between groups and creates none. The number is not
  written down here on purpose: stage 04's P5 adds a governance group, so any figure
  pinned in advance goes stale before this stage runs. Measure it on the commit this
  stage branches from, with the runner's own exclusions
  (`--exclude-group=benchmark --exclude-group=live-freshness`) — a bare
  `--list-tests` counts two `live-freshness` cases the aggregate never runs.
- `composer check` green.
