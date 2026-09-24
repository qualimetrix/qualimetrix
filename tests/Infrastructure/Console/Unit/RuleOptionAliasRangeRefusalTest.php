<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\CliOptionsParser;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The third door a rule option is written through: a rule's own short alias.
 *
 * The range refusal is covered for `rules:` and `--rule-opt`; an alias reaches
 * the same options through {@see CliOptionsParser}, so a regression that let
 * it bypass the range would not show there. `--max-cycle-size=-1` is the
 * spelling the documentation names.
 */
#[CoversClass(CliOptionsParser::class)]
final class RuleOptionAliasRangeRefusalTest extends TestCase
{
    #[Test]
    public function itRefusesANegativeValueWrittenThroughARuleAlias(): void
    {
        try {
            $this->fromAlias('-1');
            self::fail('a negative value written through --max-cycle-size was accepted');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString(
                'Option "maxCycleSize" of rule "architecture.circular-dependency" must be a non-negative whole number or null, got -1.',
                $refusal->getMessage(),
            );
        }
    }

    /** The neighbours that must keep working, down to the floor itself. */
    #[Test]
    public function itKeepsAcceptingAValueAtOrAboveTheFloorThroughTheSameAlias(): void
    {
        foreach (['5' => 5, '0' => 0] as $written => $expected) {
            $options = $this->fromAlias((string) $written);

            self::assertInstanceOf(CircularDependencyOptions::class, $options);
            self::assertSame($expected, $options->maxCycleSize);
        }
    }

    private function fromAlias(string $value): RuleOptionsInterface
    {
        $command = new Command('check');
        CheckCommandDefinition::addOptions($command, new RuleRegistry([CircularDependencyRule::class]));

        $input = new ArrayInput(['--max-cycle-size' => $value], $command->getDefinition());
        $parser = new CliOptionsParser((new RuleOptionsParserFactory())->createFromClasses([CircularDependencyRule::class]));

        $registry = new RuleOptionsRegistry();
        $registry->replace(new FindingConfiguration(
            new RuleOptionsDocument(),
            new FindingCliOverrides($parser->parseRuleOptions($input)),
            new RuleSelection(),
        ));

        return (new RuleOptionsFactory($registry))->create(CircularDependencyRule::NAME, CircularDependencyOptions::class);
    }
}
