<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

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
     */
    public function __construct(
        public string $fqn,
        public string $shortName,
        public array $attributeFqns = [],
        public array $interfaces = [],
        public array $parentClasses = [],
    ) {
        $this->attributeFqnSet = $attributeFqns === [] ? [] : array_fill_keys($attributeFqns, true);
        $this->interfaceSet = $interfaces === [] ? [] : array_fill_keys($interfaces, true);
        $this->parentClassSet = $parentClasses === [] ? [] : array_fill_keys($parentClasses, true);
    }
}
