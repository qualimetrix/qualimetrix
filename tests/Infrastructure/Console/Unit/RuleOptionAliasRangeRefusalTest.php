<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\CliOptionsParser;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
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
            self::assertSame(
                'Option --max-cycle-size (written as --max-cycle-size=-1) must be at least 0, got -1.',
                $refusal->getMessage(),
            );
            self::assertSame(ConfigurationSource::CommandLine, $refusal->sources()[0]->source());
            self::assertSame('--max-cycle-size', $refusal->sources()[0]->locator());
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
        CheckCommandDefinition::addOptions(new RuleOptionDocumentForms(), $command, new RuleRegistry([CircularDependencyRule::class]));

        $input = new ArrayInput(['--max-cycle-size' => $value], $command->getDefinition());
        $parser = new CliOptionsParser(new RuleOptionDocumentForms(), (new RuleOptionsParserFactory())->createFromClasses([CircularDependencyRule::class]));
        $writes = $parser->pathWrites($input);
        self::assertCount(1, $writes);
        self::assertSame(['rules', 'architecture.circular-dependency', 'max-cycle-size'], $writes[0]->path);
        $pipeline = new ConfigurationPipeline();
        $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
        $execution = $container->get(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class, $execution);
        foreach (['rules', 'only_rules', 'disabled_rules'] as $root) {
            $pipeline->addSection(new \Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection($execution, $root));
        }
        $pipeline->addStage(new CliStage());
        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: $writes));
        $registry = new RuleOptionsRegistry();

        return (new ResolvedOptionsFixture($registry, FindingConfiguration::fromDocument($document)))->create(CircularDependencyRule::NAME, CircularDependencyOptions::class);
    }
}
