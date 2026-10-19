<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog;

use LogicException;

use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConsumerBoundPassInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with 'qmx.rule' and injects them into the
 * Finding's rule materialization service. Adapters consume its metadata contract.
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
     * RuleMaterialization: the architecture generator derives a composition binding
     * only from a `getDefinition()` whose argument names the class, and this
     * loop names it through a variable, so an imported `::class` here would be
     * an edge the manifest cannot declare.
     */
    private const array CONSUMERS = [
        'Qualimetrix\\Analysis\\Finding\\RuleMaterialization' => 0,
    ];

    public static function consumerServiceIds(): array
    {
        return array_keys(self::CONSUMERS);
    }

    public function process(ContainerBuilder $container): void
    {
        $rules = $this->collectRules($container);

        foreach (self::CONSUMERS as $consumerId => $argumentIndex) {
            if (!$container->hasDefinition($consumerId)) {
                continue;
            }

            $container->getDefinition($consumerId)->setArgument($argumentIndex, $rules);
        }
    }

    /** @return list<array{metadata: \Symfony\Component\DependencyInjection\Definition, create: ServiceClosureArgument}> */
    private function collectRules(ContainerBuilder $container): array
    {
        $rules = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $_tags) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();
            if ($class === null || !class_exists($class)) {
                throw new LogicException(\sprintf('Rule service "%s" has no class.', $id));
            }
            $definition->setShared(false);
            $rules[] = [
                'metadata' => new \Symfony\Component\DependencyInjection\Definition(RuleMetadata::class, [
                    RuleNameReader::read($class),
                    $class::getOptionsClass(),
                    $class::getDescription(),
                    CliAliasReader::read($class),
                    false,
                ]),
                'create' => new ServiceClosureArgument(new Reference($id)),
            ];
        }

        usort($rules, static fn(array $left, array $right): int => strcmp(
            (string) $left['metadata']->getArgument(0),
            (string) $right['metadata']->getArgument(0),
        ));

        return $rules;
    }
}
