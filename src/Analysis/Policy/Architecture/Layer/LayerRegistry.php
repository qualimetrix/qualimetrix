<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Closure;
use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Owns the full ordered set of {@see LayerDefinition} instances declared by
 * configuration and resolves classes to their owning layer.
 *
 * Resolution semantics — **declaration order, first match wins**:
 * - {@see resolveLayer()} iterates definitions in declared order and returns
 *   the name of the first layer whose membership criteria match (or null if
 *   no layer matches). This is the hot path used during dependency-edge
 *   analysis.
 * - {@see resolveAll()} returns every layer whose criteria match, in
 *   declaration order. The first entry is the assignment; the rest are
 *   layers that would have matched if they were declared earlier. Used by
 *   evidence-based shadow detection and the debug command.
 *
 * - {@see excludedLayers()} returns the layers whose positive criteria
 *   matched and whose `exclude:` clause then removed the class. It is a
 *   second exit of the very same walk, not a second walk, and it changes
 *   nothing about assignment.
 * - {@see undecidedLayers()} returns the layers the run could not answer for
 *   the class that bear on its assignment. Third exit of the same walk, and
 *   it changes nothing about assignment either — see its own note for why
 *   that is deliberate, and for which unanswered layers bear on it.
 * - {@see unansweredExcludeLayers()} returns the layers whose positive
 *   criteria matched and whose `exclude:` clause could not be answered,
 *   winning or not. Fourth exit, for the one reader asking about a clause
 *   rather than about the class.
 * - {@see contenders()} returns the layers that could own the class once
 *   every unanswered layer bearing on it is answered. Fifth exit, for the
 *   readers that must not draw a conclusion from an assignment in doubt.
 * - {@see establishedMatches()} returns the matches the run established
 *   whatever the unanswered layers answer. Sixth exit, for the readers that
 *   conclude something from which layer loses the class.
 *
 * Every lookup shares a single cache keyed by
 * {@see SymbolPath::toCanonical()}: every output of the walk is computed once
 * and stored together, and {@see resolveLayer()} reads the first entry off the
 * match list. A class queried by every method therefore walks the criteria at
 * most once. The cache is the
 * only mutable state on the registry (which is therefore final but not
 * readonly).
 *
 * The registry holds a {@see ClassContextFactory} that produces the
 * {@see ClassContext} consumed by {@see LayerDefinition::matches()}. The
 * factory is per-analysis-run state:
 * {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::prepare()}
 * binds the run's dependency graph via {@see bindGraph()}, which also drops
 * the match cache so the new graph's data is picked up. Before
 * {@see bindGraph()} is called the factory operates in no-graph mode (config
 * load, or a registry built without a run behind it): {@code patterns} and
 * {@code suffix} still resolve, and a layer declaring {@code attributes},
 * {@code implements} or {@code extends} refuses instead of resolving to
 * nothing.
 *
 * There is intentionally no specificity scoring, no collision detection,
 * and no exception class for ambiguity — declaration order is the user's
 * tool to express intent, and the engine does not second-guess it. The
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator}
 * emits `architecture.unreachable-layer` and `architecture.potential-shadow`
 * to surface misordered or overlapping declarations.
 */
final class LayerRegistry
{
    /**
     * @var list<LayerDefinition>
     */
    private array $layers;

    private ClassContextFactory $contextFactory;

    /**
     * Shared cache for {@see resolveLayer()}, {@see resolveAll()} and
     * {@see excludedLayers()}. Keyed by {@see SymbolPath::toCanonical()}.
     *
     * Each value carries BOTH outputs of the one walk over the layer list:
     * `matches`, the complete list of {@see LayerMatch} entries in declaration
     * order (empty means the class matches no layer), `excluded`, the
     * names of the layers whose positive criteria matched but whose
     * `exclude:` clause then removed the class, `undecided`, the names of
     * the layers the run could not answer either way that bear on the
     * assignment, `unansweredExcludes`, the matching layers whose `exclude:`
     * went unanswered, `contenders`, the layers that could own the class once
     * those are answered, `established`, the matches whose `exclude:` was
     * answered, and `chainStopsAt`, where the inheritance chain stopped
     * when a layer bearing on the assignment went unanswered. One entry rather than
     * parallel arrays because separate caches drift apart at every early return
     * and at {@see clearCache()}: a lookup that found one populated and the
     * others not would report an exclusion that never happened.
     *
     * @var array<string, array{matches: list<LayerMatch>, excluded: list<string>, undecided: list<string>, unansweredExcludes: list<string>, contenders: list<string>, established: list<LayerMatch>, chainStopsAt: list<string>}>
     */
    private array $matchCache = [];

    /**
     * @param list<LayerDefinition> $layers Layer definitions in declaration order;
     *                                      layer names must be unique.
     * @param ClassContextFactory|null $contextFactory Optional factory injection
     *                                                 for tests / DI; defaults
     *                                                 to a fresh no-graph instance.
     *
     * @throws InvalidArgumentException If two layers share the same name.
     */
    public function __construct(array $layers, ?ClassContextFactory $contextFactory = null)
    {
        $seenNames = [];
        foreach ($layers as $layer) {
            $name = $layer->name();
            if (isset($seenNames[$name])) {
                throw new InvalidArgumentException(\sprintf(
                    'Duplicate layer name "%s" — each layer must have a unique identifier.',
                    $name,
                ));
            }
            $seenNames[$name] = true;
        }

        $this->layers = $layers;
        $this->contextFactory = $contextFactory ?? new ClassContextFactory();
    }

    /**
     * Returns the underlying {@see ClassContextFactory} so a caller can hand
     * the same instance to something that reads contexts outside the registry
     * — which is what {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::prepare()}
     * does for template expansion, and why a second factory beside this one
     * is always a defect.
     */
    public function contextFactory(): ClassContextFactory
    {
        return $this->contextFactory;
    }

    /**
     * Convenience: forwards to {@see ClassContextFactory::bindGraph()} and
     * invalidates the match cache so subsequent lookups pick up the new
     * graph's data.
     *
     * @param iterable<SymbolPath>|null $analysedClasses The run's analysed
     *                                                   declarations, forwarded
     *                                                   verbatim — see
     *                                                   {@see ClassContextFactory::bindGraph()}
     *                                                   for what omitting them
     *                                                   costs.
     * @param (Closure(string): bool)|null $installDeclares Forwarded verbatim, like the declarations.
     */
    public function bindGraph(?DependencyGraphInterface $graph, ?iterable $analysedClasses = null, ?Closure $installDeclares = null): void
    {
        $this->contextFactory->bindGraph($graph, $analysedClasses, $installDeclares);
        $this->matchCache = [];
    }

    /**
     * Drops cached layer matches. Useful when the registry instance is reused
     * across analysis runs (e.g. test fixtures sharing a configuration object)
     * and the underlying graph data may have changed.
     */
    public function clearCache(): void
    {
        $this->matchCache = [];
    }

    /**
     * Returns the name of the first layer (in declaration order) whose
     * membership criteria match the class, or null if no layer matches.
     *
     * This is the hot path for {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule}
     * — called once per dependency-edge endpoint.
     */
    public function resolveLayer(SymbolPath $class): ?string
    {
        $matches = $this->resolveAll($class);

        return $matches === [] ? null : $matches[0]->layerName;
    }

    /**
     * Returns every layer whose membership criteria match the class, in
     * declaration order.
     *
     * Returns an empty list when no layer matches. The first entry is the
     * actual assignment; subsequent entries are layers that would have matched
     * had they been declared earlier (used by `architecture.potential-shadow`
     * and the debug command).
     *
     * @return list<LayerMatch>
     */
    public function resolveAll(SymbolPath $class): array
    {
        return $this->walk($class)['matches'];
    }

    /**
     * Returns the names of every layer whose positive criteria matched the
     * class and whose `exclude:` clause then removed it, in declaration
     * order.
     *
     * The second exit of the same cached walk {@see resolveAll()} reads, not a
     * second walk: the two answers are produced by one pass over the layer
     * list and stored together. Membership is unaffected — an excluded layer
     * is absent from {@see resolveAll()} exactly as it always was.
     *
     * `architecture.unmatched-exclude` is the only consumer, and it needs this
     * because a class the clause removed and a class the positive criteria
     * never caught are the same "layer is not in the list" from the outside.
     *
     * @return list<string> layer names
     */
    public function excludedLayers(SymbolPath $class): array
    {
        return $this->walk($class)['excluded'];
    }

    /**
     * Returns the names of the layers the run could not decide for this symbol
     * and that bear on its assignment, in declaration order.
     *
     * **Which unanswered layers bear on it** is decided here and nowhere else:
     * for a symbol no layer matched, every one of them; for an assigned
     * symbol, those declared before the first match the run established for
     * certain — an earlier layer that might have owned it, the assigned
     * layer's own `exclude:`, and, when that `exclude:` is the unanswered
     * one, a layer after it that would own the symbol if the clause removed
     * it. A layer declared after a certain match is left out, because first
     * match wins and it could not have owned the symbol whatever it answered.
     * So a non-empty list beside a match means exactly "this assignment can
     * change once the chain is analysed", and every reader of the doubt —
     * `architecture.coverage-gap`, `architecture.doubted-assignment`,
     * `debug:layer-assignment` — reads that one statement instead of
     * re-deriving it from the declaration order.
     *
     * The third exit of the same cached walk, for the same reason
     * {@see excludedLayers()} is the second. A symbol in nobody's layer
     * because the run answered every criterion is a hole the author closes by
     * writing a layer, a symbol in nobody's layer because its inheritance
     * chain left the analysed set is not, and an assignment that stands on a
     * layer the run could not fully answer is a doubt the reader must see.
     *
     * **Assignment is unaffected on purpose.** Neither an undecidable layer
     * declared before one that matched nor an undecidable `exclude:` on the
     * matching layer itself withdraws the match, even though a strictly
     * three-valued reading would make the assignment unknown. Withdrawing it
     * would leave the class in no layer, so no allow-list would judge its edges
     * and real violations would stop being reported — trading a wrong answer
     * for a missing one. The match stands and the doubt is published beside
     * it; an assigned layer whose own exclude went unanswered is named both in
     * the match list and here.
     *
     * @return list<string> layer names
     */
    public function undecidedLayers(SymbolPath $class): array
    {
        return $this->walk($class)['undecided'];
    }

    /**
     * Returns the names of every layer whose positive criteria matched the
     * symbol and whose `exclude:` clause could not be answered about it, in
     * declaration order, whichever layer won the symbol.
     *
     * Not a subset of {@see undecidedLayers()}: the question is about the
     * clause, not the assignment. `architecture.unmatched-exclude` asks
     * whether a clause ever made a difference, and a clause that could not
     * answer for a symbol the layer caught — shadowed or not — has not been
     * shown to make none.
     *
     * @return list<string> layer names
     */
    public function unansweredExcludeLayers(SymbolPath $class): array
    {
        return $this->walk($class)['unansweredExcludes'];
    }

    /**
     * Returns the layers that could own the symbol once every layer in
     * {@see undecidedLayers()} is answered, in declaration order: each layer
     * the run could not answer and each layer that matched, up to and
     * including the first match the run established for certain. Empty when
     * nothing bears on the assignment — then the assignment is the only
     * outcome and there is no contest.
     *
     * The readers are the ones that conclude something from who won:
     * `architecture.unreachable-layer` may not call a layer that could still
     * own an analysed class one that owns nothing,
     * `architecture.doubted-assignment` names the layer that would own a
     * symbol if a clause in front of it removed it, and
     * `debug:layer-assignment` prints the list.
     *
     * @return list<string> layer names
     */
    public function contenders(SymbolPath $class): array
    {
        return $this->walk($class)['contenders'];
    }

    /**
     * Returns the matches the run established for the symbol, in declaration
     * order: every match but those whose `exclude:` went unanswered. The
     * first is the first match the run established — where
     * {@see contenders()} stops — so every later one loses the symbol
     * whatever the unanswered layers answer, while the first may still lose
     * it to a contender in front of it.
     *
     * The reader is the verdict about who loses rather than who wins:
     * `architecture.potential-shadow` and the shadow `debug:layer-assignment`
     * reports. A match whose clause may still remove the symbol neither
     * shadows nor is shadowed, and a match behind the first established one
     * is shadowed however the clauses in front of it answer.
     *
     * @return list<LayerMatch>
     */
    public function establishedMatches(SymbolPath $class): array
    {
        return $this->walk($class)['established'];
    }

    /**
     * Returns where the class's inheritance chain stopped because the run did
     * not read the declaration there — the subject's own FQN when it was not
     * analysed — and nothing when every layer was answered.
     *
     * A reader told only that a layer could not be answered cannot tell
     * which boundary to move; this names it.
     *
     * @return list<string> FQNs
     */
    public function chainStopsAt(SymbolPath $class): array
    {
        return $this->walk($class)['chainStopsAt'];
    }

    /**
     * @return array{matches: list<LayerMatch>, excluded: list<string>, undecided: list<string>, unansweredExcludes: list<string>, contenders: list<string>, established: list<LayerMatch>, chainStopsAt: list<string>}
     */
    private function walk(SymbolPath $class): array
    {
        $cacheKey = $class->toCanonical();
        if (\array_key_exists($cacheKey, $this->matchCache)) {
            return $this->matchCache[$cacheKey];
        }

        $context = $this->contextFactory->build($class);
        if ($context->fqn === '') {
            return $this->matchCache[$cacheKey] = ['matches' => [], 'excluded' => [], 'undecided' => [], 'unansweredExcludes' => [], 'contenders' => [], 'established' => [], 'chainStopsAt' => []];
        }

        $outcome = $this->walkLayers($context);

        return $this->matchCache[$cacheKey] = $outcome + [
            'chainStopsAt' => $outcome['undecided'] === [] ? [] : $context->chainStopsAt(),
        ];
    }

    /**
     * The walk proper: every layer asked once, in declaration order.
     *
     * @return array{matches: list<LayerMatch>, excluded: list<string>, undecided: list<string>, unansweredExcludes: list<string>, contenders: list<string>, established: list<LayerMatch>}
     */
    private function walkLayers(ClassContext $context): array
    {
        $matches = [];
        $excluded = [];
        $unansweredExcludes = [];
        $established = [];
        $answers = [];
        foreach ($this->layers as $layer) {
            $result = $layer->matches($context);
            if ($result->isExcluded()) {
                $excluded[] = $layer->name();

                continue;
            }
            $answers[] = [$layer->name(), $result];
            if (!$result->matched) {
                continue;
            }
            $match = new LayerMatch($layer->name(), $result->matchedCriteria);
            $matches[] = $match;
            if ($result->undecided) {
                $unansweredExcludes[] = $layer->name();
            } else {
                $established[] = $match;
            }
        }

        $open = self::openQuestions($answers);

        return [
            'matches' => $matches,
            'excluded' => $excluded,
            'undecided' => $open['undecided'],
            'unansweredExcludes' => $unansweredExcludes,
            'contenders' => $open['contenders'],
            'established' => $established,
        ];
    }

    /**
     * The layers the run could not answer that bear on the assignment, and
     * the layers that could own the symbol once they are answered.
     *
     * Both stop at the first match the run established for certain: first
     * match wins, so nothing after it could own the symbol. A match whose
     * `exclude:` went unanswered does not stop them — the clause may remove
     * the symbol, and then a later layer owns it — and is itself read before
     * it counts as a match, so the assigned layer's unanswered `exclude:`
     * bears on its own assignment.
     *
     * @param list<array{0: string, 1: MembershipResult}> $answers Every layer that did not exclude the
     *                                                             symbol, in declaration order.
     *
     * @return array{undecided: list<string>, contenders: list<string>}
     */
    private static function openQuestions(array $answers): array
    {
        $undecided = [];
        $contenders = [];
        foreach ($answers as [$layerName, $result]) {
            if ($result->undecided) {
                $undecided[] = $layerName;
            }
            if ($result->matched || $result->undecided) {
                $contenders[] = $layerName;
            }
            if ($result->matched && !$result->undecided) {
                break;
            }
        }

        return ['undecided' => $undecided, 'contenders' => $undecided === [] ? [] : $contenders];
    }

    /**
     * Returns layer names in **declaration order** (NOT alphabetically
     * sorted). The order is meaningful — it is the user's disambiguation
     * tool and the factory's cross-validation reference.
     *
     * @return list<string>
     */
    public function layerNames(): array
    {
        return array_map(static fn(LayerDefinition $layer): string => $layer->name(), $this->layers);
    }

    public function isEmpty(): bool
    {
        return $this->layers === [];
    }

    /**
     * @return list<LayerDefinition>
     */
    public function definitions(): array
    {
        return $this->layers;
    }
}
