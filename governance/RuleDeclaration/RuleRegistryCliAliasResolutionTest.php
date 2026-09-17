<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleDeclaration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionClass;
use ReflectionNamedType;

final class RuleRegistryCliAliasResolutionTest extends TestCase
{
    /**
     * Regression guard for a silent alias-target rot: an alias whose target key
     * no longer names a real option of its rule's Options class still resolves
     * cleanly through reflection and only surfaces later as a no-op CLI flag
     * (or an "Unknown option" warning). Every `#[CliAlias]` target must resolve,
     * segment by segment, to a constructor parameter of the rule's Options class
     * (descending into nested level options for hierarchical rules).
     */
    #[Test]
    public function itResolvesEveryCliAliasToARealRuleOption(): void
    {
        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        $broken = [];

        foreach ($registry->getClasses() as $ruleClass) {
            $optionsClass = $ruleClass::getOptionsClass();

            foreach (CliAliasReader::read($ruleClass) as $alias => $optionName) {
                if (!$this->optionResolves($optionsClass, $optionName)) {
                    $broken[] = \sprintf('%s: --%s -> %s', $ruleClass, $alias, $optionName);
                }
            }
        }

        self::assertSame(
            [],
            $broken,
            "CLI alias target(s) that do not resolve to a real rule option:\n" . implode("\n", $broken),
        );
    }

    /**
     * @param class-string $optionsClass
     */
    private function optionResolves(string $optionsClass, string $optionName): bool
    {
        $currentClass = $optionsClass;
        $segments = explode('.', $optionName);

        foreach ($segments as $index => $segment) {
            $normalized = lcfirst(str_replace(['-', '_'], '', ucwords($segment, '-_')));
            $reflection = new ReflectionClass($currentClass);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return false;
            }

            $parameter = null;
            foreach ($constructor->getParameters() as $candidate) {
                if ($candidate->getName() === $normalized) {
                    $parameter = $candidate;
                    break;
                }
            }

            if ($parameter === null) {
                return false;
            }

            if ($index < \count($segments) - 1) {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    return false;
                }

                $currentClass = $type->getName();
                if (!class_exists($currentClass)) {
                    return false;
                }
            }
        }

        return true;
    }
}
