<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
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
    /**
     * An unknown group, and a known group in the wrong case, are refused
     * instead of answered with an
     * empty listing and exit 0. Checked against the real container, so the
     * groups the failure offers are the ones the listing actually prints.
     *
     * `RulesCommand` throws the carrier and has no ladder of its own; under
     * `CommandTester` that carrier
     * flies out of `execute()` rather than settling as a status code, so each
     * group gets its own try/catch — `expectException()` would only survive
     * the first iteration of the loop.
     */
    #[Test]
    public function itRefusesAGroupNoProducerHas(): void
    {
        $container = (new ContainerFactory())->create();

        $command = $container->get(RulesCommand::class);
        \assert($command instanceof RulesCommand);

        foreach (['nonexistent', 'Complexity'] as $group) {
            $tester = new CommandTester($command);

            try {
                $tester->execute(['--group' => $group]);
                self::fail(\sprintf('Expected a ConfigurationRefusal for group "%s".', $group));
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString(\sprintf('No rule group "%s"', $group), $refusal->summary(), $group);
                self::assertStringContainsString('complexity', $refusal->summary(), 'the failure names the groups that exist');
            }
        }
    }
}
