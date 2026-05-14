<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

/**
 * Minimal read-only view of a class needed by
 * {@see LayerDefinition::matches()} to evaluate the layer's membership
 * criteria.
 *
 * Step A populates only the FQN and the short name (the data
 * {@see LayerRegistry} already had via {@see \Qualimetrix\Core\Symbol\SymbolPath}).
 * Step B will extend the VO with resolved attribute FQNs, the interface
 * chain and the parent-class chain so the `attributes`, `implements` and
 * `extends` membership criteria can be evaluated without exposing AST nodes
 * to the rule layer.
 *
 * The VO is constructed outside the worker boundary (in the main process)
 * from already-merged collection output, so it does not need to be
 * serializable for {@code amphp/parallel}.
 */
final readonly class ClassContext
{
    /**
     * @param string $fqn Fully-qualified class name without a leading
     *                    backslash (e.g. {@code App\Service\UserService}).
     *                    Empty string is permitted; {@see LayerDefinition::matches()}
     *                    treats it as a non-match.
     * @param string $shortName Class name without the namespace prefix
     *                          (e.g. {@code UserService}). Empty when the
     *                          FQN itself is empty.
     */
    public function __construct(
        public string $fqn,
        public string $shortName,
    ) {}
}
