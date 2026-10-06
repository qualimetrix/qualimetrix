<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface;
use Qualimetrix\Analysis\Evidence\Coupling\CboOptions;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityOptions;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoverageOptions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface;
use Qualimetrix\Analysis\Evidence\Size\MethodCountOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Exclusion\RuleNamespaceExclusionProvider;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Infrastructure\Console\CliOptionsParser;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\TestRuleOptions;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\TestRuleOptionsNoConstructor;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\TestRuleOptionsWithRequiredParams;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\TestRuleOptionsWithUnionType;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(RuleOptionsBuild::class)]
#[CoversClass(RuleOptionsRegistry::class)]
final class RuleOptionsFactoryTest extends TestCase
{
    private RuleOptionsRegistry $registry;
    private ResolvedOptionsFixture $factory;

    /** @var array<string, mixed> the configuration file's `rules:` written so far */
    private array $configFileRules = [];

    /** @var array<string, array<string, mixed>> the command line's rule options written so far */
    private array $cliRules = [];

    protected function setUp(): void
    {
        $this->registry = new RuleOptionsRegistry();
        $this->factory = new ResolvedOptionsFixture($this->registry);
    }

    #[Test]
    public function itCreatesWithDefaults(): void
    {
        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertInstanceOf(TestRuleOptions::class, $options);
        self::assertTrue($options->enabled);
        self::assertSame(10, $options->warningThreshold);
        self::assertSame(20, $options->errorThreshold);
        self::assertTrue($options->countNullsafe);
    }

    #[Test]
    public function itCreatesWithConfigFileOptions(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 15,
                'error_threshold' => 30,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        self::assertSame(15, $options->warningThreshold);
        self::assertSame(30, $options->errorThreshold);
        // Defaults preserved
        self::assertTrue($options->enabled);
        self::assertTrue($options->countNullsafe);
    }

    #[Test]
    public function itCreatesWithCliOptions(): void
    {
        $this->writeCliOption('test-rule', 'warningThreshold', 25);
        $this->writeCliOption('test-rule', 'countNullsafe', false);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        self::assertSame(25, $options->warningThreshold);
        self::assertFalse($options->countNullsafe);
        // Defaults preserved
        self::assertSame(20, $options->errorThreshold);
    }

    #[Test]
    public function itCliOptionsOverrideConfigFile(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 15,
            ],
        ]);

        $this->writeCliOption('test-rule', 'warningThreshold', 25);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        // CLI wins
        self::assertSame(25, $options->warningThreshold);
    }

    #[Test]
    public function itSetsCliOptions(): void
    {
        $this->writeCliOptions('test-rule', [
            'warningThreshold' => 50,
            'errorThreshold' => 100,
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        self::assertSame(50, $options->warningThreshold);
        self::assertSame(100, $options->errorThreshold);
    }

    #[Test]
    public function itGetsConfigFileOptions(): void
    {
        $this->writeConfigFile([
            'rule-a' => ['enabled' => false],
            'rule-b' => ['enabled' => true],
        ]);

        $configuration = ResolvedOptionsFixture::authoredConfiguration(['rules' => $this->configFileRules], [
            new RuleMetadata('rule-a', TestRuleOptions::class, '', [], false),
            new RuleMetadata('rule-b', TestRuleOptions::class, '', [], false),
        ]);
        $options = $configuration->document->get('rules')?->plain();

        self::assertSame(['rule-a' => ['enabled' => false], 'rule-b' => ['enabled' => true]], $options);
    }

    #[Test]
    public function itRefusesUndeclaredCliKeysForEachProducer(): void
    {
        $this->assertUnsupportedCliWrite(
            'rule-a',
            'opt1',
            'value1',
            'Option "opt1" is not an option of rule "rule-a". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'rule-b',
            'opt2',
            'value2',
            'Option "opt2" is not an option of rule "rule-b". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itThrowsForNonExistentClass(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('does not exist');

        /** @phpstan-ignore argument.type */
        $this->factory->create('test-rule', 'NonExistent\\Class');
    }

    #[Test]
    public function itThrowsForNonRuleOptionsClass(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must implement');

        /** @phpstan-ignore argument.type */
        $this->factory->create('test-rule', stdClass::class);
    }

    #[Test]
    public function itNormalizesSnakeCaseKeys(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 15,
                'count_nullsafe' => false,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        self::assertSame(15, $options->warningThreshold);
        self::assertFalse($options->countNullsafe);
    }

    #[Test]
    public function itNormalizesKebabCaseKeys(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning-threshold' => 15,
                'count-nullsafe' => false,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        self::assertSame(15, $options->warningThreshold);
        self::assertFalse($options->countNullsafe);
    }

    #[Test]
    public function itRefusesUndeclaredCliLevels(): void
    {
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'method.warning',
            5,
            'Option "method.warning" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'method.error',
            10,
            'Option "method.error" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'class.enabled',
            false,
            'Option "class.enabled" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itRefusesAnUndeclaredNestedFileOption(): void
    {
        $this->assertUnsupportedFileWrite(
            'test-rule',
            ['enabled' => true, 'nested' => ['level1' => ['level2' => 'deep-value']]],
            'nested',
            'Unknown key "rules.test-rule.nested" in configuration file "/project/qmx.yaml". Accepted keys: count-nullsafe, enabled, error-threshold, warning-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths.',
        );
    }

    #[Test]
    public function itDeepMergesNestedArrays(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 15,
                'enabled' => true,
            ],
        ]);

        $this->writeCliOptions('test-rule', [
            'errorThreshold' => 25,
            'countNullsafe' => false,
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        // All three sources merged: defaults + config + CLI
        self::assertTrue($options->enabled); // from config
        self::assertSame(15, $options->warningThreshold); // from config
        self::assertSame(25, $options->errorThreshold); // from CLI
        self::assertFalse($options->countNullsafe); // from CLI
    }

    #[Test]
    public function itHandlesEmptyConfigArrays(): void
    {
        $this->writeConfigFile([]);
        $this->writeCliOptions('test-rule', []);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        // Should use all defaults
        self::assertTrue($options->enabled);
        self::assertSame(10, $options->warningThreshold);
        self::assertSame(20, $options->errorThreshold);
        self::assertTrue($options->countNullsafe);
    }

    #[Test]
    public function itOverridesArrayValuesInMerge(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 5,
            ],
        ]);

        // CLI completely overrides config value (not merges)
        $this->writeCliOption('test-rule', 'warningThreshold', 50);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(50, $options->warningThreshold);
    }

    #[Test]
    public function itRefusesAMalformedMixedCaseKeyInItsOriginalSpelling(): void
    {
        $this->writeConfigFile(['test-rule' => ['Warning_Threshold' => 12, 'error-threshold' => 24]]);
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Key "rules.test-rule.Warning_Threshold" in configuration file "/project/qmx.yaml" is not written in an accepted spelling; write "warning-threshold" (its snake_case, camelCase and kebab-case spellings are accepted).');
        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itAcceptsCanonicalSnakeCamelAndKebabKeys(): void
    {
        foreach (['warning_threshold', 'warningThreshold', 'warning-threshold'] as $key) {
            $this->writeConfigFile(['test-rule' => [$key => 12, 'error-threshold' => 24]]);
            $options = $this->factory->create('test-rule', TestRuleOptions::class);
            self::assertInstanceOf(TestRuleOptions::class, $options);
            self::assertSame(12, $options->warningThreshold);
            self::assertSame(24, $options->errorThreshold);
        }
    }

    #[Test]
    public function itRefusesUndeclaredMultilevelCliKeys(): void
    {
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'level1.level2.level3',
            'deep',
            'Option "level1.level2.level3" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'level1.level2.other',
            'value',
            'Option "level1.level2.other" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itRefusesABooleanOptionWrittenAsAString(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'enabled' => 'true',
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.test-rule.enabled" in configuration file "/project/qmx.yaml" must be boolean, got string.');

        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itHandlesNullValues(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => null,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        // null should fall through to default via ??
        self::assertSame(10, $options->warningThreshold);
    }

    #[Test]
    public function itPreservesZeroValues(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 0,
                'error_threshold' => 0,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        // 0 is valid, should not fall through to default
        self::assertSame(0, $options->warningThreshold);
        self::assertSame(0, $options->errorThreshold);
    }

    #[Test]
    public function itRefusesAFractionWhereAWholeNumberWasDeclared(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 10.5,
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.test-rule.warning_threshold" in configuration file "/project/qmx.yaml" must be integer at least 0, got float.');

        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itMergesPartialConfigFileOptions(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'enabled' => false, // only override enabled
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertFalse($options->enabled); // from config
        self::assertSame(10, $options->warningThreshold); // default
        self::assertSame(20, $options->errorThreshold); // default
        self::assertTrue($options->countNullsafe); // default
    }

    #[Test]
    public function itHandlesMultipleRulesIndependently(): void
    {
        $this->writeConfigFile([
            'rule-a' => ['warning_threshold' => 5],
            'rule-b' => ['warning_threshold' => 15],
        ]);

        $this->writeCliOption('rule-a', 'errorThreshold', 10);
        $this->writeCliOption('rule-b', 'errorThreshold', 30);

        $snapshot = ResolvedOptionsFixture::build(ResolvedOptionsFixture::authoredConfiguration(['rules' => $this->configFileRules], [new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata('rule-a', TestRuleOptions::class, '', [], false), new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata('rule-b', TestRuleOptions::class, '', [], false)], cliOptions: $this->cliRules), [
            new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata('rule-a', TestRuleOptions::class, '', [], false),
            new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata('rule-b', TestRuleOptions::class, '', [], false),
        ]);
        $optionsA = $snapshot->for('rule-a');
        self::assertInstanceOf(TestRuleOptions::class, $optionsA);
        /** @var TestRuleOptions $optionsB */
        $optionsB = $snapshot->for('rule-b');
        self::assertInstanceOf(TestRuleOptions::class, $optionsB);

        self::assertSame(5, $optionsA->warningThreshold);
        self::assertSame(10, $optionsA->errorThreshold);

        self::assertSame(15, $optionsB->warningThreshold);
        self::assertSame(30, $optionsB->errorThreshold);
    }

    #[Test]
    public function itRefusesEachUndeclaredIncrementalCliWrite(): void
    {
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'option1',
            'value1',
            'Option "option1" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'option2',
            'value2',
            'Option "option2" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'option3',
            'value3',
            'Option "option3" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itOverwritesCliOptionWhenAddedTwice(): void
    {
        $this->writeCliOption('test-rule', 'warningThreshold', 5);
        $this->writeCliOption('test-rule', 'warningThreshold', 15);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(15, $options->warningThreshold);
    }

    #[Test]
    public function itRefusesBothUndeclaredReplacementCliWrites(): void
    {
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'option1',
            'old',
            'Option "option1" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'option2',
            'new',
            'Option "option2" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itRefusesEmptyAndUndeclaredFileKeys(): void
    {
        $this->assertUnsupportedFileWrite(
            'test-rule',
            ['' => 'empty-key-value'],
            '',
            'Unknown key "rules.test-rule." in configuration file "/project/qmx.yaml". Accepted keys: count-nullsafe, enabled, error-threshold, warning-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths.',
        );
        $this->assertUnsupportedFileWrite(
            'test-rule',
            ['valid_key' => 'valid-value'],
            'valid_key',
            'Unknown key "rules.test-rule.valid_key" in configuration file "/project/qmx.yaml". Accepted keys: count-nullsafe, enabled, error-threshold, warning-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths.',
        );
    }

    #[Test]
    public function itRefusesANumericPrefixInAnUndeclaredFileKey(): void
    {
        $this->assertUnsupportedFileWrite(
            'test-rule',
            ['123_value' => 'numeric-start'],
            '123_value',
            'Unknown key "rules.test-rule.123_value" in configuration file "/project/qmx.yaml". Accepted keys: count-nullsafe, enabled, error-threshold, warning-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths.',
        );
    }

    #[Test]
    public function itRefusesUndeclaredSimpleAndDottedCliKeys(): void
    {
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'simpleKey',
            'value',
            'Option "simpleKey" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'nested.key',
            'nested-value',
            'Option "nested.key" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itCreatesNestedStructureFromDotNotationDuringMerge(): void
    {
        // When create() is called, dot notation should expand
        $this->writeConfigFile([
            'test-rule' => [
                'enabled' => true,
            ],
        ]);

        $this->writeCliOption('test-rule', 'warningThreshold', 99);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertTrue($options->enabled);
        self::assertSame(99, $options->warningThreshold);
    }

    #[Test]
    public function itHandlesArrayMergeWithScalarOverwrite(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 5,
            ],
        ]);

        // Overwrite from the CLI door, which hands over an already-typed value
        $this->writeCliOption('test-rule', 'warningThreshold', 25);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(25, $options->warningThreshold);
    }

    #[Test]
    public function itPreservesCamelCaseKeysFromConfigFile(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warningThreshold' => 8, // already camelCase
                'errorThreshold' => 16,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(8, $options->warningThreshold);
        self::assertSame(16, $options->errorThreshold);
    }

    #[Test]
    public function itHandlesConfigWithOnlyDisabledFlag(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'enabled' => false,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertFalse($options->enabled);
        // Other values should be defaults
        self::assertSame(10, $options->warningThreshold);
        self::assertSame(20, $options->errorThreshold);
    }

    #[Test]
    public function itHandlesEmptyRuleNameInConfig(): void
    {
        $this->writeConfigFile([
            '' => [
                'warning_threshold' => 5,
            ],
        ]);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Producer "" has no family: its name must start with a non-empty dot-separated segment, which is what `qmx rules` groups it under.');
        $this->factory->create('', TestRuleOptions::class);
    }

    #[Test]
    public function itRefusesRawWritesAndResetsAnActuallyReadySnapshot(): void
    {
        $this->assertUnsupportedFileWrite(
            'rule1',
            ['opt1' => 'val1'],
            'opt1',
            'Unknown key "rules.rule1.opt1" in configuration file "/project/qmx.yaml". Accepted keys: count-nullsafe, enabled, error-threshold, warning-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths.',
        );
        $this->assertUnsupportedFileWrite(
            'rule2',
            ['opt2' => 'val2'],
            'opt2',
            'Unknown key "rules.rule2.opt2" in configuration file "/project/qmx.yaml". Accepted keys: count-nullsafe, enabled, error-threshold, warning-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths.',
        );
        $this->assertUnsupportedCliWrite(
            'rule1',
            'cliOpt',
            'cliVal',
            'Option "cliOpt" is not an option of rule "rule1". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'rule3',
            'cliOpt2',
            'cliVal2',
            'Option "cliOpt2" is not an option of rule "rule3". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->writeConfigFile(['test-rule' => ['warning-threshold' => 30, 'error-threshold' => 40]]);
        $configured = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $configured);
        self::assertSame(30, $configured->warningThreshold);
        self::assertSame($configured, $this->registry->resolvedOptions()->for('test-rule'));

        $this->registry->resetRuntimeState();
        try {
            $this->registry->resolvedOptions();
            self::fail('Reset must invalidate the installed ready snapshot.');
        } catch (LogicException $refusal) {
            self::assertSame('Rule options are unavailable before analysis preflight.', $refusal->getMessage());
        }
        $this->resetRun();
        $defaults = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $defaults);
        self::assertTrue($defaults->enabled);
        self::assertSame(10, $defaults->warningThreshold);
        self::assertSame(20, $defaults->errorThreshold);
        self::assertSame($defaults, $this->registry->resolvedOptions()->for('test-rule'));
    }

    #[Test]
    public function itMergesPriorityCorrectly(): void
    {
        // Setup: defaults (10, 20) → config (15, 25) → CLI (warningThreshold=30)
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 15,
                'error_threshold' => 25,
            ],
        ]);

        $this->writeCliOption('test-rule', 'warningThreshold', 30);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        // Priority: CLI > config > defaults
        self::assertSame(30, $options->warningThreshold); // CLI wins
        self::assertSame(25, $options->errorThreshold); // config wins
        self::assertTrue($options->enabled); // default
        self::assertTrue($options->countNullsafe); // default
    }

    #[Test]
    public function itHandlesOptionsClassWithoutConstructor(): void
    {
        /** @var TestRuleOptionsNoConstructor $options */
        $options = $this->factory->create('test-rule', TestRuleOptionsNoConstructor::class);

        self::assertInstanceOf(TestRuleOptionsNoConstructor::class, $options);
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itExtractsTypeBasedDefaultsForRequiredParameters(): void
    {
        // No config provided - should use type-based defaults
        /** @var TestRuleOptionsWithRequiredParams $options */
        $options = $this->factory->create('test-rule', TestRuleOptionsWithRequiredParams::class);

        self::assertInstanceOf(TestRuleOptionsWithRequiredParams::class, $options);
        // Type-based defaults
        self::assertTrue($options->enabled); // bool -> true
        self::assertSame(0, $options->threshold); // int -> 0
        self::assertSame(0.0, $options->ratio); // float -> 0.0
        self::assertSame('', $options->name); // string -> ''
        self::assertSame([], $options->items); // array -> []
        self::assertNull($options->optional); // nullable -> null
    }

    #[Test]
    public function itMergesConfigWithTypeBasedDefaults(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'enabled' => false,
                'threshold' => 100,
                'name' => 'custom',
            ],
        ]);

        /** @var TestRuleOptionsWithRequiredParams $options */
        $options = $this->factory->create('test-rule', TestRuleOptionsWithRequiredParams::class);

        // From config
        self::assertFalse($options->enabled);
        self::assertSame(100, $options->threshold);
        self::assertSame('custom', $options->name);

        // Type-based defaults (not in config)
        self::assertSame(0.0, $options->ratio);
        self::assertSame([], $options->items);
        self::assertNull($options->optional);
    }

    #[Test]
    public function itOverridesTypeBasedDefaultsWithCliOptions(): void
    {
        $this->writeCliOption('test-rule', 'enabled', false);
        $this->writeCliOption('test-rule', 'threshold', 50);
        $this->writeCliOption('test-rule', 'ratio', 0.5);
        $this->writeCliOption('test-rule', 'name', 'cli-name');
        $this->writeCliOption('test-rule', 'items', ['a', 'b', 'c']);
        $this->writeCliOption('test-rule', 'optional', 'value');

        /** @var TestRuleOptionsWithRequiredParams $options */
        $options = $this->factory->create('test-rule', TestRuleOptionsWithRequiredParams::class);

        // All from CLI
        self::assertFalse($options->enabled);
        self::assertSame(50, $options->threshold);
        self::assertSame(0.5, $options->ratio);
        self::assertSame('cli-name', $options->name);
        self::assertSame(['a', 'b', 'c'], $options->items);
        self::assertSame('value', $options->optional);
    }

    #[Test]
    public function itHandlesUnionTypeParametersWithNullDefault(): void
    {
        // Union types (int|string) should fall back to null
        /** @var TestRuleOptionsWithUnionType $options */
        $options = $this->factory->create('test-rule', TestRuleOptionsWithUnionType::class);

        self::assertInstanceOf(TestRuleOptionsWithUnionType::class, $options);
        self::assertNull($options->value); // Union type -> null default
    }

    #[Test]
    public function itRefusesEachUndeclaredComplexityCliLevel(): void
    {
        $this->assertUnsupportedCliWrite(
            'complexity',
            'method.warning',
            5,
            'Option "method.warning" is not an option of rule "complexity". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'complexity',
            'method.error',
            10,
            'Option "method.error" is not an option of rule "complexity". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'complexity',
            'class.warning',
            15,
            'Option "class.warning" is not an option of rule "complexity". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'complexity',
            'class.error',
            20,
            'Option "class.error" is not an option of rule "complexity". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itRefusesBothUndeclaredSiblingCliKeys(): void
    {
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'nested.key1',
            'value1',
            'Option "nested.key1" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'nested.key2',
            'value2',
            'Option "nested.key2" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itDoesNotLeakCliOptionsIntoTheNextRunAfterReset(): void
    {
        // Simulate first run
        $this->writeCliOptions('test-rule', ['warningThreshold' => 50]);

        /** @var TestRuleOptions $options1 */
        $options1 = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertSame(50, $options1->warningThreshold);

        // Reset between runs, the way the product does before resolving the next configuration
        $this->resetRun();

        // Second run without CLI options — should use defaults
        /** @var TestRuleOptions $options2 */
        $options2 = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertSame(10, $options2->warningThreshold, 'CLI options from first run should not leak into second run');
    }

    #[Test]
    public function itNormalizesScalarFalseRuleConfig(): void
    {
        // YAML: `rules: { test-rule: false }` arrives as scalar false
        $this->writeConfigFile([
            'test-rule' => false,
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertFalse($options->enabled);
        // Other values should be defaults
        self::assertSame(10, $options->warningThreshold);
        self::assertSame(20, $options->errorThreshold);
    }

    #[Test]
    public function itNormalizesScalarTrueRuleConfig(): void
    {
        // YAML: `rules: { test-rule: true }` arrives as scalar true
        $this->writeConfigFile([
            'test-rule' => true,
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertTrue($options->enabled);
        self::assertSame(10, $options->warningThreshold);
    }

    #[Test]
    public function itNormalizesScalarNullRuleConfig(): void
    {
        // YAML: `rules: { test-rule: ~ }` arrives as null
        $this->writeConfigFile([
            'test-rule' => null,
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        // Null should use all defaults
        self::assertTrue($options->enabled);
        self::assertSame(10, $options->warningThreshold);
        self::assertSame(20, $options->errorThreshold);
    }

    #[Test]
    public function itRefusesAnUndeclaredDeepCliKey(): void
    {
        $this->assertUnsupportedCliWrite(
            'test-rule',
            'a.b.c.d.e',
            'deep-value',
            'Option "a.b.c.d.e" is not an option of rule "test-rule". Options here: count-nullsafe, enabled, error-threshold, suppress-namespace-channels, suppress-namespaces, suppress-paths, warning-threshold.',
        );
    }

    #[Test]
    public function itThrowsWhenNumericFieldContainsNonNumericString(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 'not_a_number',
            ],
        ]);

        try {
            $this->factory->create('test-rule', TestRuleOptions::class);
            self::fail('The non-numeric value was accepted.');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(
                '"rules.test-rule.warning_threshold" in configuration file "/project/qmx.yaml" must be integer at least 0, got string.',
                $e->getMessage(),
            );
            self::assertCount(1, $e->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $e->sources()[0]->source());
            self::assertSame('/project/qmx.yaml', $e->sources()[0]->locator());
            self::assertNotNull($e->position());
            self::assertSame('warning_threshold', $e->position()->written);
            self::assertFalse($e->position()->closed);
        }
    }

    #[Test]
    public function itThrowsWhenErrorThresholdIsNonNumericString(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'error_threshold' => 'invalid',
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.test-rule.error_threshold" in configuration file "/project/qmx.yaml" must be integer at least 0, got string.');

        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itRefusesANumericStringForAWholeNumberOption(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => '15',
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.test-rule.warning_threshold" in configuration file "/project/qmx.yaml" must be integer at least 0, got string.');

        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itAcceptsAWholeNumberWrittenAsANumber(): void
    {
        $this->writeConfigFile([
            'test-rule' => [
                'warning_threshold' => 15,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(15, $options->warningThreshold);
    }

    #[Test]
    public function itIncludesRuleNameInNumericValidationError(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => [
                'error_threshold' => 'not_a_number',
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.complexity.ccn.error_threshold" in configuration file "/project/qmx.yaml" must be integer at least 0, got string.');

        $this->factory->create('complexity.ccn', TestRuleOptions::class);
    }

    // --- suppress_namespaces extraction tests ---

    #[Test]
    public function itExtractsSuppressNamespacesWrittenInSnakeCase(): void
    {
        $this->writeConfigFile([
            'test.rule' => [
                'suppress_namespaces' => [
                    ['subtree' => 'App\\Tests'],
                    ['exact' => 'App\\Legacy'],
                ],
                'warningThreshold' => 5,
            ],
        ]);

        $this->factory->create('test.rule', TestRuleOptions::class);

        self::assertTrue($this->registry->isNamespaceExcluded('test.rule', 'App\\Tests'));
        self::assertTrue($this->registry->isNamespaceExcluded('test.rule', 'App\\Legacy'));
    }

    #[Test]
    public function itExtractsSuppressNamespacesWrittenInCamelCase(): void
    {
        $this->writeConfigFile([
            'test.rule' => [
                'suppressNamespaces' => [['subtree' => 'App\\Tests']],
            ],
        ]);

        $this->factory->create('test.rule', TestRuleOptions::class);

        self::assertTrue($this->registry->isNamespaceExcluded('test.rule', 'App\\Tests'));
    }

    #[Test]
    public function itRefusesAScalarSuppressNamespacesValue(): void
    {
        $this->writeConfigFile([
            'test.rule' => [
                'suppress_namespaces' => 'App\\Tests',
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.test.rule.suppress_namespaces" in configuration file "/project/qmx.yaml" must be a list, got string.');

        $this->factory->create('test.rule', TestRuleOptions::class);
    }

    #[Test]
    public function itExtractsCodeScopedNamespaceExclusions(): void
    {
        $this->writeConfigFile([
            'computed.health' => [
                'suppress_namespace_channels' => [
                    'health.cohesion' => [['subtree' => 'App\\Metrics']],
                    'health.typing' => [['subtree' => 'App\\Generated']],
                ],
            ],
        ]);

        $metadata = [new RuleMetadata('computed.health', TestRuleOptions::class, '', [], false)];
        $metricReach = self::createStub(MetricReachCatalogInterface::class);
        $metricReach->method('metricReach')->willThrowException(new LogicException('This fixture does not query measured-metric reach.'));
        $computedReach = self::createStub(ComputedMetricReachInterface::class);
        $computedReach->method('reachAt')->willThrowException(new LogicException('This fixture does not query computed-metric reach.'));

        $channels = new \Qualimetrix\Infrastructure\Rule\ChannelUniverse(
            [
                'health.cohesion' => \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration::occurrence(\Qualimetrix\Core\Symbol\SymbolLevel::Namespace_),
                'health.typing' => \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration::occurrence(\Qualimetrix\Core\Symbol\SymbolLevel::Namespace_),
            ],
            ['computed.health' => ['health.cohesion', 'health.typing']],
            ['computed.health' => false],
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions([]),
            $metricReach,
            $computedReach,
        );
        $configuration = ResolvedOptionsFixture::authoredConfiguration(['rules' => $this->configFileRules], $metadata);
        $this->registry->replace(ResolvedOptionsFixture::ready($configuration, $metadata, channels: $channels));

        self::assertTrue($this->registry->isNamespaceChannelExcluded(
            'computed.health',
            new FindingChannel('health.cohesion'),
            'App\\Metrics',
        ));
        self::assertTrue($this->registry->isNamespaceChannelExcluded(
            'computed.health',
            new FindingChannel('health.typing'),
            'App\\Generated',
        ));
    }

    #[Test]
    public function itRejectsEmptyCodeScopedNamespaceExclusions(): void
    {
        $this->writeConfigFile([
            'computed.health' => [
                'suppress_namespace_channels' => ['health.cohesion' => []],
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('suppress_namespace_channels.health.cohesion');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsEmptyNamespacePatternsInChannelExclusions(): void
    {
        $this->writeConfigFile([
            'computed.health' => [
                'suppress_namespace_channels' => ['health.cohesion' => [['exact' => '']]],
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.computed.health.suppress_namespace_channels.health.cohesion[0].exact" in configuration file "/project/qmx.yaml" must be non-empty text.');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsNonListChannelNamespaceExclusions(): void
    {
        $this->writeConfigFile([
            'computed.health' => [
                'suppress_namespace_channels' => ['health.cohesion' => 'App\\Metrics'],
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.computed.health.suppress_namespace_channels.health.cohesion" in configuration file "/project/qmx.yaml" must be a list, got string.');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsEmptyCodeSelectorsInNamespaceChannelExclusions(): void
    {
        $this->writeConfigFile([
            'computed.health' => [
                'suppress_namespace_channels' => ['' => [['subtree' => 'App\\Metrics']]],
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('empty or non-string channel selector');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsChannelMapsWhereASelectorListIsRequired(): void
    {
        $this->writeConfigFile([
            'computed.health' => [
                'suppress_namespaces' => ['health.cohesion' => ['App\\Metrics']],
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.computed.health.suppress_namespaces" in configuration file "/project/qmx.yaml" must be a list, got a map.');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsNonStringLegacyNamespaceExclusions(): void
    {
        $this->writeConfigFile([
            'computed.health' => [
                'suppress_namespaces' => [['subtree' => 'App\\Metrics'], 42],
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.computed.health.suppress_namespaces[1]" in configuration file "/project/qmx.yaml" must be a map, got int.');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itStripsSuppressNamespacesFromOptionsBeforeBuildingThem(): void
    {
        $this->writeConfigFile([
            'test.rule' => [
                'suppress_namespaces' => [['subtree' => 'App\\Tests']],
                'warningThreshold' => 7,
            ],
        ]);

        $options = $this->factory->create('test.rule', TestRuleOptions::class);

        self::assertInstanceOf(TestRuleOptions::class, $options);
        self::assertSame(7, $options->warningThreshold);
        self::assertTrue($this->registry->isNamespaceExcluded('test.rule', 'App\\Tests'));
    }

    #[Test]
    public function itClearsTheExclusionProviderOnReset(): void
    {
        $provider = new RuleNamespaceExclusionProvider();
        $registry = new RuleOptionsRegistry($provider);
        $factory = new ResolvedOptionsFixture($registry);

        $factory->inputs(['rules' => [
            'test.rule' => ['suppress_namespaces' => [['subtree' => 'App\\Tests']]],
        ]]);
        $factory->create('test.rule', TestRuleOptions::class);
        self::assertSame(
            ['subtree:App\\Tests'],
            array_map(
                static fn(NamespacePattern $pattern): string => $pattern->definition->display(),
                $provider->getExclusions('test.rule'),
            ),
        );

        $registry->resetRuntimeState();
        self::assertSame([], $provider->getExclusions('test.rule'));
    }

    // --- regression: a rule configured with ONLY framework-level keys
    // (suppress_namespaces / suppress_paths) must stay enabled ---
    //
    // Bug: an earlier version of create() decided "$userConfig === [] ->
    // fall back to $defaults" BEFORE extractExcludeNamespaces()/
    // extractExcludePaths() stripped the framework-level keys out of
    // $userConfig. So a rule configured with ONLY `suppress_namespaces`
    // looked "non-empty" at check time, $merged became $userConfig, THEN
    // extraction emptied it out to `[]`, and Options::fromArray([]) special-
    // cases an empty array as "disabled" for ~21 rule classes (including
    // LongParameterListOptions, used below) — silently disabling the rule
    // even though the user never touched `enabled`. These tests use a real
    // production Options class (not the TestRuleOptions fixture, which
    // doesn't have the "$config === [] -> disabled" sentinel and so
    // couldn't reproduce this) end-to-end through the factory.

    #[Test]
    public function itKeepsTheRuleEnabledWhenOnlyExcludeNamespacesIsConfiguredInTheFile(): void
    {
        $this->writeConfigFile([
            'code-smell.long-parameter-list' => [
                'suppress_namespaces' => [['subtree' => 'App\\Tests']],
            ],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertTrue($options->isEnabled(), 'A rule configured with only suppress_namespaces must stay enabled');
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
        self::assertTrue($this->registry->isNamespaceExcluded('code-smell.long-parameter-list', 'App\\Tests'));
    }

    #[Test]
    public function itKeepsTheRuleEnabledWhenOnlyExcludePathsIsConfiguredInTheFile(): void
    {
        $this->writeConfigFile([
            'code-smell.long-parameter-list' => [
                'suppress_paths' => [['subtree' => 'src/Legacy']],
            ],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertTrue($options->isEnabled(), 'A rule configured with only suppress_paths must stay enabled');
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
        self::assertTrue(
            $this->registry->isPathExcluded(
                'code-smell.long-parameter-list',
                RelativePath::fromString('src/Legacy/Foo.php'),
            ),
        );
    }

    #[Test]
    public function itKeepsTheRuleEnabledWhenExcludeNamespacesIsConfiguredAlongsideARealOption(): void
    {
        $this->writeConfigFile([
            'code-smell.long-parameter-list' => [
                'suppress_namespaces' => [['subtree' => 'App\\Tests']],
                'error' => 8,
            ],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertTrue($options->isEnabled());
        self::assertSame(8, $options->error);
        self::assertSame(4, $options->warning, 'Untouched sibling key keeps its own default');
        self::assertTrue($this->registry->isNamespaceExcluded('code-smell.long-parameter-list', 'App\\Tests'));
    }

    #[Test]
    public function itKeepsTheRuleEnabledWhenOnlyExcludeNamespacesIsConfiguredViaCli(): void
    {
        // No config file entry at all for this rule — suppress_namespaces
        // arrives purely through --rule-opt / addCliOption().
        $this->writeCliOption(
            'code-smell.long-parameter-list',
            'suppressNamespaces',
            [['subtree' => 'App\\Tests']],
        );

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertTrue($options->isEnabled(), 'A rule configured with only a CLI suppress_namespaces must stay enabled');
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
        self::assertTrue($this->registry->isNamespaceExcluded('code-smell.long-parameter-list', 'App\\Tests'));
    }

    // --- retired `exclude*` spelling refuses instead of warning ---
    //
    // An unknown option key on a rule only ever
    // produced a logged warning (RuleOptionsFactory::warnAboutUnknownKeys()),
    // so a config still using the pre-rename spelling would keep the rule
    // running with its suppression silently switched off. Each of the five
    // retired spellings, snake_case and camelCase, must refuse by name
    // instead, naming both the rule-level `suppress_*` replacement and the
    // unrelated `exclude` option.

    // The config-file path runs snake_case keys through normalizeKeys()
    // before this check ever sees them (see itNormalizesSnakeCaseKeys()),
    // collapsing `exclude_namespaces` to `excludeNamespaces` on the way in —
    // so the snake_case spelling is only observable, unnormalized, on the
    // CLI/--rule-opt path (addCliOption() below bypasses that normalizer),
    // and the camelCase spelling is exercised through the config file.

    #[Test]
    public function itRefusesTheRetiredSnakeCaseExcludeNamespacesSpelling(): void
    {
        $this->writeCliOption('code-smell.long-parameter-list', 'exclude_namespaces', ['App\\Tests']);

        // Asserted by catching: `expectExceptionMessage()` and its `Matches()`
        // twin each hold one expectation, so a second call of the same kind
        // replaces the first and that half stops being checked.
        try {
            $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);
            self::fail('The retired spelling was accepted.');
        } catch (ConfigurationRefusal $e) {
            self::assertStringContainsString('The "exclude_namespaces" option was retired', $e->getMessage());
            self::assertStringContainsString('use "suppress_namespaces"', $e->getMessage());
            self::assertStringContainsString('"exclude" option instead', $e->getMessage());
            self::assertCount(1, $e->sources());
            self::assertSame(ConfigurationSource::Resolved, $e->sources()[0]->source());
        }
    }

    #[Test]
    public function itRefusesTheRetiredCamelCaseExcludeNamespacesSpelling(): void
    {
        $this->writeConfigFile([
            'code-smell.long-parameter-list' => ['excludeNamespaces' => ['App\\Tests']],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Key "rules.code-smell.long-parameter-list.excludeNamespaces" in configuration file "/project/qmx.yaml" is retired. The "exclude-namespaces" option was retired. To suppress findings the analysis already produces, use "suppress-namespaces". To exclude files from analysis entirely (the finding is never produced), use the "exclude" option instead — it is a different mechanism, not a renamed one.');

        $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);
    }

    #[Test]
    public function itRefusesTheRetiredExcludeNamespaceChannelsSpelling(): void
    {
        $this->writeCliOption('computed.health', 'exclude_namespace_channels', ['health.cohesion' => ['App\\Metrics']]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessageMatches('/suppress_namespace_channels/');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRefusesTheRetiredSnakeCaseExcludePathsSpelling(): void
    {
        $this->writeCliOption('code-smell.long-parameter-list', 'exclude_paths', ['src/Legacy/**']);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessageMatches('/suppress_paths/');

        $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);
    }

    #[Test]
    public function itRefusesTheRetiredCamelCaseExcludePathsSpelling(): void
    {
        $this->writeCliOption('code-smell.long-parameter-list', 'excludePaths', ['src/Legacy/**']);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessageMatches('/suppressPaths/');

        $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);
    }

    // --- flat `threshold:` shorthand through the full factory path ---
    //
    // Regression coverage for a bug where RuleOptionsFactory::create()
    // unconditionally seeded ALL constructor defaults (including `warning`/
    // `error`) into the array handed to Options::fromArray(), even when the
    // user never set those keys. ThresholdParser::parse() then saw the
    // defaulted `warning`/`error` as "explicitly set" and rejected the flat
    // `threshold:` shorthand as "mixed with warning/error" — even though the
    // user only ever wrote `threshold`. This is exactly the shorthand
    // documented in website/docs/getting-started/configuration.md.
    //
    // These tests go through RuleOptionsFactory::create() end-to-end (not
    // Options::fromArray() directly) because the bug lives entirely in how
    // the factory assembles the array passed to fromArray() — a direct
    // fromArray() call cannot reproduce it.

    #[Test]
    public function itAppliesFlatThresholdShorthandThroughTheFactory(): void
    {
        $this->writeConfigFile([
            'size.method-count' => [
                'threshold' => 25,
            ],
        ]);

        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertTrue($options->isEnabled());
        self::assertSame(25, $options->warning);
        self::assertSame(25, $options->error);
    }

    #[Test]
    public function itAppliesNestedThresholdShorthandThroughTheFactory(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => [
                'callable' => ['threshold' => 15],
            ],
        ]);

        /** @var ComplexityOptions $options */
        $options = $this->factory->create('complexity.ccn', ComplexityOptions::class);

        self::assertSame(15, $options->callable->warning);
        self::assertSame(15, $options->callable->error);
        // Untouched sibling level keeps its own defaults.
        self::assertSame(30, $options->class->maxWarning);
        self::assertSame(50, $options->class->maxError);
    }

    #[Test]
    public function itThrowsWhenUserExplicitlyMixesThresholdAndWarningThroughTheFactory(): void
    {
        $this->writeConfigFile([
            'size.method-count' => [
                'threshold' => 25,
                'warning' => 10,
            ],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.size.method-count" in configuration file "/project/qmx.yaml" writes both "threshold" and "warning" in one layer; "threshold" is shorthand for "warning" and "error" — write either the shorthand or the full keys in one layer.');

        $this->factory->create('size.method-count', MethodCountOptions::class);
    }

    #[Test]
    public function itStillAppliesConstructorDefaultsWhenRuleIsEntirelyUnconfigured(): void
    {
        // No config file / CLI entry at all for this rule — the "enabled by
        // default" contract must be preserved (this is the common case for
        // the vast majority of rules).
        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertTrue($options->isEnabled());
        self::assertSame(20, $options->warning);
        self::assertSame(30, $options->error);
    }

    // --- the declared key set is what a written key is compared against -----
    //
    // Each of these was, before the declaration existed, a false "Unknown
    // option" warning: the factory read constructor parameters and could not
    // see into `fromArray()`, so a documented ThresholdParser shorthand — the
    // bare `threshold`, or a rule-specific one like `vo-threshold` — was
    // reported as unknown while applying correctly. The class now states its
    // own set, and each of these keys is in it. They assert the value too: a
    // declaration that accepted a key and dropped it would pass a test that
    // only asserted "not refused".

    #[Test]
    public function itAcceptsTheDocumentedThresholdShorthandOnASupportingRule(): void
    {
        $this->writeConfigFile([
            'size.method-count' => ['threshold' => 25],
        ]);

        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertSame(25, $options->warning);
        self::assertSame(25, $options->error);
    }

    #[Test]
    public function itAcceptsTheVoThresholdShorthandOnLongParameterList(): void
    {
        $this->writeConfigFile([
            'code-smell.long-parameter-list' => ['vo-threshold' => 9],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertSame(9, $options->voWarning);
        self::assertSame(9, $options->voError);
    }

    #[Test]
    public function itAcceptsTheThresholdShorthandOnTypeCoverage(): void
    {
        $this->writeConfigFile([
            'design.type-coverage.param' => ['threshold' => 70.0],
        ]);

        /** @var TypeCoverageOptions $options */
        $options = $this->factory->create('design.type-coverage.param', TypeCoverageOptions::class);

        self::assertSame(70.0, $options->warning);
        self::assertSame(70.0, $options->error);
    }

    #[Test]
    public function itAcceptsTheThresholdShorthandOnCboAndAppliesItToBothLevels(): void
    {
        $this->writeConfigFile([
            'coupling.cbo' => ['threshold' => 30],
        ]);

        /** @var CboOptions $options */
        $options = $this->factory->create('coupling.cbo', CboOptions::class);

        // CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, )) has a top-level `threshold` flat-shorthand
        // branch that applies uniformly to BOTH the class and namespace
        // dimensions (their defaults already match: 14/20).
        self::assertSame(30, $options->class->warning);
        self::assertSame(30, $options->class->error);
        self::assertSame(30, $options->namespace->warning);
        self::assertSame(30, $options->namespace->error);
    }

    #[Test]
    public function itAcceptsTheThresholdShorthandOnInstabilityAndAppliesItToBothLevels(): void
    {
        $this->writeConfigFile([
            'coupling.instability' => ['threshold' => 0.9],
        ]);

        /** @var InstabilityOptions $options */
        $options = $this->factory->create('coupling.instability', InstabilityOptions::class);

        self::assertSame(0.9, $options->class->maxWarning);
        self::assertSame(0.9, $options->class->maxError);
        self::assertSame(0.9, $options->namespace->maxWarning);
        self::assertSame(0.9, $options->namespace->maxError);
    }

    // --- an unrecognised key is refused, at both depths ---------------------
    //
    // It used to be a warning, at depth 1 only: a key written inside a level
    // slot was compared against nothing at all, and a run continued at the
    // defaults the author believed they had replaced. The exception class is
    // `ConfigurationRefusal` because this is an error in the configuration
    // document; `CheckCommand` prefixes it with "Configuration error: " and
    // exits 3.

    #[Test]
    public function itRefusesAGenuinelyUnknownKeyAtTheRulesOwnDepth(): void
    {
        $this->writeConfigFile([
            'size.method-count' => ['nonsense' => 1],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "rules.size.method-count.nonsense" in configuration file "/project/qmx.yaml". Accepted keys: error, warning, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.');

        $this->factory->create('size.method-count', MethodCountOptions::class);
    }

    /**
     * The framework keys are legal here and declared by no options class, so
     * the printed set has to carry them: a refusal for a mistyped
     * `suppress_path` that listed only the rule's own options would name the
     * fix nowhere.
     */
    #[Test]
    public function itRefusesAMistypedFrameworkKeyAndPrintsTheSpellingThatWorks(): void
    {
        $this->writeConfigFile([
            'size.method-count' => ['suppress_path' => ['src/']],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "rules.size.method-count.suppress_path" in configuration file "/project/qmx.yaml" (did you mean "suppress-paths"?). Accepted keys: error, warning, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.');

        $this->factory->create('size.method-count', MethodCountOptions::class);
    }

    #[Test]
    public function itRefusesAnUnknownKeyInsideALevelSlotAndNamesThatSlotsOwnSet(): void
    {
        $this->writeConfigFile([
            'coupling.cbo' => ['class' => ['maxWarning' => 1]],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "rules.coupling.cbo.class.maxWarning" in configuration file "/project/qmx.yaml" (did you mean "warning"?). Accepted keys: enabled, error, scope, warning, threshold.');

        $this->factory->create('coupling.cbo', CboOptions::class);
    }

    /**
     * Two slots of one rule take disjoint threshold keys, so there is no
     * single "keys allowed at a level" list: `coupling.instability` reads
     * `max-warning` where `coupling.cbo` reads `warning`, at the same slot
     * name. Both halves are asserted here, on the same slot, so a walk that
     * ever grew one shared set would redden.
     */
    #[Test]
    public function itComparesEachSlotAgainstItsOwnLevelClass(): void
    {
        $this->writeConfigFile([
            'coupling.instability' => ['class' => ['max_warning' => 0.6, 'max_error' => 0.8]],
        ]);

        /** @var InstabilityOptions $options */
        $options = $this->factory->create('coupling.instability', InstabilityOptions::class);

        self::assertSame(0.6, $options->class->maxWarning);
        self::assertSame(0.8, $options->class->maxError);

        $this->writeConfigFile([
            'coupling.instability' => ['class' => ['warning' => 0.6]],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "rules.coupling.instability.class.warning" in configuration file "/project/qmx.yaml". Accepted keys: enabled, max-error, max-warning, min-afferent, threshold.');

        $this->factory->create('coupling.instability', InstabilityOptions::class);
    }

    /**
     * `threshold` inside a slot is documented on the website and works, and no
     * constructor names it — the default `$thresholdKey` of
     * `ThresholdParser::parse()` is where it lives. A comparison rebuilt from
     * constructor parameters would refuse it in ten places.
     */
    #[Test]
    public function itAcceptsTheBareThresholdInsideALevelSlotAndAppliesIt(): void
    {
        $this->writeConfigFile([
            'coupling.cbo' => ['class' => ['threshold' => 7]],
        ]);

        /** @var CboOptions $options */
        $options = $this->factory->create('coupling.cbo', CboOptions::class);

        self::assertSame(7, $options->class->warning);
        self::assertSame(7, $options->class->error);
    }

    /**
     * An empty level block means what an omitted one means; refusing it would
     * refuse a harmless YAML idiom. Carried as a case so that a later
     * tightening has to delete a green test rather than merely not notice.
     */
    #[Test]
    public function itAcceptsANullLevelSlotAsAnOmittedOne(): void
    {
        $this->writeConfigFile([
            'coupling.cbo' => ['class' => null],
        ]);

        $options = $this->factory->create('coupling.cbo', CboOptions::class);

        self::assertInstanceOf(CboOptions::class, $options);
    }

    /**
     * A rule has a universal off-switch and a level has none, so
     * `class: false` is a plausible thing to write that did nothing at all.
     */
    #[Test]
    public function itRefusesAFalseLevelSlotWithTheSpellingThatSwitchesOneLevelOff(): void
    {
        $this->writeConfigFile([
            'coupling.cbo' => ['class' => false],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.coupling.cbo.class" in configuration file "/project/qmx.yaml" must be a map, got bool.');

        $this->factory->create('coupling.cbo', CboOptions::class);
    }

    #[Test]
    public function itRefusesANonMapLevelSlotWithoutInventingAdvice(): void
    {
        $this->writeConfigFile([
            'coupling.cbo' => ['class' => 10],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.coupling.cbo.class" in configuration file "/project/qmx.yaml" must be a map, got int.');

        $this->factory->create('coupling.cbo', CboOptions::class);
    }

    /**
     * The refusal answers in the spelling the factory received and applies no
     * inverse transformation to it. Through every real door that spelling is
     * the folded one — the YAML loader and the `--rule-opt` parser both fold
     * separators before the factory exists — so a mistyped `max_warnign`
     * arrives, and is answered, as `maxWarnign`. That is ADR 0044's limit at
     * this seam; the letters, which is what a typo gets wrong, survive it.
     *
     * The registry is written to directly here, which is the one door that
     * folds nothing, so the folded spelling is written out rather than
     * produced — and the assertion is on the printing, not on the folding.
     */
    #[Test]
    public function itPrintsTheKeyAsTheFactoryReceivedItRatherThanGuessingAnAuthoredSpelling(): void
    {
        $this->writeConfigFile([
            'coupling.instability' => ['class' => ['maxWarnign' => 1]],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "rules.coupling.instability.class.maxWarnign" in configuration file "/project/qmx.yaml" (did you mean "max-warning"?). Accepted keys: enabled, max-error, max-warning, min-afferent, threshold.');

        $this->factory->create('coupling.instability', InstabilityOptions::class);
    }

    #[Test]
    public function itLetsFrameworkDisablementCoexistWithTheOwningTypedMode(): void
    {
        $this->writeConfigFile([
            'architecture.unassigned-class' => ['enabled' => false, 'mode' => 'ignore'],
        ]);

        $options = $this->factory->create('architecture.unassigned-class', UnassignedClassOptions::class);
        self::assertInstanceOf(UnassignedClassOptions::class, $options);
        self::assertSame(\Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassMode::Ignore, $options->mode);
        self::assertNull($options->getSeverity(1));
    }

    #[Test]
    public function itRefusesExplicitEnablementOfTheDefaultMutedModeWithItsAuthoredWriter(): void
    {
        $this->writeConfigFile([
            'architecture.unassigned-class' => ['enabled' => true],
        ]);

        try {
            $this->factory->create('architecture.unassigned-class', UnassignedClassOptions::class);
            self::fail('An explicit enable must not silently run an ignored mode.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('"architecture.unassigned-class" is enabled by rules.architecture.unassigned-class.enabled: true (configuration file "/project/qmx.yaml") but its mode is ignore: mode of "architecture.unassigned-class" is ignore by default.', $refusal->getMessage());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
        }
    }

    // --- `threshold` vs `warning`/`error` mode conflicts across the
    // config-file -> CLI merge boundary ------------------------------------
    //
    // Regression coverage for the HIGH review finding: a naive deep-merge
    // let a higher-priority layer's `threshold` and a lower-priority
    // layer's `warning`/`error` survive into the same array handed to
    // Options::fromArray(), which ThresholdParser::parse() then rejected as
    // "cannot mix" — even though the CLI (or a preset, which arrives here
    // pre-merged into "config file options" the same way) clearly meant to
    // switch modes, not combine them. Cured by unfolding the `threshold`
    // shorthand into the graduated pair in EACH layer before they merge
    // (`RuleOptionThresholdShorthand`) — an earlier design evicted the
    // lower layer's stale keys instead, which only ever fixed this
    // direction; see the "reverse direction" tests below for the one it
    // could not. Reproduces both CLI repros from the review:
    // `--rule-opt=size.method-count:threshold=25` on top of a
    // preset/config-file `warning`/`error` pair.

    #[Test]
    public function itLetsACliThresholdOverrideConfigFileWarningAndError(): void
    {
        // Reproduces: qmx.yaml sets `warning`/`error`,
        // `--rule-opt=size.method-count:threshold=25` on top.
        $this->writeConfigFile([
            'size.method-count' => ['warning' => 10, 'error' => 20],
        ]);
        $this->writeCliOptions('size.method-count', ['threshold' => 25]);

        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertTrue($options->isEnabled());
        self::assertSame(25, $options->warning);
        self::assertSame(25, $options->error);
    }

    #[Test]
    public function itLetsACliThresholdOverridePresetSuppliedWarningAndError(): void
    {
        // Reproduces: --preset=strict sets `warning`/`error` for this rule
        // (arrives here as "config file options", since presets are merged
        // in before RuleOptionsFactory ever runs), and
        // `--rule-opt=size.method-count:threshold=25` on top.
        $this->writeConfigFile([
            'size.method-count' => ['warning' => 5, 'error' => 8],
        ]);
        $this->writeCliOptions('size.method-count', ['threshold' => 25]);

        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertSame(25, $options->warning);
        self::assertSame(25, $options->error);
    }

    #[Test]
    public function itLetsCliWarningAndErrorOverrideAConfigFileThreshold(): void
    {
        $this->writeConfigFile([
            'size.method-count' => ['threshold' => 25],
        ]);
        $this->writeCliOptions('size.method-count', ['warning' => 10, 'error' => 20]);

        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertSame(10, $options->warning);
        self::assertSame(20, $options->error);
    }

    #[Test]
    public function itScopesTheCliThresholdUnfoldingToItsOwnNestedLevel(): void
    {
        // Hierarchical rule (complexity.ccn): unfolding must be
        // scoped to the `callable:` nesting level, not the rule's top level.
        $this->writeConfigFile([
            'complexity.ccn' => [
                'callable' => ['warning' => 10, 'error' => 20],
                'class' => ['max_warning' => 30, 'max_error' => 50],
            ],
        ]);
        $this->writeCliOption('complexity.ccn', 'callable.threshold', 15);

        /** @var ComplexityOptions $options */
        $options = $this->factory->create('complexity.ccn', ComplexityOptions::class);

        self::assertSame(15, $options->callable->warning);
        self::assertSame(15, $options->callable->error);
        // Untouched sibling level keeps its own config-file values.
        self::assertSame(30, $options->class->maxWarning);
        self::assertSame(50, $options->class->maxError);
    }

    #[Test]
    public function itStillThrowsWhenThresholdAndWarningComeFromTheSameLayer(): void
    {
        // Both keys set by the SAME source (CLI) must still be reported as
        // a genuine configuration error — unfolding never touches a layer
        // that already carries a graduated key of the same group.
        $this->writeCliOptions('size.method-count', ['threshold' => 25, 'warning' => 10]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('The command line writes both "threshold" and "--rule-opt" in one layer; "threshold" is shorthand for "warning" and "error" — write either the shorthand or the full keys in one layer.');

        $this->factory->create('size.method-count', MethodCountOptions::class);
    }

    #[Test]
    public function itLeavesAnUnrelatedVoGroupUntouchedByACliThresholdOverride(): void
    {
        // code-smell.long-parameter-list has two independent dimensions:
        // bare warning/error/threshold, and the vo-prefixed variant.
        // A CLI override of one group must not unfold the other.
        $this->writeConfigFile([
            'code-smell.long-parameter-list' => ['warning' => 4, 'error' => 6, 'voWarning' => 8, 'voError' => 12],
        ]);
        $this->writeCliOptions('code-smell.long-parameter-list', ['threshold' => 5]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertSame(5, $options->warning);
        self::assertSame(5, $options->error);
        // vo-* dimension untouched by the unrelated bare `threshold` override.
        self::assertSame(8, $options->voWarning);
        self::assertSame(12, $options->voError);
    }

    // --- Prefixed graduated keys (max_distance_warning/max_distance_error
    // vs. a bare `threshold`) — coordinator-reported gap in the heuristic
    // fallback, now closed via RuleThresholdKeyGroupRegistry ----------------
    //
    // Reproduces: qmx.yaml sets `max_distance_warning`/`max_distance_error`
    // for coupling.distance, `--rule-opt=coupling.distance:threshold=0.5` on
    // top. Before the registry, the heuristic required the threshold key's
    // prefix to match the graduated keys' prefix ('' vs 'maxDistance') and
    // never evicted — this is exactly the same "cannot mix" failure as the
    // HIGH finding, just for a rule whose graduated keys aren't the bare
    // `warning`/`error` spelling.

    #[Test]
    public function itLetsACliThresholdOverrideConfigFilePrefixedGraduatedKeys(): void
    {
        $this->writeConfigFile([
            'coupling.distance' => ['max_distance_warning' => 0.4, 'max_distance_error' => 0.6],
        ]);
        $this->writeCliOptions('coupling.distance', ['threshold' => 0.5]);

        /** @var DistanceOptions $options */
        $options = $this->factory->create('coupling.distance', DistanceOptions::class);

        self::assertSame(0.5, $options->maxDistanceWarning);
        self::assertSame(0.5, $options->maxDistanceError);
    }

    #[Test]
    public function itLetsCliPrefixedGraduatedKeysOverrideAConfigFileThreshold(): void
    {
        // Symmetric direction: config file sets the bare `threshold`
        // shorthand, CLI switches to the prefixed graduated pair.
        $this->writeConfigFile([
            'coupling.distance' => ['threshold' => 0.5],
        ]);
        $this->writeCliOptions('coupling.distance', [
            'maxDistanceWarning' => 0.4,
            'maxDistanceError' => 0.6,
        ]);

        /** @var DistanceOptions $options */
        $options = $this->factory->create('coupling.distance', DistanceOptions::class);

        self::assertSame(0.4, $options->maxDistanceWarning);
        self::assertSame(0.6, $options->maxDistanceError);
    }

    #[Test]
    public function itStillThrowsWhenThresholdAndPrefixedGraduatedKeysComeFromTheSameLayer(): void
    {
        // Both keys set by the SAME source (CLI) for a prefixed group must
        // still be a genuine configuration error.
        $this->writeCliOptions('coupling.distance', [
            'threshold' => 0.5,
            'maxDistanceWarning' => 0.4,
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('The command line writes both "threshold" and "--rule-opt" in one layer; "threshold" is shorthand for "max-distance-warning" and "max-distance-error" — write either the shorthand or the full keys in one layer.');

        $this->factory->create('coupling.distance', DistanceOptions::class);
    }

    /**
     * Regression for condition 5 of `RuleOptionThresholdShorthand::unfold()`
     * relying on an unproven invariant ("every door folds separators away
     * before either merge site runs"): the config file writes the class
     * level's graduated pair in the PRIMARY spelling `ThresholdParser::parse()`
     * itself is called with (`max_warning`/`max_error`), and the CLI writes a
     * `threshold` shorthand at the same level, unfolding to the FOLDED
     * spelling (`maxWarning`/`maxError`). Before this method folded a
     * group's own graduated keys unconditionally, this left both spellings
     * alive side by side after the merge, and `ThresholdParser::candidateKeys()`'s
     * primary-before-legacy order let the config file's stale `max_warning`
     * win over the CLI's `threshold` — the higher-priority layer silently lost.
     */
    #[Test]
    public function itFoldsAnUnnormalizedGraduatedKeyBeforeMergingAgainstAnUnfoldedOverlay(): void
    {
        $this->writeConfigFile([
            'coupling.instability' => [
                'class' => ['max_warning' => 0.7, 'max_error' => 0.9],
            ],
        ]);
        $this->writeCliOption('coupling.instability', 'class.threshold', 0.85);

        /** @var InstabilityOptions $options */
        $options = $this->factory->create('coupling.instability', InstabilityOptions::class);

        self::assertSame(0.85, $options->class->maxWarning);
        self::assertSame(0.85, $options->class->maxError);
    }

    #[Test]
    public function itScopesThePrefixedGraduatedKeyUnfoldingToItsOwnNestedLevel(): void
    {
        // Hierarchical rule with a prefix-mismatched nested level:
        // coupling.instability's `class:` dimension uses maxWarning/
        // maxError paired with a bare `threshold` — unfolding must be
        // scoped to the `class:` level.
        //
        // Nested keys are written camelCase here, as every real door
        // (YamlConfigLoader's recursive fold for the `rules:` section, the
        // `--rule-opt` parser) already produces by the time this array
        // reaches the factory — unfolding writes the SAME folded spelling
        // (`RuleOptionThresholdShorthand`'s condition 5), so a hand-built
        // fixture using an unfolded snake_case spelling here would not be
        // reachable through any real door and would wrongly appear to leave
        // two keys (`max_warning` and `maxWarning`) alive side by side.
        $this->writeConfigFile([
            'coupling.instability' => [
                'class' => ['maxWarning' => 0.8, 'maxError' => 0.95],
                'namespace' => ['maxWarning' => 0.7, 'maxError' => 0.9],
            ],
        ]);
        $this->writeCliOption('coupling.instability', 'class.threshold', 0.85);

        /** @var InstabilityOptions $options */
        $options = $this->factory->create('coupling.instability', InstabilityOptions::class);

        self::assertSame(0.85, $options->class->maxWarning);
        self::assertSame(0.85, $options->class->maxError);
        // Untouched sibling level keeps its own config-file values.
        self::assertSame(0.7, $options->namespace->maxWarning);
        self::assertSame(0.9, $options->namespace->maxError);
    }

    // --- Threshold shorthand across both option layers, and the completeness of the
    // scalar-form guard -------------------------------------------------
    //
    // Through the real hierarchical Options class, combine a config-file
    // `threshold` shorthand on a nested level with a CLI override of only ONE
    // half of the graduated pair.
    // Eviction only ever rewrote the config-file (lower) layer, so this
    // exact shape — the higher layer contributing only half the band — used
    // to survive with the untouched half falling to the constructor default
    // (10/20) instead of the config file's 5. Both halves are asserted, per
    // Both halves are asserted so the untouched value cannot fall back to a
    // constructor default unnoticed.

    #[Test]
    public function itAppliesBothHalvesWhenTheCliOnlyOverridesOneHalfOfAConfigFileThreshold(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => ['callable' => ['threshold' => 5]],
        ]);
        $this->writeCliOption('complexity.ccn', 'callable.warning', 2);

        /** @var ComplexityOptions $options */
        $options = $this->factory->create('complexity.ccn', ComplexityOptions::class);

        self::assertSame(2, $options->callable->warning);
        self::assertSame(5, $options->callable->error);
    }

    // The mirror question — does an overlay's `~` erase a value the lower
    // layer wrote — is not asked at this merge site: $override here is
    // always the CLI layer (see RuleOptionsFactory::create()), and no CLI
    // door (`--rule-opt`, a short alias) can ever produce a bare `null` —
    // both refuse an empty value before RuleOptionsFactory ever sees it. The
    // question only has a real door on the OTHER merge site, where a YAML
    // `~` genuinely reaches a layer this way — see
    // FindingConfigurationResolverTest::itKeepsBothHalvesOfTheBandWhenTheOverlaysNullTargetsAnUnfoldedHalf().

    /**
     * Condition 2 of `RuleOptionThresholdShorthand::unfold()`: a `threshold`
     * whose value is not the group's declared scalar form must be left under
     * its own name. `size.method-count`'s group declares `integer()` (whole
     * number only, matching `RuleOptionShape::integer()` in
     * `MethodCountOptions::acceptedOptionKeys()`); `10.5` unfolded would
     * reach the recognition seam as `warning: 10.5` and refuse the author
     * for a key they never wrote. This is the same defect class as
     * `threshold: abc`, for a value that merely has the wrong NUMBER form
     * rather than the wrong TYPE.
     */
    #[Test]
    public function itRefusesAFractionalThresholdNamingThresholdRatherThanWarning(): void
    {
        $this->writeConfigFile([
            'size.method-count' => ['threshold' => 10.5],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.size.method-count.threshold" in configuration file "/project/qmx.yaml" must be integer at least 0, got float.');

        $this->factory->create('size.method-count', MethodCountOptions::class);
    }

    /**
     * Each pair below answers one measured form of the same defect — a value
     * of a form the rule cannot use, taken silently — and each is followed by
     * the shape of value that must keep working, so that the cure cannot be
     * mistaken for a blanket refusal.
     */
    #[Test]
    public function itRefusesAListWhereABooleanSwitchWasDeclared(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => ['enabled' => [7331]],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.complexity.ccn.enabled" in configuration file "/project/qmx.yaml" must be boolean, got a list.');

        $this->factory->create('complexity.ccn', ComplexityOptions::class);
    }

    #[Test]
    public function itStillSwitchesARuleOffWithAnExplicitBoolean(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => ['enabled' => false],
        ]);

        /** @var ComplexityOptions $options */
        $options = $this->factory->create('complexity.ccn', ComplexityOptions::class);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itRefusesAWholeNumberWhereAPathPatternWasDeclared(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => ['suppress_paths' => 7331],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.complexity.ccn.suppress_paths" in configuration file "/project/qmx.yaml" must be a list, got int.');

        $this->factory->create('complexity.ccn', ComplexityOptions::class);
    }

    #[Test]
    public function itRefusesBarePathStringsInsteadOfCoercingThem(): void
    {
        $this->writeConfigFile([
            'one.rule' => ['suppress_paths' => 'src/Generated'],
        ]);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.one.rule.suppress_paths" in configuration file "/project/qmx.yaml" must be a list, got string.');

        $this->factory->create('one.rule', TestRuleOptions::class);
    }

    #[Test]
    public function itRefusesAWholeNumberWhereANamespacePatternWasDeclared(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => ['suppress_namespaces' => 7331],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.complexity.ccn.suppress_namespaces" in configuration file "/project/qmx.yaml" must be a list, got int.');

        $this->factory->create('complexity.ccn', ComplexityOptions::class);
    }

    #[Test]
    public function itRefusesAWholeNumberWhereASeverityWordWasDeclared(): void
    {
        $this->writeConfigFile([
            'architecture.layer-violation' => ['severity' => 7331],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.architecture.layer-violation.severity" in configuration file "/project/qmx.yaml" must be string (one of info, warning, error, case-insensitive), got int.');

        $this->factory->create('architecture.layer-violation', LayerViolationOptions::class);
    }

    #[Test]
    public function itStillTakesASeverityWord(): void
    {
        $this->writeConfigFile([
            'architecture.layer-violation' => ['severity' => 'error'],
        ]);

        /** @var LayerViolationOptions $options */
        $options = $this->factory->create('architecture.layer-violation', LayerViolationOptions::class);

        self::assertSame(Severity::Error, $options->severity);
    }

    #[Test]
    public function itRefusesAWronglyShapedValueInsideALevelSlot(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => ['callable' => ['warning' => 'ten']],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.complexity.ccn.callable.warning" in configuration file "/project/qmx.yaml" must be integer at least 0, got string.');

        $this->factory->create('complexity.ccn', ComplexityOptions::class);
    }

    #[Test]
    public function itStillTakesAWholeNumberInsideALevelSlot(): void
    {
        $this->writeConfigFile([
            'complexity.ccn' => ['callable' => ['warning' => 7, 'error' => 9]],
        ]);

        /** @var ComplexityOptions $options */
        $options = $this->factory->create('complexity.ccn', ComplexityOptions::class);

        self::assertSame(7, $options->callable->warning);
        self::assertSame(9, $options->callable->error);
    }

    /**
     * The half of {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet}
     * that carries no form: a key the class recognises only in order to
     * answer about it must still reach that answer, not a sentence about its
     * form and not the generic unknown-key one.
     */
    #[Test]
    public function itLeavesAKeyTheClassAnswersAboutToItsOwnWords(): void
    {
        $this->writeConfigFile([
            'architecture.layer-violation' => ['unreachable_layer_severity' => 7331],
        ]);

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('no longer exists');

        $this->factory->create('architecture.layer-violation', LayerViolationOptions::class);
    }

    // -- the door ---------------------------------------------------------------
    //
    // Every write goes through `replace()`, the one door the product configures
    // the registry by, with the whole configuration written so far: a suite
    // built on per-field setters would test the merge against a state shape the
    // product never builds.

    /** @param array<string, mixed> $rules */
    private function writeConfigFile(array $rules): void
    {
        $this->configFileRules = $rules;
        $this->install();
    }

    private function writeCliOption(string $ruleName, string $option, mixed $value): void
    {
        $this->cliRules[$ruleName][$option] = $value;
        $this->install();
    }

    /** @param array<string, mixed> $options */
    private function writeCliOptions(string $ruleName, array $options): void
    {
        $this->cliRules[$ruleName] = $options;
        $this->install();
    }

    private function resetRun(): void
    {
        $this->configFileRules = [];
        $this->cliRules = [];
        $this->factory->inputs([]);
        $this->registry->resetRuntimeState();
    }

    private function assertUnsupportedCliWrite(string $producer, string $key, mixed $value, string $summary): void
    {
        $parser = (new RuleOptionsParserFactory())->createFromMetadata([
            new RuleMetadata($producer, TestRuleOptions::class, '', [], false),
        ]);
        $input = new ArrayInput(['--rule-opt' => [$producer . ':' . $key . '=' . json_encode($value, \JSON_THROW_ON_ERROR)]], new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]));
        try {
            (new CliOptionsParser(new RuleOptionDocumentForms(), $parser))->pathWrites($input);
            self::fail('The authored CLI key must be refused before installing a ready snapshot.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame($summary, $refusal->summary());
            self::assertSame(ConfigurationSource::CommandLine, $refusal->sources()[0]->source());
            self::assertSame('--rule-opt', $refusal->sources()[0]->locator());
            self::assertNull($refusal->position());
        }
        $this->assertNoReadySnapshot();
    }

    /** @param array<string, mixed> $options */
    private function assertUnsupportedFileWrite(string $producer, array $options, string $key, string $summary): void
    {
        try {
            ResolvedOptionsFixture::authoredConfiguration(['rules' => [$producer => $options]], [
                new RuleMetadata($producer, TestRuleOptions::class, '', [], false),
            ]);
            self::fail('The authored file key must be refused before installing a ready snapshot.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame($summary, $refusal->summary());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame('/project/qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['rules', $producer, $key], $refusal->position()?->segments);
            self::assertSame($key, $refusal->position()->written);
        }
        $this->assertNoReadySnapshot();
    }

    private function assertNoReadySnapshot(): void
    {
        try {
            $this->registry->resolvedOptions();
            self::fail('An invalid ingress must not install a ready snapshot.');
        } catch (LogicException $refusal) {
            self::assertSame('Rule options are unavailable before analysis preflight.', $refusal->getMessage());
        }
    }

    private function install(): void
    {
        $this->factory->inputs(['rules' => $this->configFileRules], $this->cliRules);
    }
}
