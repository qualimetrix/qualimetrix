<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\DependencyInjection\Integration\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ControlFlow\EmptyCatchRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\CountInLoopRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\DebugCodeRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EvalRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ExitRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\SuperglobalsRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Security\DirectSinks\CommandInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\DirectSinks\SecurityPatternOptions;
use Qualimetrix\Analysis\Evidence\Security\DirectSinks\SqlInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\DirectSinks\XssRule;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\Console\RunSetup\AnalysisPreflight;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\RuleOptionsCompilerPass;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\TestSupport\WorkingDirectory\RunsFromRepositoryRoot;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(RuleOptionsCompilerPass::class)]
final class SharedRuleOptionsContainerTest extends TestCase
{
    use RunsFromRepositoryRoot;

    /**
     * @var array<class-string<RuleDefinitionInterface>, class-string<RuleOptionsInterface>>
     */
    private const array PRODUCERS = [
        CountInLoopRule::class => CodeSmellOptions::class,
        DebugCodeRule::class => CodeSmellOptions::class,
        EmptyCatchRule::class => CodeSmellOptions::class,
        EvalRule::class => CodeSmellOptions::class,
        ExitRule::class => CodeSmellOptions::class,
        GotoRule::class => CodeSmellOptions::class,
        SuperglobalsRule::class => CodeSmellOptions::class,
        CommandInjectionRule::class => SecurityPatternOptions::class,
        SqlInjectionRule::class => SecurityPatternOptions::class,
        XssRule::class => SecurityPatternOptions::class,
    ];

    #[Test]
    public function itCreatesIndependentlyConfiguredOptionsForEverySharedOptionsProducer(): void
    {
        $container = $this->createContainer();
        $registry = $container->get(RuleOptionsRegistry::class);
        self::assertInstanceOf(RuleOptionsRegistry::class, $registry);

        $fileValues = ['rules' => [
            EvalRule::NAME => ['enabled' => false, 'suppress_paths' => [['subtree' => 'src/Eval']]],
            GotoRule::NAME => ['suppress_namespaces' => [['subtree' => 'App\\Legacy']]],
            SqlInjectionRule::NAME => ['enabled' => false],
        ]];
        $execution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);
        $configuration = \Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::authoredConfiguration($fileValues, $execution->allRules(), cliOptions: [XssRule::NAME => ['enabled' => false]]);
        $builder = $container->get(RuleOptionsBuild::class);
        self::assertInstanceOf(RuleOptionsBuild::class, $builder);
        $catalog = $container->get(ChannelUniverse::class);
        self::assertInstanceOf(ChannelUniverse::class, $catalog);
        $channels = $catalog->snapshot(new ResolvedComputedMetricDefinitions([]));
        $resolver = new RuleEnablementResolver();
        $stated = $resolver->decide($configuration->document, $channels);
        $options = $builder->build($configuration, $stated);
        $registry->replace($configuration->withChannelUniverse($channels)->withResolvedOptions($options)
            ->withEnablement($resolver->conclude($stated, $options)));

        $optionsByProducer = [];
        foreach (self::PRODUCERS as $ruleClass => $optionsClass) {
            $rule = $container->get($ruleClass);
            self::assertInstanceOf($ruleClass, $rule);

            $options = self::optionsOf($rule);
            self::assertInstanceOf($optionsClass, $options);
            $optionsByProducer[$ruleClass::NAME] = $options;
            self::assertSame($registry->resolvedOptions()->for($ruleClass::NAME), $options);
        }

        self::assertCount(10, array_unique(array_map(spl_object_id(...), $optionsByProducer)));

        self::assertTrue($optionsByProducer[CountInLoopRule::NAME]->isEnabled());
        self::assertFalse($optionsByProducer[EvalRule::NAME]->isEnabled());
        self::assertTrue($optionsByProducer[CommandInjectionRule::NAME]->isEnabled());
        self::assertFalse($optionsByProducer[SqlInjectionRule::NAME]->isEnabled());
        self::assertFalse($optionsByProducer[XssRule::NAME]->isEnabled());

        self::assertTrue($registry->isPathExcluded(EvalRule::NAME, RelativePath::fromString('src/Eval/File.php')));
        self::assertFalse($registry->isPathExcluded(CountInLoopRule::NAME, RelativePath::fromString('src/Eval/File.php')));

        self::assertTrue($registry->isNamespaceExcluded(GotoRule::NAME, 'App\\Legacy\\Service'));
        self::assertFalse($registry->isNamespaceExcluded(DebugCodeRule::NAME, 'App\\Legacy\\Service'));
    }

    #[Test]
    public function itUsesTheCurrentHealthOptionsAndActivityOnTwoPreflightsInOneCompiledContainer(): void
    {
        $container = $this->createContainer();
        $registry = $container->get(RuleOptionsRegistry::class);
        self::assertInstanceOf(RuleOptionsRegistry::class, $registry);
        $execution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);
        self::assertCount(56, $execution->allRules());
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $preflight = $container->get(AnalysisPreflight::class);
        self::assertInstanceOf(AnalysisPreflight::class, $preflight);
        $first = $preflight->resolve(new ArrayInput([
            'paths' => [__DIR__], '--no-cache' => true, '--workers' => '1',
            '--rule-opt' => ['computed:enabled=false', 'health.complexity:enabled=true'],
        ], $command->getDefinition()), new NullOutput());
        $firstOptions = $registry->resolvedOptions()->for('health.complexity');
        $firstAggregate = $container->get('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricProducerOptions');
        $firstRule = $container->get('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricRule');
        self::assertTrue($firstOptions->isEnabled());
        $publication = $container->get(\Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface::class, $publication);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\RuleExecution::class, $execution);
        self::assertSame($publication, (new ReflectionProperty(\Qualimetrix\Analysis\Finding\RuleExecution::class, 'publication'))->getValue($execution));
        self::assertSame(
            (new ReflectionProperty(\Qualimetrix\Analysis\Finding\RuleExecution::class, 'materialization'))->getValue($execution),
            (new ReflectionProperty(\Qualimetrix\Analysis\Finding\FindingPublication::class, 'materialization'))->getValue($publication),
        );
        self::assertProducerActivityAgrees($execution, $publication);
        self::assertTrue($publication->levelActivity()->toMap()['health.complexity']['project']);
        self::assertFalse($registry->resolvedOptions()->for('computed')->isEnabled());
        $second = $preflight->resolve(new ArrayInput([
            'paths' => [__DIR__], '--no-cache' => true, '--workers' => '1',
            '--rule-opt' => ['computed:enabled=false', 'health.complexity:enabled=false'],
        ], $command->getDefinition()), new NullOutput());
        self::assertNotSame($first->findingConfiguration?->resolvedOptions, $second->findingConfiguration?->resolvedOptions);
        self::assertNotSame($firstOptions, $registry->resolvedOptions()->for('health.complexity'));
        self::assertFalse($registry->resolvedOptions()->for('health.complexity')->isEnabled());
        self::assertProducerActivityAgrees($execution, $publication);
        self::assertFalse($publication->levelActivity()->toMap()['health.complexity']['project']);
        self::assertNotSame($firstAggregate, $container->get('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricProducerOptions'));
        self::assertNotSame($firstRule, $container->get('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricRule'));
    }

    private static function assertProducerActivityAgrees(
        RuleExecutionInterface $execution,
        \Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface $publication,
    ): void {
        $rules = $execution->allRules();
        self::assertCount(56, $rules);
        $activity = $publication->levelActivity()->toMap();
        foreach ($rules as $rule) {
            if (!isset($activity[$rule->name])) {
                self::assertFalse($rule->active, $rule->name);
                continue;
            }
            self::assertSame($rule->active, \in_array(true, $activity[$rule->name], true), $rule->name);
        }
    }

    private function createContainer(): ContainerBuilder
    {
        $container = (new ContainerFactory())->configure();
        $container->getDefinition(RuleOptionsRegistry::class)->setPublic(true);
        $container->getDefinition(AnalysisPreflight::class)->setPublic(true);
        foreach (array_keys(self::PRODUCERS) as $ruleClass) {
            $container->getDefinition($ruleClass)->setPublic(true);
        }
        $container->getDefinition('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricRule')->setPublic(true);
        $container->getDefinition('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricProducerOptions')->setPublic(true);
        $container->compile();

        return $container;
    }

    private static function optionsOf(RuleInterface $rule): RuleOptionsInterface
    {
        $property = new ReflectionProperty(AbstractRule::class, 'options');
        $options = $property->getValue($rule);
        self::assertInstanceOf(RuleOptionsInterface::class, $options);

        return $options;
    }
}
