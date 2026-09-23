<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with 'qmx.rule' and injects them into the
 * the Finding-owned executor. Adapters consume its metadata contract.
 *
 * Rule instances always come from the container — a rule may declare
 * constructor dependencies beyond its Options object, so consumers must never
 * build rules themselves.
 */
final class RuleCompilerPass implements CompilerPassInterface, ConsumerBoundPassInterface
{
    public const string TAG = 'qmx.rule';

    /**
     * Services receiving the rule list, keyed by service id.
     *
     * Values are the constructor argument index. An index is used instead of a
     * named argument to avoid conflicts with the TYPE_BEFORE_REMOVING phase.
     *
     * Spelled as a literal, unlike the other passes that write into
     * RuleExecution: the architecture generator derives a composition binding
     * only from a `getDefinition()` whose argument names the class, and this
     * loop names it through a variable, so an imported `::class` here would be
     * an edge the manifest cannot declare.
     */
    private const array CONSUMERS = [
        'Qualimetrix\\Analysis\\Finding\\RuleExecution' => 0,
    ];

    public static function consumerServiceIds(): array
    {
        return array_keys(self::CONSUMERS);
    }

    public function process(ContainerBuilder $container): void
    {
        $rules = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $rules[] = new Reference($id);
        }

        foreach (self::CONSUMERS as $consumerId => $argumentIndex) {
            if (!$container->hasDefinition($consumerId)) {
                continue;
            }

            $container->getDefinition($consumerId)->setArgument($argumentIndex, $rules);
        }
    }
}
