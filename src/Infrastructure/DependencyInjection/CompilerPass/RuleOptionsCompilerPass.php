<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\CompilerPass;

use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Infrastructure\Logging\DelegatingLogger;
use Psr\Log\LoggerInterface;
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
 * 2. Registers the Options class with RuleOptionsFactory::create() as factory
 * 3. Binds the Options to the Rule via setArgument('$options', ...)
 *
 * This allows Rules to be auto-registered via registerClasses() without
 * manual Options registration in ContainerFactory.
 *
 * Must run BEFORE RuleCompilerPass so Options are available when Rules are collected.
 */
final class RuleOptionsCompilerPass implements CompilerPassInterface
{
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
            if (!is_a($ruleClass, RuleInterface::class, true)) {
                continue;
            }

            // Get Options class from rule
            /** @var class-string<RuleInterface> $ruleClass */
            $optionsClass = $ruleClass::getOptionsClass();

            // Get rule NAME constant for factory
            $ruleName = $ruleClass::NAME;

            // Register Options service if not already registered
            if (!$container->hasDefinition($optionsClass)) {
                $container->register($optionsClass)
                    ->setFactory([new Reference(RuleOptionsFactory::class), 'create'])
                    ->setArguments([$ruleName, $optionsClass]);
                // Note: Options are NOT lazy - they're simple value objects
            }

            // Bind Options to Rule
            $ruleDefinition->setArgument('$options', new Reference($optionsClass));

            // Resolve additional constructor dependencies (rules have autowiring disabled,
            // so we must manually bind typed parameters beyond $options)
            $this->resolveExtraDependencies($container, $ruleDefinition, $ruleClass);
        }
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
     * @param class-string<RuleInterface> $ruleClass
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
