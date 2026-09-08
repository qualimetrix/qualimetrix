# Pakiet E — corpus directives retargeted, to be reverted after GREEN

The gate replays the reference commit (`ec1597d6`) by rewriting `args` and
configuration, but never touches the analyzed corpus sources. Under the new
(candidate) vocabulary, an inline directive still spelled with an old name
becomes a genuine `annotation.unresolved-directive` finding — a real
divergence the declared rename delta does not absorb. Snapping the directives
to the new spelling was not an option either: `annotations` is authoritative,
and it uniquely produces `annotation.invalid-threshold@file` /
`annotation.unresolved-directive@file` claims that removing the directives
would starve, dropping the run to `PARTIAL` via `--incomplete-corpus`, which
the pass's DoD forbids. So the three directives below were **retargeted** —
pointed at a name the step does not rename — for the GREEN window only.

## What was changed, and why each target holds the case's claim

| file:line (measured on this tree)                         | old                                                       | new                                                      | why this target                                                                                                                                                                                                                                                                                                |
| --------------------------------------------------------- | --------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `finding-gate/cases/annotations/src/Directives.php:22`    | `@qmx-threshold complexity.cyclomatic warning=notanumber` | `@qmx-threshold complexity.cognitive warning=notanumber` | Only the threshold syntax matters to `annotation.invalid-threshold`; the rule name just has to resolve and accept `@qmx-threshold`, which `complexity.cognitive` does.                                                                                                                                         |
| `finding-gate/cases/annotations/src/Directives.php:67`    | `@qmx-ignore duplication.code-duplication:class`          | `@qmx-ignore architecture.circular-dependency:class`     | The directive tests a channel:level pair the channel never declares. `architecture.circular-dependency` is `SymbolLevel::Project`-only (`src/Analysis/Evidence/CircularDependency/CircularDependencyRule.php:190`), the same shape `duplication.code-duplication` had, so `:class` still can never match.      |
| `finding-gate/cases/applied-threshold/src/Retuned.php:15` | `@qmx-threshold complexity.cyclomatic warning=2`          | `@qmx-threshold complexity.cognitive warning=2`          | This case is `coverage: auxiliary` (`CaseDefinition::COVERAGE_AUXILIARY`): it is compared on every surface and must emit exactly what it claims. The applied override still has to publish an annotated-not-configured number on `classify()`; `complexity.cognitive` does that under its own, unrenamed name. |

Retargeting the `applied-threshold` directive means the case's sole producer
of `complexity.ccn@callable` (formerly `complexity.cyclomatic@callable`)
disappeared, so **`complexity.ccn@callable` was removed from
`finding-gate/cases/applied-threshold/case.json`'s `channels` array** for the
same window. This does not weaken coverage: `complexity.ccn` stays covered
authoritatively by the `complexity` case, and an auxiliary case's channels are
excluded from the coverage arithmetic by definition.

## Verified on this tree (commands are exact; re-run to reproduce)

`annotations`, multiset unchanged (still `annotation.unresolved-directive`×4,
`annotation.invalid-threshold`×1, `annotation.unsupported-threshold`×1,
`annotation.unused-directive`×1; exit 2):

```
php bin/qmx check finding-gate/cases/annotations/src \
  --config=finding-gate/cases/annotations/qmx.yaml \
  --rule-opt=coupling.class-rank:warning=2 \
  --rule-opt=coupling.class-rank:error=2 \
  --rule-opt=coupling.distance:max_distance_warning=2 \
  --rule-opt=coupling.distance:max_distance_error=2 \
  --format=json --workers=0
```

`applied-threshold`, now exactly its (updated) claim — `complexity.cognitive`
×3 (`@callable`×2, `@class`×1), no `complexity.ccn` at all, exit 0:

```
php bin/qmx check finding-gate/cases/applied-threshold/src \
  --config=finding-gate/cases/applied-threshold/qmx.yaml \
  --rule-opt=coupling.class-rank:warning=2 \
  --rule-opt=coupling.class-rank:error=2 \
  --rule-opt=coupling.distance:max_distance_warning=2 \
  --rule-opt=coupling.distance:max_distance_error=2 \
  --format=json --workers=0
```

## What the orchestrator must return after GREEN

In one commit, after the finding-equivalence gate is GREEN and the vocabulary
comparator has been unfrozen for `Х12П4`:

1. Move `finding-gate/cases/annotations/src/Directives.php:22` to
   `@qmx-threshold complexity.ccn warning=notanumber`.
2. Move `finding-gate/cases/annotations/src/Directives.php:67` to
   `@qmx-ignore duplication.clone:class`.
3. Move `finding-gate/cases/applied-threshold/src/Retuned.php:15` to
   `@qmx-threshold complexity.ccn warning=2`.
4. Restore `"complexity.ccn@callable"` to
   `finding-gate/cases/applied-threshold/case.json`'s `channels` array (its
   spelling stays `complexity.ccn`, not `complexity.cyclomatic` — the
   directive is what moves back, not the channel vocabulary).
5. Remove the corresponding rows from `finding-gate/maps/channels.tsv`,
   `finding-gate/maps/metric-keys.tsv` and `finding-gate/maps/inputs.tsv`
   (all five `Х12П4`-reasoned rows in each, plus the hand-authored
   `coverage:` → `coverage-gap:` row in `inputs.tsv`) — the maps' job ends
   once the candidate dictionary is the only dictionary the gate ever runs.

Line numbers above were measured on this tree at the time Pakiet E ran;
re-locate by content, not by line number, since sibling packages may have
shifted surrounding lines by the time this is applied.

> **Corrected by the orchestrator.** This section first said *revert to*
> the old spellings. That direction is wrong: after GREEN the tree is
> renamed and `complexity.cyclomatic` is no longer a rule, so reverting
> would reintroduce `annotation.unresolved-directive` and break the
> `applied-threshold` claim. The directives move to the NEW spelling,
> which is what `00-overview.md` and `03-gate-and-hand-surfaces.md` say.
> Both cases were re-run after the move: `annotations` reproduces its
> original multiset exactly, and `applied-threshold` emits
> `complexity.ccn` x1 plus `complexity.cognitive` x2 — exactly its claims.
