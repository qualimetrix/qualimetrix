<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\DependencyInjection\Integration\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\CountInLoopRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\DebugCodeRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EmptyCatchRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EvalRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ExitRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\SuperglobalsRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Security\CommandInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\SecurityPatternOptions;
use Qualimetrix\Analysis\Evidence\Security\SqlInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\XssRule;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(RuleOptionsCompilerPass::class)]
final class SharedRuleOptionsContainerTest extends TestCase
{
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
        self::assertCount(55, $execution->allRules());
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
        self::assertTrue($execution->levelActivity()->toMap()['health.complexity']['project']);
        self::assertFalse($registry->resolvedOptions()->for('computed')->isEnabled());
        $second = $preflight->resolve(new ArrayInput([
            'paths' => [__DIR__], '--no-cache' => true, '--workers' => '1',
            '--rule-opt' => ['computed:enabled=false', 'health.complexity:enabled=false'],
        ], $command->getDefinition()), new NullOutput());
        self::assertNotSame($first->findingConfiguration?->resolvedOptions, $second->findingConfiguration?->resolvedOptions);
        self::assertNotSame($firstOptions, $registry->resolvedOptions()->for('health.complexity'));
        self::assertFalse($registry->resolvedOptions()->for('health.complexity')->isEnabled());
        self::assertFalse($execution->levelActivity()->toMap()['health.complexity']['project']);
        self::assertNotSame($firstAggregate, $container->get('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricProducerOptions'));
        self::assertNotSame($firstRule, $container->get('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricRule'));
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
