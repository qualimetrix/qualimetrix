<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleDeclaration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\ConfigurationValidatorRegistry;
use ReflectionClass;

/**
 * Every registered rule and configuration validator is stateless in itself:
 * no property an instance can reassign, no static property at all.
 *
 * {@see RuleInterface::analyze()} states why: one instance is shared by the
 * whole process and asked 1 + N times per run — once for the run, once per
 * authored threshold-override group inside the directive audit — and the
 * only thing that would otherwise notice a remembered value is that audit's
 * control pass, which invalidates every verdict instead of naming the class.
 *
 * What this cannot see, by construction: a `static` variable inside a
 * function body, and a write through an injected collaborator. The contract
 * docblock says which collaborator writes are allowed.
 */
final class RuleInstanceStatelessnessTest extends TestCase
{
    /** {@see RegisteredRules::COUNT} rule classes plus the two configuration validators. */
    private const int POPULATION = RegisteredRules::COUNT + 2;

    #[Test]
    public function itFindsNoStateAnInstanceCouldCarryBetweenCalls(): void
    {
        $population = [...RegisteredRules::classes(), ...self::validatorClasses()];
        $violations = [];

        foreach ($population as $class) {
            $violations = [...$violations, ...self::stateOf($class)];
        }

        self::assertCount(self::POPULATION, array_unique($population), 'the swept population moved; re-derive it before trusting an empty answer');
        self::assertSame([], $violations, "A rule or validator carries state between calls:\n" . implode("\n", $violations));
    }

    /**
     * The detector held against a planted stateful class, so that an empty
     * answer above cannot mean it stopped recognising anything.
     */
    #[Test]
    public function itRecognisesAPropertyAnInstanceCanReassignAndAStaticOne(): void
    {
        $planted = new class {
            public static int $shared = 0;

            private int $calls = 0;

            public function __construct(private readonly int $limit = 1) {}

            public function tick(): int
            {
                return ++$this->calls + $this->limit + self::$shared;
            }
        };

        $violations = self::stateOf($planted::class);

        self::assertCount(2, $violations);
        self::assertStringContainsString('$calls', $violations[0] . $violations[1]);
        self::assertStringContainsString('static $shared', $violations[0] . $violations[1]);
    }

    /**
     * Every property the class and its ancestors and traits declare, each
     * named once by the class that declares it.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function stateOf(string $class): array
    {
        $violations = [];
        $reflection = new ReflectionClass($class);

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }

                if ($property->isStatic()) {
                    $violations[] = \sprintf('%s: static $%s', $current->getName(), $property->getName());

                    continue;
                }

                if (!$property->isReadOnly()) {
                    $violations[] = \sprintf('%s: $%s is not readonly', $current->getName(), $property->getName());
                }
            }
        }

        return $violations;
    }

    /** @return list<class-string<ConfigurationValidatorInterface>> */
    private static function validatorClasses(): array
    {
        $registry = (new ContainerFactory())->create()->get(ConfigurationValidatorRegistry::class);
        \assert($registry instanceof ConfigurationValidatorRegistry);

        return $registry->getClasses();
    }
}
