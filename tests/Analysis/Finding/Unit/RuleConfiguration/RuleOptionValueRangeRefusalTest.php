<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\RuleConfiguration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityOptions;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityRule;
use Qualimetrix\Analysis\Evidence\Size\MethodCountOptions;
use Qualimetrix\Analysis\Evidence\Size\MethodCountRule;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionKeyRecognition;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\CliOptionsParser;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\WrittenFile;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * A rule option's value is refused for being out of its range, not only for
 * being of the wrong form, and from both doors a user writes one through.
 *
 * Every numeric option in `rules:` is a count or a boundary on a measurement
 * that is never negative. A negative boundary does not tighten a rule, it
 * inverts it: `value >= -1` holds for every symbol, and `value < -1` holds for
 * none. Accepting one was a silent answer to a configuration mistake.
 *
 * Each refusal sits beside the neighbouring value that must keep working, and
 * the working half asserts the value arrived rather than that nothing threw.
 */
#[CoversClass(RuleOptionsBuild::class)]
#[CoversClass(RuleOptionKeyRecognition::class)]
final class RuleOptionValueRangeRefusalTest extends TestCase
{
    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    #[Test]
    #[DataProvider('provideNegativeValuesWrittenInTheConfigurationFile')]
    public function itRefusesANegativeValueWrittenInTheConfigurationFile(
        string $rulesBlock,
        string $ruleName,
        string $optionsClass,
        string $expectedSentence,
    ): void {
        $refusal = $this->capture(fn() => $this->fromConfigurationFile($rulesBlock, $ruleName, $optionsClass));

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal, 'a negative value was accepted');
        self::assertStringContainsString($expectedSentence, $refusal->getMessage());
    }

    /**
     * @return iterable<string, array{string, string, class-string<RuleOptionsInterface>, string}>
     */
    public static function provideNegativeValuesWrittenInTheConfigurationFile(): iterable
    {
        yield 'a graduated boundary at the rule depth' => [
            "  size.method-count:\n    warning: -1\n",
            'size.method-count',
            MethodCountOptions::class,
            '"rules.size.method-count.warning" in configuration file "/project/qmx.yaml" must be at least 0, got -1.',
        ];

        // The shorthand is unfolded into `warning`/`error` before the
        // recognition walk; a value outside the range must stop that, or the
        // refusal would name a key the author never wrote.
        yield 'the threshold shorthand, named as written' => [
            "  size.method-count:\n    threshold: -1\n",
            'size.method-count',
            MethodCountOptions::class,
            '"rules.size.method-count.threshold" in configuration file "/project/qmx.yaml" must be at least 0, got -1.',
        ];

        yield 'a boundary inside a level slot' => [
            "  complexity.ccn:\n    callable:\n      warning: -5\n",
            'complexity.ccn',
            ComplexityOptions::class,
            '"rules.complexity.ccn.callable.warning" in configuration file "/project/qmx.yaml" must be at least 0, got -5.',
        ];

        yield 'a fractional boundary' => [
            "  coupling.instability:\n    class:\n      max_warning: -0.5\n",
            'coupling.instability',
            InstabilityOptions::class,
            '"rules.coupling.instability.class.maxWarning" in configuration file "/project/qmx.yaml" must be at least 0, got -0.5.',
        ];

        yield 'a minimum count' => [
            "  cohesion.lcom:\n    min_methods: -3\n",
            'cohesion.lcom',
            LcomOptions::class,
            '"rules.cohesion.lcom.minMethods" in configuration file "/project/qmx.yaml" must be at least 0, got -3.',
        ];
    }

    /**
     * @param class-string<RuleDefinitionInterface> $ruleClass
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param list<string> $expectedPath
     */
    #[Test]
    #[DataProvider('provideNegativeValuesWrittenOnTheCommandLine')]
    public function itRefusesANegativeValueWrittenOnTheCommandLine(
        string $ruleOpt,
        string $ruleClass,
        string $ruleName,
        string $optionsClass,
        string $expectedSentence,
        array $expectedPath,
    ): void {
        $refusal = $this->capture(fn() => $this->fromCommandLine($ruleOpt, $ruleClass, $ruleName, $optionsClass, $expectedPath));

        self::assertInstanceOf(ConfigurationRefusal::class, $refusal, 'a negative value was accepted');
        self::assertSame($expectedSentence, $refusal->getMessage());
        self::assertSame(ConfigurationSource::CommandLine, $refusal->sources()[0]->source());
        self::assertSame('--rule-opt', $refusal->sources()[0]->locator());
    }

    /**
     * @return iterable<string, array{string, class-string<RuleDefinitionInterface>, string, class-string<RuleOptionsInterface>, string, list<string>}>
     */
    public static function provideNegativeValuesWrittenOnTheCommandLine(): iterable
    {
        yield 'the threshold shorthand' => [
            'size.method-count:threshold=-1',
            MethodCountRule::class,
            'size.method-count',
            MethodCountOptions::class,
            'Option --rule-opt must be at least 0, got -1.',
            ['rules', 'size.method-count', 'threshold'],
        ];

        yield 'a boundary inside a level slot' => [
            'complexity.ccn:callable.warning=-5',
            ComplexityRule::class,
            'complexity.ccn',
            ComplexityOptions::class,
            'Option --rule-opt must be at least 0, got -5.',
            ['rules', 'complexity.ccn', 'callable', 'warning'],
        ];

        yield 'a minimum count' => [
            'cohesion.lcom:min-methods=-3',
            LcomRule::class,
            'cohesion.lcom',
            LcomOptions::class,
            'Option --rule-opt must be at least 0, got -3.',
            ['rules', 'cohesion.lcom', 'min-methods'],
        ];

        yield 'a fractional boundary' => [
            'coupling.instability:class.max-warning=-0.5',
            InstabilityRule::class,
            'coupling.instability',
            InstabilityOptions::class,
            'Option --rule-opt must be at least 0, got -0.5.',
            ['rules', 'coupling.instability', 'class', 'max-warning'],
        ];
    }

    /**
     * The floor is zero and zero itself is inside it: `warning: 0` reports
     * every symbol, which is how a reader asks "show me all of them", and a
     * fix that refused it would have eaten a working configuration.
     */
    #[Test]
    public function itKeepsAcceptingZeroAtTheFloor(): void
    {
        $options = $this->fromConfigurationFile(
            "  size.method-count:\n    warning: 0\n    error: 0\n",
            'size.method-count',
            MethodCountOptions::class,
        );

        self::assertInstanceOf(MethodCountOptions::class, $options);
        self::assertSame(0, $options->warning);
        self::assertSame(0, $options->error);
    }

    #[Test]
    public function itKeepsUnfoldingAZeroThresholdShorthand(): void
    {
        $options = $this->fromCommandLine(
            'size.method-count:threshold=0',
            MethodCountRule::class,
            'size.method-count',
            MethodCountOptions::class,
        );

        self::assertInstanceOf(MethodCountOptions::class, $options);
        self::assertSame(0, $options->warning);
        self::assertSame(0, $options->error);
    }

    #[Test]
    public function itKeepsAcceptingAFractionalZeroAndAnExplicitNull(): void
    {
        $options = $this->fromConfigurationFile(
            "  coupling.instability:\n    class:\n      max_warning: 0.0\n      max_error: ~\n",
            'coupling.instability',
            InstabilityOptions::class,
        );

        self::assertInstanceOf(InstabilityOptions::class, $options);
        self::assertSame(0.0, $options->class->maxWarning);
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    private function fromConfigurationFile(string $rulesBlock, string $ruleName, string $optionsClass): RuleOptionsInterface
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-rule-option-range-');
        self::assertNotFalse($path);
        file_put_contents($path, "rules:\n" . $rulesBlock);

        try {
            $config = WrittenFile::foldedValues($path);
        } finally {
            unlink($path);
        }

        /** @var array<string, mixed> $rules */
        $rules = $config['rules'] ?? [];

        return $this->create(new FindingConfiguration(
            new RuleOptionsDocument($rules),
            new FindingCliOverrides(),
        ), $ruleName, $optionsClass);
    }

    /**
     * @param class-string<RuleDefinitionInterface> $ruleClass
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    /**
     * @param class-string<RuleDefinitionInterface> $ruleClass
     * @param class-string<RuleOptionsInterface> $optionsClass
     * @param ?list<string> $expectedPath
     */
    private function fromCommandLine(string $ruleOpt, string $ruleClass, string $ruleName, string $optionsClass, ?array $expectedPath = null): RuleOptionsInterface
    {
        $parser = (new RuleOptionsParserFactory())->createFromClasses([$ruleClass]);
        $input = new ArrayInput(['--rule-opt' => [$ruleOpt]], new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]));
        $writes = (new CliOptionsParser($parser))->pathWrites($input);
        if ($expectedPath !== null) {
            self::assertCount(1, $writes);
            self::assertSame($expectedPath, $writes[0]->path);
        }
        $pipeline = new ConfigurationPipeline();
        $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
        $execution = $container->get(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class, $execution);
        foreach (['rules', 'only_rules', 'disabled_rules'] as $root) {
            $pipeline->addSection(new \Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection($execution, $root));
        }
        $pipeline->addStage(new CliStage());
        $document = $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: $writes));
        $configuration = FindingConfiguration::fromDocument($document);

        return $this->create($configuration, $ruleName, $optionsClass);
    }

    /**
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    private function create(FindingConfiguration $configuration, string $ruleName, string $optionsClass): RuleOptionsInterface
    {
        $registry = new RuleOptionsRegistry();
        ResolvedOptionsFixture::configure($registry, $configuration);

        return (new ResolvedOptionsFixture($registry))->create($ruleName, $optionsClass);
    }

    private function capture(callable $act): ?Throwable
    {
        try {
            $act();
        } catch (Throwable $refusal) {
            return $refusal;
        }

        return null;
    }
}
