<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;

use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression guard for the `rules` command against the production container.
 *
 * The unit test builds the command from stub rules, so it cannot see wiring
 * faults. This test runs the real command over the real rule set: it failed
 * with a fatal ArgumentCountError while the command built rules itself
 * (LayerViolationRule takes the capability-owned ArchitecturePolicy on top of
 * its Options object, which only the container can supply).
 */
#[CoversClass(RulesCommand::class)]
#[CoversClass(RuleCompilerPass::class)]
final class RulesCommandWiringTest extends TestCase
{
    #[Test]
    public function itListsEveryRegisteredRule(): void
    {
        $container = (new ContainerFactory())->create();

        $command = $container->get(RulesCommand::class);
        \assert($command instanceof RulesCommand);

        $registry = $container->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        $ruleClasses = $registry->getClasses();
        self::assertNotSame([], $ruleClasses, 'The production container must register rules.');

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();

        // The listing is producer-oriented, and a producer is not a class: the
        // computed-metric family runs in one class and publishes under seven
        // names. Counting classes here would have quietly stopped covering six
        // of them.
        $execution = $container->get(RuleExecutionInterface::class);
        \assert($execution instanceof RuleExecutionInterface);
        $producerNames = array_map(static fn(RuleMetadata $rule): string => $rule->name, $execution->allRules());

        self::assertSame(
            \count($ruleClasses) + \count(ComputedMetricChannelFamily::HEALTH_PRODUCER_RULE_NAMES),
            \count($producerNames),
        );
        self::assertStringContainsString(\sprintf('%d rules available', \count($producerNames)), $display);

        foreach ($producerNames as $producerName) {
            self::assertStringContainsString($producerName, $display);
        }
    }

    #[Test]
    public function itFiltersByGroupAgainstTheRealRuleSet(): void
    {
        $container = (new ContainerFactory())->create();

        $command = $container->get(RulesCommand::class);
        \assert($command instanceof RulesCommand);

        $tester = new CommandTester($command);
        $tester->execute(['--group' => 'architecture']);

        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('architecture.layer-violation', $display);
        self::assertStringNotContainsString('complexity.cyclomatic', $display);
    }
}
