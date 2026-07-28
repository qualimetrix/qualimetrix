<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Qualimetrix\Analysis\RuleExecution\RuleExecutor;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with 'qmx.rule' and injects them into the
 * consumers that need rule instances: {@see RuleExecutor} and
 * {@see RulesCommand}.
 *
 * Rule instances always come from the container — a rule may declare
 * constructor dependencies beyond its Options object, so consumers must never
 * build rules themselves.
 */
final class RuleCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'qmx.rule';

    /**
     * Services receiving the rule list, keyed by service id.
     *
     * Values are the constructor argument index. An index is used instead of a
     * named argument to avoid conflicts with the TYPE_BEFORE_REMOVING phase.
     */
    private const array CONSUMERS = [
        RuleExecutor::class => 0,
        RulesCommand::class => 0,
    ];

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
