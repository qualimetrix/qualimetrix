<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects rule classes from tagged services and injects them into RuleRegistry.
 *
 * This allows RuleRegistry to work with class names instead of instances,
 * enabling metadata extraction via reflection without instantiation.
 */
final class RuleRegistryCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'qmx.rule';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RuleRegistry::class)) {
            return;
        }

        $definition = $container->getDefinition(RuleRegistry::class);
        $ruleClasses = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $serviceDefinition = $container->getDefinition($id);
            $class = $serviceDefinition->getClass();

            if ($class !== null) {
                $ruleClasses[] = $class;
            }
        }

        /** @var list<class-string> $ruleClasses */
        $this->validateNoDuplicateNames($ruleClasses);

        $definition->setArgument('$ruleClasses', $ruleClasses);

        if ($container->hasDefinition(KnownRuleNamesAdapter::class)) {
            $container->getDefinition(KnownRuleNamesAdapter::class)
                ->setArgument('$ruleClasses', $ruleClasses);
        }
    }

    /**
     * @param list<class-string> $ruleClasses
     */
    private function validateNoDuplicateNames(array $ruleClasses): void
    {
        /** @var array<string, class-string> $nameToClass */
        $nameToClass = [];

        foreach ($ruleClasses as $class) {
            $reflection = new ReflectionClass($class);

            if (!$reflection->hasConstant('NAME')) {
                continue;
            }

            $name = $reflection->getConstant('NAME');

            if (!\is_string($name)) {
                continue;
            }

            if (isset($nameToClass[$name])) {
                throw new LogicException(\sprintf(
                    'Duplicate rule NAME "%s" found in classes %s and %s. Each rule must have a unique NAME constant.',
                    $name,
                    $nameToClass[$name],
                    $class,
                ));
            }

            $nameToClass[$name] = $class;
        }
    }
}
