<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Evidence\Complexity\Cyclomatic\ComplexityRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\Diagnostics\AnalysisPreflightProfile;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(ConfigurationInputAdapter::class)]
#[CoversClass(\Qualimetrix\Infrastructure\Console\Invocation\Configuration\WorkerCountParser::class)]
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
        ], $document->resolved()->get('rules')?->plain());
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

        self::assertSame([], $document->resolved()->roots());
    }

    #[Test]
    #[DataProvider('invalidWorkerSpellings')]
    public function itRefusesInvalidWorkersBeforeBuildingAGraphRequest(mixed $workers): void
    {
        $input = new ArrayInput(['--workers' => $workers], new InputDefinition([new InputOption('workers', null, InputOption::VALUE_REQUIRED)]));
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('--workers');
        $this->adapter()->adapt($input, '/project', AnalysisPreflightProfile::graph());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidWorkerSpellings(): iterable
    {
        yield 'text' => ['abc'];
        yield 'fraction' => ['1.5'];
        yield 'overflow' => [(string) \PHP_INT_MAX . '0'];
        yield 'boolean' => [true];
        yield 'array' => [[]];
    }

    #[Test]
    public function itPreservesAbsentZeroAndPositiveGraphWorkers(): void
    {
        $definition = new InputDefinition([new InputOption('workers', null, InputOption::VALUE_REQUIRED)]);
        foreach ([null, '0', '2'] as $value) {
            $input = new ArrayInput($value === null ? [] : ['--workers' => $value], $definition);
            $request = $this->adapter()->adapt($input, '/project', AnalysisPreflightProfile::graph());
            self::assertSame($value === null ? null : (int) $value, $request->cliValues['parallel.workers'] ?? null);
        }
    }

    private function adapter(): ConfigurationInputAdapter
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new CliStage());

        $execution = self::createStub(RuleExecutionInterface::class);
        $execution->method('allRules')->willReturn([
            new RuleMetadata(ComplexityRule::NAME, ComplexityRule::getOptionsClass(), '', CliAliasReader::read(ComplexityRule::class), false),
        ]);

        foreach (['rules', 'only_rules', 'disabled_rules'] as $root) {
            $pipeline->addSection(new \Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection($execution, $root));
        }

        return new ConfigurationInputAdapter(new RuleOptionDocumentForms(), $pipeline, new ErrorStream(), $execution);
    }

    private function definition(): InputDefinition
    {
        return new InputDefinition([
            new InputOption('cyclomatic-warning', null, InputOption::VALUE_REQUIRED),
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);
    }
}
