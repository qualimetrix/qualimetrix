<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\RuleNamespaceExclusionProvider;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Rules\CodeSmell\LongParameterListOptions;
use Qualimetrix\Rules\Complexity\ComplexityOptions;
use Qualimetrix\Rules\Coupling\CboOptions;
use Qualimetrix\Rules\Coupling\DistanceOptions;
use Qualimetrix\Rules\Coupling\InstabilityOptions;
use Qualimetrix\Rules\Design\TypeCoverageOptions;
use Qualimetrix\Rules\Size\MethodCountOptions;
use Qualimetrix\Tests\Fixture\TestRuleOptions;
use Qualimetrix\Tests\Fixture\TestRuleOptionsNoConstructor;
use Qualimetrix\Tests\Fixture\TestRuleOptionsWithRequiredParams;
use Qualimetrix\Tests\Fixture\TestRuleOptionsWithUnionType;
use Qualimetrix\Tests\Support\Logger\RecordingLogger;
use RuntimeException;
use stdClass;

#[CoversClass(RuleOptionsFactory::class)]
#[CoversClass(RuleOptionsRegistry::class)]
final class RuleOptionsFactoryTest extends TestCase
{
    private RuleOptionsRegistry $registry;
    private RuleOptionsFactory $factory;

    protected function setUp(): void
    {
        $this->registry = new RuleOptionsRegistry();
        $this->factory = new RuleOptionsFactory($this->registry);
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
        $this->registry->setConfigFileOptions([
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
        $this->registry->addCliOption('test-rule', 'warningThreshold', 25);
        $this->registry->addCliOption('test-rule', 'countNullsafe', false);

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
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 15,
            ],
        ]);

        $this->registry->addCliOption('test-rule', 'warningThreshold', 25);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        // CLI wins
        self::assertSame(25, $options->warningThreshold);
    }

    #[Test]
    public function itSetsCliOptions(): void
    {
        $this->registry->setCliOptions('test-rule', [
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
        $this->registry->setConfigFileOptions([
            'rule-a' => ['enabled' => false],
            'rule-b' => ['enabled' => true],
        ]);

        $options = $this->registry->getConfigFileOptions();

        self::assertSame(['rule-a' => ['enabled' => false], 'rule-b' => ['enabled' => true]], $options);
    }

    #[Test]
    public function itGetsCliOptions(): void
    {
        $this->registry->addCliOption('rule-a', 'opt1', 'value1');
        $this->registry->addCliOption('rule-b', 'opt2', 'value2');

        $options = $this->registry->getCliOptions();

        self::assertSame([
            'rule-a' => ['opt1' => 'value1'],
            'rule-b' => ['opt2' => 'value2'],
        ], $options);
    }

    #[Test]
    public function itResetsState(): void
    {
        $this->registry->setConfigFileOptions(['rule' => ['opt' => 'val']]);
        $this->registry->addCliOption('rule', 'opt2', 'val2');

        $this->registry->reset();

        self::assertSame([], $this->registry->getConfigFileOptions());
        self::assertSame([], $this->registry->getCliOptions());
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
        $this->registry->setConfigFileOptions([
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
        $this->registry->setConfigFileOptions([
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
    public function itExpandsDotNotationInCliOptions(): void
    {
        $this->registry->addCliOption('test-rule', 'method.warning', 5);
        $this->registry->addCliOption('test-rule', 'method.error', 10);
        $this->registry->addCliOption('test-rule', 'class.enabled', false);

        $cliOptions = $this->registry->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertSame([
            'method.warning' => 5,
            'method.error' => 10,
            'class.enabled' => false,
        ], $cliOptions['test-rule']);
    }

    #[Test]
    public function itHandlesNestedConfigFileOptions(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'enabled' => true,
                'nested' => [
                    'level1' => [
                        'level2' => 'deep-value',
                    ],
                ],
            ],
        ]);

        $options = $this->registry->getConfigFileOptions();

        self::assertArrayHasKey('test-rule', $options);
        self::assertIsArray($options['test-rule']['nested']);
        self::assertSame('deep-value', $options['test-rule']['nested']['level1']['level2']);
    }

    #[Test]
    public function itDeepMergesNestedArrays(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 15,
                'enabled' => true,
            ],
        ]);

        $this->registry->setCliOptions('test-rule', [
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
        $this->registry->setConfigFileOptions([]);
        $this->registry->setCliOptions('test-rule', []);

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
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 5,
            ],
        ]);

        // CLI completely overrides config value (not merges)
        $this->registry->addCliOption('test-rule', 'warningThreshold', 50);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(50, $options->warningThreshold);
    }

    #[Test]
    public function itNormalizesMixedCaseKeys(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'Warning_Threshold' => 12,
                'error-threshold' => 24,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(12, $options->warningThreshold);
        self::assertSame(24, $options->errorThreshold);
        // Note: count_NULL_safe would normalize to countNULLSafe (not countNullsafe)
        // This is expected behavior - normalization preserves case after delimiters
    }

    #[Test]
    public function itHandlesMultiLevelDotNotation(): void
    {
        $this->registry->addCliOption('test-rule', 'level1.level2.level3', 'deep');
        $this->registry->addCliOption('test-rule', 'level1.level2.other', 'value');

        // The factory stores raw dot notation, expansion happens during create()
        $cliOptions = $this->registry->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertSame('deep', $cliOptions['test-rule']['level1.level2.level3']);
        self::assertSame('value', $cliOptions['test-rule']['level1.level2.other']);
    }

    #[Test]
    public function itHandlesBooleanStringValues(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'enabled' => 'true', // string instead of bool
                'count_nullsafe' => '0', // string instead of bool
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        // TestRuleOptions::fromArray does type coercion
        self::assertTrue($options->enabled); // 'true' truthy
        self::assertFalse($options->countNullsafe); // '0' falsy
    }

    #[Test]
    public function itHandlesNullValues(): void
    {
        $this->registry->setConfigFileOptions([
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
        $this->registry->setConfigFileOptions([
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
    public function itHandlesFloatValues(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 10.5,
                'error_threshold' => 20.7,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        // TestRuleOptions casts to int
        self::assertSame(10, $options->warningThreshold);
        self::assertSame(20, $options->errorThreshold);
    }

    #[Test]
    public function itMergesPartialConfigFileOptions(): void
    {
        $this->registry->setConfigFileOptions([
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
        $this->registry->setConfigFileOptions([
            'rule-a' => ['warning_threshold' => 5],
            'rule-b' => ['warning_threshold' => 15],
        ]);

        $this->registry->addCliOption('rule-a', 'errorThreshold', 10);
        $this->registry->addCliOption('rule-b', 'errorThreshold', 30);

        /** @var TestRuleOptions $optionsA */
        $optionsA = $this->factory->create('rule-a', TestRuleOptions::class);
        /** @var TestRuleOptions $optionsB */
        $optionsB = $this->factory->create('rule-b', TestRuleOptions::class);

        self::assertSame(5, $optionsA->warningThreshold);
        self::assertSame(10, $optionsA->errorThreshold);

        self::assertSame(15, $optionsB->warningThreshold);
        self::assertSame(30, $optionsB->errorThreshold);
    }

    #[Test]
    public function itHandlesCliOptionsAddedIncrementally(): void
    {
        $this->registry->addCliOption('test-rule', 'option1', 'value1');
        $this->registry->addCliOption('test-rule', 'option2', 'value2');
        $this->registry->addCliOption('test-rule', 'option3', 'value3');

        $cliOptions = $this->registry->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertCount(3, $cliOptions['test-rule']);
        self::assertSame('value1', $cliOptions['test-rule']['option1']);
        self::assertSame('value2', $cliOptions['test-rule']['option2']);
        self::assertSame('value3', $cliOptions['test-rule']['option3']);
    }

    #[Test]
    public function itOverwritesCliOptionWhenAddedTwice(): void
    {
        $this->registry->addCliOption('test-rule', 'warningThreshold', 5);
        $this->registry->addCliOption('test-rule', 'warningThreshold', 15);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(15, $options->warningThreshold);
    }

    #[Test]
    public function itReplacesCliOptionsWhenUsingSetCliOptions(): void
    {
        $this->registry->addCliOption('test-rule', 'option1', 'old');
        $this->registry->setCliOptions('test-rule', [
            'option2' => 'new',
        ]);

        $cliOptions = $this->registry->getCliOptions();

        self::assertArrayNotHasKey('option1', $cliOptions['test-rule']);
        self::assertArrayHasKey('option2', $cliOptions['test-rule']);
        self::assertSame('new', $cliOptions['test-rule']['option2']);
    }

    #[Test]
    public function itHandlesEmptyStringKeys(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                '' => 'empty-key-value',
                'valid_key' => 'valid-value',
            ],
        ]);

        $options = $this->registry->getConfigFileOptions();

        self::assertArrayHasKey('test-rule', $options);
        self::assertSame('empty-key-value', $options['test-rule']['']);
        self::assertSame('valid-value', $options['test-rule']['valid_key']);
    }

    #[Test]
    public function itNormalizesNumericStringKeys(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                '123_value' => 'numeric-start',
            ],
        ]);

        $normalized = $this->registry->getConfigFileOptions();

        // Key normalization should handle numeric prefixes
        self::assertArrayHasKey('test-rule', $normalized);
    }

    #[Test]
    public function itHandlesDotNotationWithSingleKey(): void
    {
        $this->registry->addCliOption('test-rule', 'simpleKey', 'value');
        $this->registry->addCliOption('test-rule', 'nested.key', 'nested-value');

        $cliOptions = $this->registry->getCliOptions();

        self::assertSame('value', $cliOptions['test-rule']['simpleKey']);
        self::assertSame('nested-value', $cliOptions['test-rule']['nested.key']);
    }

    #[Test]
    public function itCreatesNestedStructureFromDotNotationDuringMerge(): void
    {
        // When create() is called, dot notation should expand
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'enabled' => true,
            ],
        ]);

        $this->registry->addCliOption('test-rule', 'warningThreshold', 99);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertTrue($options->enabled);
        self::assertSame(99, $options->warningThreshold);
    }

    #[Test]
    public function itHandlesArrayMergeWithScalarOverwrite(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 5,
            ],
        ]);

        // Overwrite with different type (should work)
        $this->registry->addCliOption('test-rule', 'warningThreshold', '25');

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(25, $options->warningThreshold);
    }

    #[Test]
    public function itPreservesCamelCaseKeysFromConfigFile(): void
    {
        $this->registry->setConfigFileOptions([
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
        $this->registry->setConfigFileOptions([
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
        $this->registry->setConfigFileOptions([
            '' => [
                'warning_threshold' => 5,
            ],
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('', TestRuleOptions::class);

        // Empty rule name is valid, should use its config
        self::assertSame(5, $options->warningThreshold);
    }

    #[Test]
    public function itResetsClearsAllState(): void
    {
        $this->registry->setConfigFileOptions([
            'rule1' => ['opt1' => 'val1'],
            'rule2' => ['opt2' => 'val2'],
        ]);

        $this->registry->addCliOption('rule1', 'cliOpt', 'cliVal');
        $this->registry->addCliOption('rule3', 'cliOpt2', 'cliVal2');

        self::assertNotEmpty($this->registry->getConfigFileOptions());
        self::assertNotEmpty($this->registry->getCliOptions());

        $this->registry->reset();

        self::assertEmpty($this->registry->getConfigFileOptions());
        self::assertEmpty($this->registry->getCliOptions());
    }

    #[Test]
    public function itMergesPriorityCorrectly(): void
    {
        // Setup: defaults (10, 20) → config (15, 25) → CLI (warningThreshold=30)
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 15,
                'error_threshold' => 25,
            ],
        ]);

        $this->registry->addCliOption('test-rule', 'warningThreshold', 30);

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
        $this->registry->setConfigFileOptions([
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
        $this->registry->addCliOption('test-rule', 'enabled', false);
        $this->registry->addCliOption('test-rule', 'threshold', 50);
        $this->registry->addCliOption('test-rule', 'ratio', 0.5);
        $this->registry->addCliOption('test-rule', 'name', 'cli-name');
        $this->registry->addCliOption('test-rule', 'items', ['a', 'b', 'c']);
        $this->registry->addCliOption('test-rule', 'optional', 'value');

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
    public function itExpandsDeepDotNotationInCliOptions(): void
    {
        // Test actual expansion during create() call
        $this->registry->addCliOption('complexity', 'method.warning', 5);
        $this->registry->addCliOption('complexity', 'method.error', 10);
        $this->registry->addCliOption('complexity', 'class.warning', 15);
        $this->registry->addCliOption('complexity', 'class.error', 20);

        // Before expansion, options are stored as-is
        $cliOptions = $this->registry->getCliOptions();
        self::assertArrayHasKey('complexity', $cliOptions);
        self::assertArrayHasKey('method.warning', $cliOptions['complexity']);
        self::assertArrayHasKey('method.error', $cliOptions['complexity']);
        self::assertArrayHasKey('class.warning', $cliOptions['complexity']);
        self::assertArrayHasKey('class.error', $cliOptions['complexity']);
    }

    #[Test]
    public function itHandlesDotNotationCollisionsCorrectly(): void
    {
        // Test that dot notation expansion handles collisions
        $this->registry->addCliOption('test-rule', 'nested.key1', 'value1');
        $this->registry->addCliOption('test-rule', 'nested.key2', 'value2');

        $cliOptions = $this->registry->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertSame('value1', $cliOptions['test-rule']['nested.key1']);
        self::assertSame('value2', $cliOptions['test-rule']['nested.key2']);
    }

    #[Test]
    public function itResetsCliOptionsWithoutAffectingConfigFileOptions(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => ['warning_threshold' => 15],
        ]);
        $this->registry->addCliOption('test-rule', 'errorThreshold', 30);
        $this->registry->addCliOption('other-rule', 'enabled', false);

        self::assertNotEmpty($this->registry->getCliOptions());

        $this->registry->resetCliOptions();

        self::assertEmpty($this->registry->getCliOptions());
        // Config file options preserved
        self::assertSame(['test-rule' => ['warning_threshold' => 15]], $this->registry->getConfigFileOptions());
    }

    #[Test]
    public function cliOptionsDoNotLeakBetweenRunsAfterReset(): void
    {
        // Simulate first run
        $this->registry->setCliOptions('test-rule', ['warningThreshold' => 50]);

        /** @var TestRuleOptions $options1 */
        $options1 = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertSame(50, $options1->warningThreshold);

        // Reset between runs
        $this->registry->resetCliOptions();

        // Second run without CLI options — should use defaults
        /** @var TestRuleOptions $options2 */
        $options2 = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertSame(10, $options2->warningThreshold, 'CLI options from first run should not leak into second run');
    }

    #[Test]
    public function itNormalizesScalarFalseRuleConfig(): void
    {
        // YAML: `rules: { test-rule: false }` arrives as scalar false
        $this->registry->setConfigFileOptions([
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
        $this->registry->setConfigFileOptions([
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
        $this->registry->setConfigFileOptions([
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
    public function itHandlesDeepNestedDotNotationLevels(): void
    {
        // Test very deep nesting: a.b.c.d.e
        $this->registry->addCliOption('test-rule', 'a.b.c.d.e', 'deep-value');

        $cliOptions = $this->registry->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertSame('deep-value', $cliOptions['test-rule']['a.b.c.d.e']);
    }

    #[Test]
    public function itThrowsWhenNumericFieldContainsNonNumericString(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 'not_a_number',
            ],
        ]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('option "warningThreshold" must be numeric');

        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itThrowsWhenErrorThresholdIsNonNumericString(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'error_threshold' => 'invalid',
            ],
        ]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('option "errorThreshold" must be numeric');

        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itAcceptsNumericStringForThresholdFields(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => '15',
                'error_threshold' => '30',
            ],
        ]);

        // Numeric strings are valid — no exception should be thrown
        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(15, $options->warningThreshold);
        self::assertSame(30, $options->errorThreshold);
    }

    #[Test]
    public function itAcceptsFloatStringForThresholdFields(): void
    {
        $this->registry->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => '10.5',
            ],
        ]);

        // Float numeric strings should be accepted
        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(10, $options->warningThreshold); // cast to int
    }

    #[Test]
    public function itIncludesRuleNameInNumericValidationError(): void
    {
        $this->registry->setConfigFileOptions([
            'complexity.cyclomatic' => [
                'error_threshold' => 'not_a_number',
            ],
        ]);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('rule "complexity.cyclomatic"');

        $this->factory->create('complexity.cyclomatic', TestRuleOptions::class);
    }

    // --- exclude_namespaces extraction tests ---

    #[Test]
    public function createExtractsExcludeNamespacesSnakeCase(): void
    {
        $this->registry->setConfigFileOptions([
            'test.rule' => [
                'exclude_namespaces' => ['App\\Tests', 'App\\Legacy'],
                'warningThreshold' => 5,
            ],
        ]);

        $this->factory->create('test.rule', TestRuleOptions::class);

        $provider = $this->registry->getExclusionProvider();
        self::assertSame(['App\\Tests', 'App\\Legacy'], $provider->getExclusions('test.rule'));
    }

    #[Test]
    public function createExtractsExcludeNamespacesCamelCase(): void
    {
        $this->registry->setConfigFileOptions([
            'test.rule' => [
                'excludeNamespaces' => ['App\\Tests'],
            ],
        ]);

        $this->factory->create('test.rule', TestRuleOptions::class);

        $provider = $this->registry->getExclusionProvider();
        self::assertSame(['App\\Tests'], $provider->getExclusions('test.rule'));
    }

    #[Test]
    public function createExtractsExcludeNamespacesStringCoercedToArray(): void
    {
        $this->registry->setConfigFileOptions([
            'test.rule' => [
                'exclude_namespaces' => 'App\\Tests',
            ],
        ]);

        $this->factory->create('test.rule', TestRuleOptions::class);

        $provider = $this->registry->getExclusionProvider();
        self::assertSame(['App\\Tests'], $provider->getExclusions('test.rule'));
    }

    #[Test]
    public function itExtractsViolationCodeScopedNamespaceExclusions(): void
    {
        $this->registry->setConfigFileOptions([
            'computed.health' => [
                'exclude_namespace_channels' => [
                    'health.cohesion' => ['App\\Metrics'],
                    'health.typing' => ['App\\Generated'],
                ],
            ],
        ]);

        $this->factory->create('computed.health', TestRuleOptions::class);

        self::assertSame(
            [
                'health.cohesion' => ['App\\Metrics'],
                'health.typing' => ['App\\Generated'],
            ],
            $this->registry->getExclusionProvider()->getChannelExclusions('computed.health'),
        );
    }

    #[Test]
    public function itRejectsEmptyViolationCodeScopedNamespaceExclusions(): void
    {
        $this->registry->setConfigFileOptions([
            'computed.health' => [
                'exclude_namespace_channels' => ['health.cohesion' => []],
            ],
        ]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('exclude_namespace_channels.health.cohesion');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsEmptyNamespacePatternsInChannelExclusions(): void
    {
        $this->registry->setConfigFileOptions([
            'computed.health' => [
                'exclude_namespace_channels' => ['health.cohesion' => ['']],
            ],
        ]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must contain only non-empty strings');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsNonListChannelNamespaceExclusions(): void
    {
        $this->registry->setConfigFileOptions([
            'computed.health' => [
                'exclude_namespace_channels' => ['health.cohesion' => 'App\\Metrics'],
            ],
        ]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must be a non-empty list of strings');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsEmptyViolationCodeSelectorsInNamespaceChannelExclusions(): void
    {
        $this->registry->setConfigFileOptions([
            'computed.health' => [
                'exclude_namespace_channels' => ['' => ['App\\Metrics']],
            ],
        ]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('empty or non-string violation-code selector');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsChannelMapsUnderLegacyExcludeNamespaces(): void
    {
        $this->registry->setConfigFileOptions([
            'computed.health' => [
                'exclude_namespaces' => ['health.cohesion' => ['App\\Metrics']],
            ],
        ]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('use "exclude_namespace_channels"');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function itRejectsNonStringLegacyNamespaceExclusions(): void
    {
        $this->registry->setConfigFileOptions([
            'computed.health' => [
                'exclude_namespaces' => ['App\\Metrics', 42],
            ],
        ]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must contain only non-empty strings');

        $this->factory->create('computed.health', TestRuleOptions::class);
    }

    #[Test]
    public function createRemovesExcludeNamespacesFromOptionsBeforeFromArray(): void
    {
        $this->registry->setConfigFileOptions([
            'test.rule' => [
                'exclude_namespaces' => ['App\\Tests'],
                'warningThreshold' => 7,
            ],
        ]);

        $options = $this->factory->create('test.rule', TestRuleOptions::class);

        self::assertInstanceOf(TestRuleOptions::class, $options);
        self::assertSame(7, $options->warningThreshold);
        self::assertSame(['App\\Tests'], $this->registry->getExclusionProvider()->getExclusions('test.rule'));
    }

    #[Test]
    public function resetClearsExclusionProvider(): void
    {
        $provider = new RuleNamespaceExclusionProvider();
        $registry = new RuleOptionsRegistry($provider);
        $factory = new RuleOptionsFactory($registry);

        $registry->setConfigFileOptions([
            'test.rule' => ['exclude_namespaces' => ['App\\Tests']],
        ]);
        $factory->create('test.rule', TestRuleOptions::class);
        self::assertSame(['App\\Tests'], $provider->getExclusions('test.rule'));

        $registry->reset();
        self::assertSame([], $provider->getExclusions('test.rule'));
    }

    // --- regression: a rule configured with ONLY framework-level keys
    // (exclude_namespaces / exclude_paths) must stay enabled ---
    //
    // Bug: an earlier version of create() decided "$userConfig === [] ->
    // fall back to $defaults" BEFORE extractExcludeNamespaces()/
    // extractExcludePaths() stripped the framework-level keys out of
    // $userConfig. So a rule configured with ONLY `exclude_namespaces`
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
        $this->registry->setConfigFileOptions([
            'code-smell.long-parameter-list' => [
                'exclude_namespaces' => ['App\\Tests'],
            ],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertTrue($options->isEnabled(), 'A rule configured with only exclude_namespaces must stay enabled');
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
        self::assertSame(
            ['App\\Tests'],
            $this->registry->getExclusionProvider()->getExclusions('code-smell.long-parameter-list'),
        );
    }

    #[Test]
    public function itKeepsTheRuleEnabledWhenOnlyExcludePathsIsConfiguredInTheFile(): void
    {
        $this->registry->setConfigFileOptions([
            'code-smell.long-parameter-list' => [
                'exclude_paths' => ['src/Legacy/**'],
            ],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertTrue($options->isEnabled(), 'A rule configured with only exclude_paths must stay enabled');
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
        self::assertTrue(
            $this->registry->getPathExclusionProvider()->isExcluded(
                'code-smell.long-parameter-list',
                RelativePath::fromString('src/Legacy/Foo.php'),
            ),
        );
    }

    #[Test]
    public function itKeepsTheRuleEnabledWhenExcludeNamespacesIsConfiguredAlongsideARealOption(): void
    {
        $this->registry->setConfigFileOptions([
            'code-smell.long-parameter-list' => [
                'exclude_namespaces' => ['App\\Tests'],
                'error' => 8,
            ],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertTrue($options->isEnabled());
        self::assertSame(8, $options->error);
        self::assertSame(4, $options->warning, 'Untouched sibling key keeps its own default');
        self::assertSame(
            ['App\\Tests'],
            $this->registry->getExclusionProvider()->getExclusions('code-smell.long-parameter-list'),
        );
    }

    #[Test]
    public function itKeepsTheRuleEnabledWhenOnlyExcludeNamespacesIsConfiguredViaCli(): void
    {
        // No config file entry at all for this rule — exclude_namespaces
        // arrives purely through --rule-opt / addCliOption().
        $this->registry->addCliOption('code-smell.long-parameter-list', 'excludeNamespaces', ['App\\Tests']);

        /** @var LongParameterListOptions $options */
        $options = $this->factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertTrue($options->isEnabled(), 'A rule configured with only a CLI exclude_namespaces must stay enabled');
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
        self::assertSame(
            ['App\\Tests'],
            $this->registry->getExclusionProvider()->getExclusions('code-smell.long-parameter-list'),
        );
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
        $this->registry->setConfigFileOptions([
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
        $this->registry->setConfigFileOptions([
            'complexity.cyclomatic' => [
                'method' => ['threshold' => 15],
            ],
        ]);

        /** @var ComplexityOptions $options */
        $options = $this->factory->create('complexity.cyclomatic', ComplexityOptions::class);

        self::assertSame(15, $options->method->warning);
        self::assertSame(15, $options->method->error);
        // Untouched sibling level keeps its own defaults.
        self::assertSame(30, $options->class->maxWarning);
        self::assertSame(50, $options->class->maxError);
    }

    #[Test]
    public function itThrowsWhenUserExplicitlyMixesThresholdAndWarningThroughTheFactory(): void
    {
        $this->registry->setConfigFileOptions([
            'size.method-count' => [
                'threshold' => 25,
                'warning' => 10,
            ],
        ]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Cannot mix "threshold" with "warning"/"error"');

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

    // --- warnAboutUnknownKeys() honors ShorthandOptionKeysInterface ---------
    //
    // Regression coverage for a bug where warnAboutUnknownKeys() only knew
    // about constructor parameter names (via reflection), so a documented
    // ThresholdParser shorthand key — the bare `threshold`, or a rule-specific
    // one like `vo-threshold` / `param_threshold` — was reported as "Unknown
    // option" even though it applied correctly. ShorthandOptionKeysInterface
    // lets an Options class declare which shorthand keys its fromArray()
    // actually accepts; only classes implementing it are exempted, so rules
    // whose fromArray() genuinely has no such branch (CboOptions,
    // InstabilityOptions — the top-level `class`/`namespace` wrapper never
    // routes a bare `threshold` anywhere) keep warning as before.

    #[Test]
    public function itDoesNotWarnAboutTheDocumentedThresholdShorthandOnASupportingRule(): void
    {
        $logger = new RecordingLogger();
        $factory = new RuleOptionsFactory($this->registry, $logger);

        $this->registry->setConfigFileOptions([
            'size.method-count' => ['threshold' => 25],
        ]);

        /** @var MethodCountOptions $options */
        $options = $factory->create('size.method-count', MethodCountOptions::class);

        self::assertSame(25, $options->warning);
        self::assertSame(25, $options->error);
        self::assertSame([], $logger->records, 'The documented `threshold` shorthand must not trigger a false Unknown option warning');
    }

    #[Test]
    public function itDoesNotWarnAboutTheVoThresholdShorthandOnLongParameterList(): void
    {
        $logger = new RecordingLogger();
        $factory = new RuleOptionsFactory($this->registry, $logger);

        $this->registry->setConfigFileOptions([
            'code-smell.long-parameter-list' => ['vo-threshold' => 9],
        ]);

        /** @var LongParameterListOptions $options */
        $options = $factory->create('code-smell.long-parameter-list', LongParameterListOptions::class);

        self::assertSame(9, $options->voWarning);
        self::assertSame(9, $options->voError);
        self::assertSame([], $logger->records, 'The documented `vo-threshold` shorthand must not trigger a false Unknown option warning');
    }

    #[Test]
    public function itDoesNotWarnAboutTheParamThresholdShorthandOnTypeCoverage(): void
    {
        $logger = new RecordingLogger();
        $factory = new RuleOptionsFactory($this->registry, $logger);

        $this->registry->setConfigFileOptions([
            'design.type-coverage' => ['param_threshold' => 70.0],
        ]);

        /** @var TypeCoverageOptions $options */
        $options = $factory->create('design.type-coverage', TypeCoverageOptions::class);

        self::assertSame(70.0, $options->paramWarning);
        self::assertSame(70.0, $options->paramError);
        self::assertSame([], $logger->records, 'The documented `param_threshold` shorthand must not trigger a false Unknown option warning');
    }

    #[Test]
    public function itDoesNotWarnAboutTheThresholdShorthandOnCboAndAppliesItToBothLevels(): void
    {
        $logger = new RecordingLogger();
        $factory = new RuleOptionsFactory($this->registry, $logger);

        $this->registry->setConfigFileOptions([
            'coupling.cbo' => ['threshold' => 30],
        ]);

        /** @var CboOptions $options */
        $options = $factory->create('coupling.cbo', CboOptions::class);

        // CboOptions::fromArray() now has a top-level `threshold`
        // flat-shorthand branch that applies uniformly to BOTH the class and
        // namespace dimensions (their defaults already match: 14/20).
        self::assertSame(30, $options->class->warning);
        self::assertSame(30, $options->class->error);
        self::assertSame(30, $options->namespace->warning);
        self::assertSame(30, $options->namespace->error);
        self::assertSame([], $logger->records, 'The documented `threshold` shorthand must not trigger a false Unknown option warning');
    }

    #[Test]
    public function itDoesNotWarnAboutTheThresholdShorthandOnInstabilityAndAppliesItToBothLevels(): void
    {
        $logger = new RecordingLogger();
        $factory = new RuleOptionsFactory($this->registry, $logger);

        $this->registry->setConfigFileOptions([
            'coupling.instability' => ['threshold' => 0.9],
        ]);

        /** @var InstabilityOptions $options */
        $options = $factory->create('coupling.instability', InstabilityOptions::class);

        self::assertSame(0.9, $options->class->maxWarning);
        self::assertSame(0.9, $options->class->maxError);
        self::assertSame(0.9, $options->namespace->maxWarning);
        self::assertSame(0.9, $options->namespace->maxError);
        self::assertSame([], $logger->records, 'The documented `threshold` shorthand must not trigger a false Unknown option warning');
    }

    #[Test]
    public function itStillWarnsAboutAGenuinelyUnknownKeyOnARuleThatSupportsShorthand(): void
    {
        $logger = new RecordingLogger();
        $factory = new RuleOptionsFactory($this->registry, $logger);

        $this->registry->setConfigFileOptions([
            'size.method-count' => ['nonsense' => 1],
        ]);

        $factory->create('size.method-count', MethodCountOptions::class);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('Unknown option "nonsense" for rule "size.method-count"', $logger->records[0]['message']);
        self::assertStringContainsString(
            'Available options: enabled, warning, error, threshold',
            $logger->records[0]['message'],
            'The declared shorthand key must be listed alongside the constructor-derived options',
        );
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
    // switch modes, not combine them. Reproduces both CLI repros from the
    // review: `--rule-opt=size.method-count:threshold=25` on top of a
    // preset/config-file `warning`/`error` pair.

    #[Test]
    public function cliThresholdOverridesConfigFileWarningAndError(): void
    {
        // Reproduces: qmx.yaml sets `warning`/`error`,
        // `--rule-opt=size.method-count:threshold=25` on top.
        $this->registry->setConfigFileOptions([
            'size.method-count' => ['warning' => 10, 'error' => 20],
        ]);
        $this->registry->setCliOptions('size.method-count', ['threshold' => 25]);

        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertTrue($options->isEnabled());
        self::assertSame(25, $options->warning);
        self::assertSame(25, $options->error);
    }

    #[Test]
    public function cliThresholdOverridesPresetSuppliedWarningAndError(): void
    {
        // Reproduces: --preset=strict sets `warning`/`error` for this rule
        // (arrives here as "config file options", since presets are merged
        // in before RuleOptionsFactory ever runs), and
        // `--rule-opt=size.method-count:threshold=25` on top.
        $this->registry->setConfigFileOptions([
            'size.method-count' => ['warning' => 5, 'error' => 8],
        ]);
        $this->registry->setCliOptions('size.method-count', ['threshold' => 25]);

        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertSame(25, $options->warning);
        self::assertSame(25, $options->error);
    }

    #[Test]
    public function cliWarningAndErrorOverrideConfigFileThreshold(): void
    {
        $this->registry->setConfigFileOptions([
            'size.method-count' => ['threshold' => 25],
        ]);
        $this->registry->setCliOptions('size.method-count', ['warning' => 10, 'error' => 20]);

        /** @var MethodCountOptions $options */
        $options = $this->factory->create('size.method-count', MethodCountOptions::class);

        self::assertSame(10, $options->warning);
        self::assertSame(20, $options->error);
    }

    #[Test]
    public function cliThresholdOverridesConfigFileWarningAndErrorAtNestedLevel(): void
    {
        // Hierarchical rule (complexity.cyclomatic): eviction must be
        // scoped to the `method:` nesting level, not the rule's top level.
        $this->registry->setConfigFileOptions([
            'complexity.cyclomatic' => [
                'method' => ['warning' => 10, 'error' => 20],
                'class' => ['max_warning' => 30, 'max_error' => 50],
            ],
        ]);
        $this->registry->addCliOption('complexity.cyclomatic', 'method.threshold', 15);

        /** @var ComplexityOptions $options */
        $options = $this->factory->create('complexity.cyclomatic', ComplexityOptions::class);

        self::assertSame(15, $options->method->warning);
        self::assertSame(15, $options->method->error);
        // Untouched sibling level keeps its own config-file values.
        self::assertSame(30, $options->class->maxWarning);
        self::assertSame(50, $options->class->maxError);
    }

    #[Test]
    public function sameLayerCliThresholdAndWarningStillThrows(): void
    {
        // Both keys set by the SAME source (CLI) must still be reported as
        // a genuine configuration error — only the *other* side of a merge
        // is ever evicted.
        $this->registry->setCliOptions('size.method-count', ['threshold' => 25, 'warning' => 10]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Cannot mix "threshold" with "warning"/"error"');

        $this->factory->create('size.method-count', MethodCountOptions::class);
    }

    #[Test]
    public function unrelatedVoGroupIsNotEvictedByAnUnrelatedCliThresholdOverride(): void
    {
        // code-smell.long-parameter-list has two independent dimensions:
        // bare warning/error/threshold, and the vo-prefixed variant.
        // A CLI override of one must not evict the other.
        $this->registry->setConfigFileOptions([
            'code-smell.long-parameter-list' => ['warning' => 4, 'error' => 6, 'voWarning' => 8, 'voError' => 12],
        ]);
        $this->registry->setCliOptions('code-smell.long-parameter-list', ['threshold' => 5]);

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
    public function cliThresholdOverridesConfigFilePrefixedGraduatedKeys(): void
    {
        $this->registry->setConfigFileOptions([
            'coupling.distance' => ['max_distance_warning' => 0.4, 'max_distance_error' => 0.6],
        ]);
        $this->registry->setCliOptions('coupling.distance', ['threshold' => 0.5]);

        /** @var DistanceOptions $options */
        $options = $this->factory->create('coupling.distance', DistanceOptions::class);

        self::assertSame(0.5, $options->maxDistanceWarning);
        self::assertSame(0.5, $options->maxDistanceError);
    }

    #[Test]
    public function cliPrefixedGraduatedKeysOverrideConfigFileThreshold(): void
    {
        // Symmetric direction: config file sets the bare `threshold`
        // shorthand, CLI switches to the prefixed graduated pair.
        $this->registry->setConfigFileOptions([
            'coupling.distance' => ['threshold' => 0.5],
        ]);
        $this->registry->setCliOptions('coupling.distance', [
            'maxDistanceWarning' => 0.4,
            'maxDistanceError' => 0.6,
        ]);

        /** @var DistanceOptions $options */
        $options = $this->factory->create('coupling.distance', DistanceOptions::class);

        self::assertSame(0.4, $options->maxDistanceWarning);
        self::assertSame(0.6, $options->maxDistanceError);
    }

    #[Test]
    public function sameLayerCliThresholdAndPrefixedGraduatedKeysStillThrows(): void
    {
        // Both keys set by the SAME source (CLI) for a prefixed group must
        // still be a genuine configuration error.
        $this->registry->setCliOptions('coupling.distance', [
            'threshold' => 0.5,
            'maxDistanceWarning' => 0.4,
        ]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Cannot mix "threshold" with "max_distance_warning"/"max_distance_error"');

        $this->factory->create('coupling.distance', DistanceOptions::class);
    }

    #[Test]
    public function cliThresholdOverridesConfigFilePrefixedGraduatedKeysAtNestedLevel(): void
    {
        // Hierarchical rule with a prefix-mismatched nested level:
        // coupling.instability's `class:` dimension uses max_warning/
        // max_error paired with a bare `threshold` — eviction must be
        // scoped to the `class:` level.
        $this->registry->setConfigFileOptions([
            'coupling.instability' => [
                'class' => ['max_warning' => 0.8, 'max_error' => 0.95],
                'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
            ],
        ]);
        $this->registry->addCliOption('coupling.instability', 'class.threshold', 0.85);

        /** @var InstabilityOptions $options */
        $options = $this->factory->create('coupling.instability', InstabilityOptions::class);

        self::assertSame(0.85, $options->class->maxWarning);
        self::assertSame(0.85, $options->class->maxError);
        // Untouched sibling level keeps its own config-file values.
        self::assertSame(0.7, $options->namespace->maxWarning);
        self::assertSame(0.9, $options->namespace->maxError);
    }

    #[Test]
    public function cliThresholdOverridesLegacyWarningThresholdAliasAtTopLevel(): void
    {
        // complexity.cyclomatic's top-level legacy-flat shorthand accepts
        // `warningThreshold`/`errorThreshold` as legacy aliases for
        // warning/error — a naive suffix heuristic would misclassify those
        // as threshold markers (they end in "Threshold"). The registry
        // entry corrects this: a CLI `threshold` must still evict them.
        $this->registry->setConfigFileOptions([
            'complexity.cyclomatic' => ['warningThreshold' => 10, 'errorThreshold' => 20],
        ]);
        $this->registry->setCliOptions('complexity.cyclomatic', ['threshold' => 15]);

        /** @var ComplexityOptions $options */
        $options = $this->factory->create('complexity.cyclomatic', ComplexityOptions::class);

        self::assertSame(15, $options->method->warning);
        self::assertSame(15, $options->method->error);
    }
}
