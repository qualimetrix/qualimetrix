# How the channel table was obtained, and what the method does not see

## What went wrong the first time

The first version of this file defined its target as "everything that reads the
`Extends` edge". That definition is why the enumeration missed three of the four
channels: it named an **edge type** as the subject, so sibling channels of the
same defect — `implements`, attributes, and `trait_use` — were outside the
population by construction, not by oversight. A reviewer found `trait_use`;
measurement found the attribute channel.

The corrected subject is **"every edge that describes an anonymous class's own
declaration but is recorded against the enclosing class"**. That phrasing is
about the defect, not about one of its symptoms, and it is what Part 1 of the
table enumerates.

## Channels covered, and by what

| Channel                                   | Instrument                                                                                                    | Result                                                                                                                                                                 |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Edges emitted for the anonymous header    | read of `ClassLikeHandler::handleClass()` and `handleEnum()` in full                                          | extends, implements, attributes                                                                                                                                        |
| Edges emitted from the anonymous body     | read of `DependencyVisitor::enterNode()` dispatch path + `TraitUseHandler`                                    | `trait_use`, confirmed by `graph:export` on a fixture                                                                                                                  |
| Direct enum-case readers                  | `grep -rn 'DependencyType::' src/`                                                                            | Part 2 of the table                                                                                                                                                    |
| Enum method behaviour                     | Serena `find_referencing_symbols` on `DependencyType/isStrongCoupling`, then on `Dependency/isStrongCoupling` | one hop to `Dependency`, then tests only — no production consumer                                                                                                      |
| String value (`'extends'`)                | `grep -rn 'type->value\|DependencyType::tryFrom'`                                                             | baseline identity, layer-violation fields, JSON graph export                                                                                                           |
| Configuration vocabulary                  | read of `AllowAliasExpander::ALIASES` in full                                                                 | `New_` is in no alias group; `relations:` also takes bare enum values via `tryFrom`                                                                                    |
| Process boundary                          | `grep -rn 'new Dependency('` over `tests/` → `FileProcessingResultWireFormatTest`                             | `Dependency` is serialized for parallel workers; its 4-arg shape is pinned                                                                                             |
| Tests asserting anonymous-class behaviour | `grep -rln -i anonymous tests/ governance/`                                                                   | two, one of which cannot fail on this defect                                                                                                                           |
| Gate corpus                               | `grep -rn 'new class' finding-gate/`                                                                          | zero matches                                                                                                                                                           |
| Website pages naming the metrics          | `grep -rln 'design.dit\|design\.noc' website/docs/`                                                           | `rules/design.md`, `rules/index.md`, `reference/default-thresholds.md`, `reference/remediation-time.md`, `usage/cli-options.md` — there is no separate DIT or NOC page |

## What these instruments do not see

- **Reflection and DI.** No sweep was run for a `DependencyType` or `Dependency`
  reached by reflection or built from a container parameter. Judged low risk —
  both are value objects constructed during extraction — but not proven.
- **Consumer `qmx.yaml` files outside this repository.** A project whose config
  says `relations: [inheritance]` has no local witness. This is the channel that
  makes the difference between fork A and fork D a contract question rather than
  an internal one.
- **Consumer baseline files.** Same shape: a stored finding keyed on
  `…|extends` cannot be found by grepping this tree. Fork D leaves the key
  unchanged, which is why it avoids the problem rather than managing it.
- **Nested anonymous classes** are measured and covered: an anonymous class two
  levels deep still lends its trait to the outermost named class before the
  cure, and the depth counter treats any depth >= 1 as anonymous.
- **A named class nested inside an anonymous class cannot exist.** The depth
  counter would, in principle, flag such a class's own `use T;` as anonymous,
  because the counter does not reset when `enterNamedClassLike()` fires. PHP
  refuses the shape outright — "Class declarations may not be nested" — so the
  only class-like reachable inside an anonymous body is another anonymous
  class, which never enters that branch. Measured by attempting it, not
  assumed; if PHP ever permits nesting, this becomes a live false positive.
- **Anonymous classes inside enums or traits** are still not measured.
- **The `measured` column is the boundary of evidence.** A row reading `no`
  is a claim read off the code, not an observation. Two such rows are open
  questions the plan sends to P1 rather than assuming.
