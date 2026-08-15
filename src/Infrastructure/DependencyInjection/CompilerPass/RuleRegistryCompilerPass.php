<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
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

        /** @var list<class-string<RuleDefinitionInterface>> $ruleClasses */
        $this->validateNoDuplicateNames($ruleClasses);

        $definition->setArgument('$ruleClasses', $ruleClasses);

        if ($container->hasDefinition(KnownRuleNamesAdapter::class)) {
            $container->getDefinition(KnownRuleNamesAdapter::class)
                ->setArgument('$ruleClasses', $ruleClasses);
        }
    }

    /**
     * Validates that every registered rule declares a unique string NAME.
     *
     * The NAME constant is mandatory: rule metadata is read by reflection
     * (see {@see RuleNameReader}) because rules may declare constructor
     * dependencies that only the container can resolve. A missing NAME is
     * therefore a wiring error and fails the container build.
     *
     * @param list<class-string> $ruleClasses
     */
    private function validateNoDuplicateNames(array $ruleClasses): void
    {
        /** @var array<string, class-string> $nameToClass */
        $nameToClass = [];

        foreach ($ruleClasses as $class) {
            $name = RuleNameReader::read($class);

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
