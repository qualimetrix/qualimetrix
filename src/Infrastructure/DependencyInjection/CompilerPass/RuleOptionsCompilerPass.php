<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Automatically registers Options classes for Rules.
 *
 * For each tagged Rule, this pass:
 * 1. Calls Rule::getOptionsClass() to get the Options class
 * 2. Registers producer-specific Options with RuleOptionsFactory::create() as factory
 * 3. Binds the Options to the Rule via setArgument('$options', ...)
 *
 * This allows Rules to be auto-registered via registerClasses() without
 * manual Options registration in ContainerFactory.
 *
 * Must run BEFORE RuleCompilerPass so Options are available when Rules are collected.
 */
final class RuleOptionsCompilerPass implements CompilerPassInterface
{
    private const string RULE_INTERFACE = 'Qualimetrix\\Analysis\\Finding\\Rule\\RuleInterface';

    public function process(ContainerBuilder $container): void
    {
        // RuleOptionsFactory is synthetic, so use has() instead of hasDefinition()
        if (!$container->has(RuleOptionsFactory::class)) {
            return;
        }

        foreach ($container->findTaggedServiceIds(RuleCompilerPass::TAG) as $ruleId => $tags) {
            $ruleDefinition = $container->getDefinition($ruleId);
            $ruleClass = $ruleDefinition->getClass();

            if ($ruleClass === null) {
                continue;
            }

            // Ensure rule class implements RuleInterface and has getOptionsClass
            if (!is_a($ruleClass, self::RULE_INTERFACE, true)) {
                continue;
            }

            $reflection = new ReflectionClass($ruleClass);
            $optionsClass = $reflection->getMethod('getOptionsClass')->invoke(null);
            if (!\is_string($optionsClass) || !is_a($optionsClass, RuleOptionsInterface::class, true)) {
                continue;
            }

            $ruleName = RuleNameReader::read($ruleClass);

            // Options configuration is keyed by producer rule name. The service identity
            // must therefore include both the producer and the Options class: multiple
            // rules may intentionally reuse the same immutable Options implementation
            // while still requiring independently configured instances.
            $optionsServiceId = self::optionsServiceId($ruleName, $optionsClass);

            if (!$container->hasDefinition($optionsServiceId)) {
                $container->register($optionsServiceId, $optionsClass)
                    ->setFactory([new Reference(RuleOptionsFactory::class), 'create'])
                    ->setArguments([$ruleName, $optionsClass]);
                // Note: Options are NOT lazy - they're simple value objects
            }

            // Bind Options to Rule
            $ruleDefinition->setArgument('$options', new Reference($optionsServiceId));

            // Resolve additional constructor dependencies (rules have autowiring disabled,
            // so we must manually bind typed parameters beyond $options)
            $this->resolveExtraDependencies($container, $ruleDefinition, $ruleClass);
        }
    }

    /**
     * The id of the Options service configured for one producer rule.
     *
     * Public because a configuration validator answers to its producer's
     * options, and its registration therefore has to reference the very same
     * service rather than build an equal copy — two copies would be two places
     * for the configuration to be read differently.
     *
     * The Options class arrives as a plain string, not a `class-string`: the
     * capability configurators name their internals by literal so they do not
     * import them, which is the same convention that keeps the rule and the
     * policy out of this container's `use` list.
     */
    public static function optionsServiceId(string $ruleName, string $optionsClass): string
    {
        return $ruleName . '.options.' . $optionsClass;
    }

    /**
     * The same id, derived from the producer rule class the way this pass
     * derives it when it registers the service.
     *
     * A configurator wiring a validator to its producer's Options needs the id
     * before the pass has run. Spelling the Options class out there would be a
     * second statement of a fact whose authority is `getOptionsClass()`: rename
     * the Options class and the configurator would point at a service nobody
     * registers. Asking the rule keeps one authority.
     *
     * The rule class arrives as a plain string for the same reason the Options
     * class does above — capability configurators name their internals by
     * literal rather than importing them.
     */
    public static function optionsServiceIdForRule(string $ruleClass): string
    {
        if (!is_a($ruleClass, self::RULE_INTERFACE, true)) {
            throw new LogicException(\sprintf(
                'Cannot derive an Options service id from %s: it does not implement %s.',
                $ruleClass,
                self::RULE_INTERFACE,
            ));
        }

        $optionsClass = (new ReflectionClass($ruleClass))->getMethod('getOptionsClass')->invoke(null);

        if (!\is_string($optionsClass) || !is_a($optionsClass, RuleOptionsInterface::class, true)) {
            throw new LogicException(\sprintf(
                '%s::getOptionsClass() did not name a %s.',
                $ruleClass,
                RuleOptionsInterface::class,
            ));
        }

        return self::optionsServiceId(RuleNameReader::read($ruleClass), $optionsClass);
    }

    /**
     * Maps a type class to a concrete service ID in the container.
     *
     * Handles PSR interfaces that are registered via registerAliasForArgument()
     * (parametric aliases) rather than plain setAlias(), which makes them
     * invisible to $container->has().
     */
    private function resolveServiceId(string $typeClass, ContainerBuilder $container): ?string
    {
        if ($container->has($typeClass)) {
            return $typeClass;
        }

        // Map well-known PSR interfaces to concrete implementations
        if ($typeClass === LoggerInterface::class && $container->has(DelegatingLogger::class)) {
            return DelegatingLogger::class;
        }

        return null;
    }

    /**
     * Resolves additional typed constructor parameters for rules.
     *
     * Since rules have autowiring disabled (due to RuleOptionsInterface injection),
     * any extra constructor dependencies must be explicitly bound.
     *
     * @param class-string $ruleClass
     */
    private function resolveExtraDependencies(
        ContainerBuilder $container,
        \Symfony\Component\DependencyInjection\Definition $ruleDefinition,
        string $ruleClass,
    ): void {
        $reflection = new ReflectionClass($ruleClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $param) {
            $paramName = '$' . $param->getName();

            // Skip $options — already bound above
            if ($paramName === '$options') {
                continue;
            }

            // Skip parameters already explicitly set
            if (\array_key_exists($paramName, $ruleDefinition->getArguments())) {
                continue;
            }

            $type = $param->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $typeClass = $type->getName();

            // Skip RuleOptionsInterface — handled above
            if (is_a($typeClass, RuleOptionsInterface::class, true)) {
                continue;
            }

            // Map PSR interfaces to concrete implementations
            $serviceId = $this->resolveServiceId($typeClass, $container);

            // If the container has this service, bind it
            if ($serviceId !== null) {
                $ruleDefinition->setArgument($paramName, new Reference($serviceId));
            } elseif ($type->allowsNull() || $param->isDefaultValueAvailable()) {
                // Nullable or has default — skip (will use null/default)
            }
        }
    }
}
