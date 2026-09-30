<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Infrastructure\Console\AnalysisPreflightProfile;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(ConfigurationInputAdapter::class)]
final class ConfigurationInputAdapterTest extends TestCase
{
    #[Test]
    public function itContributesBoundRuleOptionsThroughTheRealDocumentPipeline(): void
    {
        $input = new ArrayInput([
            '--cyclomatic-warning' => '10',
            '--rule-opt' => ['complexity.ccn:class.max-error=20', 'complexity.ccn:class.max-warning=15'],
        ], $this->definition());

        $document = $this->adapter()->resolve($input);
        self::assertSame([
            'complexity.ccn' => ['callable' => ['warning' => 10], 'class' => ['max-error' => 20, 'max-warning' => 15]],
        ], $document->ruleContributions()[0]);
    }

    #[Test]
    public function itRefusesAliasAndRuleOptionOverlapWithBothWritersNamed(): void
    {
        $input = new ArrayInput([
            '--cyclomatic-warning' => '10',
            '--rule-opt' => ['complexity.ccn:callable.warning=20'],
        ], $this->definition());

        try {
            $this->adapter()->resolve($input);
            self::fail('The two CLI writes should conflict.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('--cyclomatic-warning', $refusal->getMessage());
            self::assertStringContainsString('--rule-opt', $refusal->getMessage());
        }
    }

    #[Test]
    public function itAcceptsExplicitAuthoredRecordsForRepeatedEmbedderAliases(): void
    {
        $input = new ArrayInput([], $this->definition());

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('--cyclomatic-warning');
        $this->adapter()->resolve($input, authoredRuleRecords: [
            ['optionName' => '--cyclomatic-warning', 'text' => '10', 'ordinal' => 0],
            ['optionName' => '--cyclomatic-warning', 'text' => '20', 'ordinal' => 1],
        ]);
    }

    #[Test]
    public function itLeavesTheGraphProfileWithoutARulesContributionWhenNoRuleFlagIsPresent(): void
    {
        $document = $this->adapter()->resolve(new ArrayInput([], $this->definition()), AnalysisPreflightProfile::graph());

        self::assertSame([], $document->ruleContributions());
    }

    private function adapter(): ConfigurationInputAdapter
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new CliStage());

        $execution = self::createStub(RuleExecutionInterface::class);
        $execution->method('allRules')->willReturn([
            new RuleMetadata(ComplexityRule::NAME, ComplexityRule::getOptionsClass(), '', CliAliasReader::read(ComplexityRule::class), false),
        ]);

        return new ConfigurationInputAdapter($pipeline, new ErrorStream(), $execution);
    }

    private function definition(): InputDefinition
    {
        return new InputDefinition([
            new InputOption('cyclomatic-warning', null, InputOption::VALUE_REQUIRED),
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);
    }
}
