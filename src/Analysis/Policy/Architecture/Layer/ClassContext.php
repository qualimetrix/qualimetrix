<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

/**
 * Read-only view of a class consumed by {@see LayerDefinition::matches()} to
 * evaluate the layer's membership criteria.
 *
 * The five fields back the five criterion kinds documented on
 * {@see MembershipSpec}:
 *
 * - {@see fqn} → matched against {@code patterns}.
 * - {@see shortName} → matched against {@code suffix}.
 * - {@see attributeFqns} → matched against {@code attributes}.
 * - {@see interfaces} → matched against {@code implements} (already includes
 *   the transitive closure: direct implements + interfaces inherited from
 *   parent classes + interfaces extending other interfaces).
 * - {@see parentClasses} → matched against {@code extends} (already includes
 *   the transitive parent-class chain).
 *
 * Built by {@see ClassContextFactory} from the analysis-run dependency graph.
 * Construction happens in the main process (outside {@code amphp/parallel}
 * workers) from already-merged collection output, so the VO does not need to
 * be serializable for worker transport.
 *
 * For non-class symbols (pure-namespace {@see \Qualimetrix\Core\Symbol\SymbolPath}
 * or an empty FQN) the factory returns a minimal context with empty lists; only
 * the {@code patterns} criterion can match in that case.
 *
 * {@see $graphBacked} separates that case from the one that used to be
 * indistinguishable from it: a context built before the factory was bound to a
 * dependency graph. Both carry three empty lists, but the first is an answer
 * and the second is the absence of one, and only the flag can tell an
 * evaluator which it is holding.
 *
 * {@see $declarationAnalysed} and {@see $ancestryCuts} separate a third case
 * that a bound graph does NOT rule out: the three lists are built from the
 * run's own declaration edges, so they say nothing about a symbol the run never
 * analysed, and the transitive walks stop wherever the next link was not
 * analysed. Both states used to read as a complete answer. See
 * {@see CriterionOutcome}.
 *
 * The cuts are kept in two lists because they reach different criteria. An
 * unread parent class may extend anything and implement anything, so it leaves
 * both `extends` and `implements` open. An unread interface can only hide
 * interfaces: no interface declares a parent class.
 */
final readonly class ClassContext
{
    /**
     * Precomputed `array_fill_keys($attributeFqns, true)` lookup table for
     * attribute haystack queries. Built once at construction so every
     * layer-evaluation pass over this context avoids repeating the
     * O(n) `array_fill_keys` call.
     *
     * @var array<string, true>
     */
    public array $attributeFqnSet;

    /**
     * Precomputed lookup table for the implements haystack. See
     * {@see $attributeFqnSet}.
     *
     * @var array<string, true>
     */
    public array $interfaceSet;

    /**
     * Precomputed lookup table for the extends haystack. See
     * {@see $attributeFqnSet}.
     *
     * @var array<string, true>
     */
    public array $parentClassSet;

    /**
     * Whether the run read this symbol's OWN declaration.
     *
     * Derived rather than passed, because the two facts are one: the subject's
     * own FQN appears in the parent-chain cuts exactly when the run
     * did not read it. False means {@see $attributeFqns} is silence rather than
     * an empty answer — attributes sit on the class itself and reach no
     * further, so this, and not the whole list, is what decides the
     * {@code attributes} criterion.
     */
    public bool $declarationAnalysed;

    /**
     * @param string $fqn Fully-qualified class name without a leading
     *                    backslash (e.g. {@code App\Service\UserService}).
     *                    Empty string is permitted; {@see LayerDefinition::matches()}
     *                    treats it as a non-match.
     * @param string $shortName Class name without the namespace prefix
     *                          (e.g. {@code UserService}). Empty when the
     *                          FQN itself is empty.
     * @param list<string> $attributeFqns Attribute class FQNs applied to this
     *                                    class via {@code #[Attr]}.
     * @param list<string> $interfaces Interface FQNs the class implements
     *                                 transitively (direct + via parent +
     *                                 via interface-extends-interface).
     * @param list<string> $parentClasses Parent-class FQNs in the transitive
     *                                    extends chain (immediate parent
     *                                    first, then grandparent, etc.).
     * @param bool $graphBacked False only when the context was built without a
     *                          dependency graph, so the three lists above say
     *                          nothing about the class rather than saying it
     *                          has no attributes, interfaces or parents.
     *                          Evaluating a graph-backed criterion against such
     *                          a context is a lifecycle error, not a non-match.
     * @param array{parentChain: list<string>, interfaces: list<string>} $ancestryCuts
     *                                                                                 Where the walks stopped at a declaration the run did not read.
     *                                                                                 `parentChain` holds the subject's own FQN when the run did not
     *                                                                                 read it and every parent class reached without facts of its own;
     *                                                                                 `interfaces` every such interface. Past a cut
     *                                                                                 {@see $parentClasses} and {@see $interfaces} are truncated, so a
     *                                                                                 missing entry proves nothing; a present one still does.
     */
    public function __construct(
        public string $fqn,
        public string $shortName,
        public array $attributeFqns = [],
        public array $interfaces = [],
        public array $parentClasses = [],
        public bool $graphBacked = true,
        public array $ancestryCuts = ['parentChain' => [], 'interfaces' => []],
    ) {
        $this->declarationAnalysed = !\in_array($fqn, $ancestryCuts['parentChain'], true);

        $this->attributeFqnSet = $attributeFqns === [] ? [] : array_fill_keys($attributeFqns, true);
        $this->interfaceSet = $interfaces === [] ? [] : array_fill_keys($interfaces, true);
        $this->parentClassSet = $parentClasses === [] ? [] : array_fill_keys($parentClasses, true);
    }

    /**
     * Whether a parent class missing from {@see $parentClasses} proves the
     * class does not extend it.
     */
    public function parentChainKnown(): bool
    {
        return $this->ancestryCuts['parentChain'] === [];
    }

    /**
     * Whether an interface missing from {@see $interfaces} proves the class
     * does not implement it — which needs the parent chain too, since an
     * unread parent may implement anything.
     */
    public function interfacesKnown(): bool
    {
        return $this->parentChainKnown() && $this->ancestryCuts['interfaces'] === [];
    }

    /**
     * Where the walks stopped at a declaration the run did not read, each FQN
     * once.
     *
     * @return list<string>
     */
    public function chainStopsAt(): array
    {
        return array_values(array_unique([...$this->ancestryCuts['parentChain'], ...$this->ancestryCuts['interfaces']]));
    }
}
