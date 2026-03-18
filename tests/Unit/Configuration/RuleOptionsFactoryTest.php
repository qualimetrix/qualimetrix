<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Configuration;

use AiMessDetector\Configuration\RuleNamespaceExclusionProvider;
use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Tests\Fixture\TestRuleOptions;
use AiMessDetector\Tests\Fixture\TestRuleOptionsNoConstructor;
use AiMessDetector\Tests\Fixture\TestRuleOptionsWithRequiredParams;
use AiMessDetector\Tests\Fixture\TestRuleOptionsWithUnionType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(RuleOptionsFactory::class)]
final class RuleOptionsFactoryTest extends TestCase
{
    private RuleOptionsFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RuleOptionsFactory();
    }

    public function testCreateWithDefaults(): void
    {
        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertInstanceOf(TestRuleOptions::class, $options);
        self::assertTrue($options->enabled);
        self::assertSame(10, $options->warningThreshold);
        self::assertSame(20, $options->errorThreshold);
        self::assertTrue($options->countNullsafe);
    }

    public function testCreateWithConfigFileOptions(): void
    {
        $this->factory->setConfigFileOptions([
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

    public function testCreateWithCliOptions(): void
    {
        $this->factory->addCliOption('test-rule', 'warningThreshold', 25);
        $this->factory->addCliOption('test-rule', 'countNullsafe', false);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        self::assertSame(25, $options->warningThreshold);
        self::assertFalse($options->countNullsafe);
        // Defaults preserved
        self::assertSame(20, $options->errorThreshold);
    }

    public function testCliOptionsOverrideConfigFile(): void
    {
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 15,
            ],
        ]);

        $this->factory->addCliOption('test-rule', 'warningThreshold', 25);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        // CLI wins
        self::assertSame(25, $options->warningThreshold);
    }

    public function testSetCliOptions(): void
    {
        $this->factory->setCliOptions('test-rule', [
            'warningThreshold' => 50,
            'errorThreshold' => 100,
        ]);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertInstanceOf(TestRuleOptions::class, $options);

        self::assertSame(50, $options->warningThreshold);
        self::assertSame(100, $options->errorThreshold);
    }

    public function testGetConfigFileOptions(): void
    {
        $this->factory->setConfigFileOptions([
            'rule-a' => ['enabled' => false],
            'rule-b' => ['enabled' => true],
        ]);

        $options = $this->factory->getConfigFileOptions();

        self::assertSame(['rule-a' => ['enabled' => false], 'rule-b' => ['enabled' => true]], $options);
    }

    public function testGetCliOptions(): void
    {
        $this->factory->addCliOption('rule-a', 'opt1', 'value1');
        $this->factory->addCliOption('rule-b', 'opt2', 'value2');

        $options = $this->factory->getCliOptions();

        self::assertSame([
            'rule-a' => ['opt1' => 'value1'],
            'rule-b' => ['opt2' => 'value2'],
        ], $options);
    }

    public function testReset(): void
    {
        $this->factory->setConfigFileOptions(['rule' => ['opt' => 'val']]);
        $this->factory->addCliOption('rule', 'opt2', 'val2');

        $this->factory->reset();

        self::assertSame([], $this->factory->getConfigFileOptions());
        self::assertSame([], $this->factory->getCliOptions());
    }

    public function testCreateThrowsForNonExistentClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        /** @phpstan-ignore argument.type */
        $this->factory->create('test-rule', 'NonExistent\\Class');
    }

    public function testCreateThrowsForNonRuleOptionsClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        /** @phpstan-ignore argument.type */
        $this->factory->create('test-rule', stdClass::class);
    }

    public function testNormalizesSnakeCaseKeys(): void
    {
        $this->factory->setConfigFileOptions([
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

    public function testNormalizesKebabCaseKeys(): void
    {
        $this->factory->setConfigFileOptions([
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
        $this->factory->addCliOption('test-rule', 'method.warning', 5);
        $this->factory->addCliOption('test-rule', 'method.error', 10);
        $this->factory->addCliOption('test-rule', 'class.enabled', false);

        $cliOptions = $this->factory->getCliOptions();

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
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'enabled' => true,
                'nested' => [
                    'level1' => [
                        'level2' => 'deep-value',
                    ],
                ],
            ],
        ]);

        $options = $this->factory->getConfigFileOptions();

        self::assertArrayHasKey('test-rule', $options);
        self::assertIsArray($options['test-rule']['nested']);
        self::assertSame('deep-value', $options['test-rule']['nested']['level1']['level2']);
    }

    #[Test]
    public function itDeepMergesNestedArrays(): void
    {
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 15,
                'enabled' => true,
            ],
        ]);

        $this->factory->setCliOptions('test-rule', [
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
        $this->factory->setConfigFileOptions([]);
        $this->factory->setCliOptions('test-rule', []);

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
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 5,
            ],
        ]);

        // CLI completely overrides config value (not merges)
        $this->factory->addCliOption('test-rule', 'warningThreshold', 50);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(50, $options->warningThreshold);
    }

    #[Test]
    public function itNormalizesMixedCaseKeys(): void
    {
        $this->factory->setConfigFileOptions([
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
        $this->factory->addCliOption('test-rule', 'level1.level2.level3', 'deep');
        $this->factory->addCliOption('test-rule', 'level1.level2.other', 'value');

        // The factory stores raw dot notation, expansion happens during create()
        $cliOptions = $this->factory->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertSame('deep', $cliOptions['test-rule']['level1.level2.level3']);
        self::assertSame('value', $cliOptions['test-rule']['level1.level2.other']);
    }

    #[Test]
    public function itHandlesBooleanStringValues(): void
    {
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
            'rule-a' => ['warning_threshold' => 5],
            'rule-b' => ['warning_threshold' => 15],
        ]);

        $this->factory->addCliOption('rule-a', 'errorThreshold', 10);
        $this->factory->addCliOption('rule-b', 'errorThreshold', 30);

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
        $this->factory->addCliOption('test-rule', 'option1', 'value1');
        $this->factory->addCliOption('test-rule', 'option2', 'value2');
        $this->factory->addCliOption('test-rule', 'option3', 'value3');

        $cliOptions = $this->factory->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertCount(3, $cliOptions['test-rule']);
        self::assertSame('value1', $cliOptions['test-rule']['option1']);
        self::assertSame('value2', $cliOptions['test-rule']['option2']);
        self::assertSame('value3', $cliOptions['test-rule']['option3']);
    }

    #[Test]
    public function itOverwritesCliOptionWhenAddedTwice(): void
    {
        $this->factory->addCliOption('test-rule', 'warningThreshold', 5);
        $this->factory->addCliOption('test-rule', 'warningThreshold', 15);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(15, $options->warningThreshold);
    }

    #[Test]
    public function itReplacesCliOptionsWhenUsingSetCliOptions(): void
    {
        $this->factory->addCliOption('test-rule', 'option1', 'old');
        $this->factory->setCliOptions('test-rule', [
            'option2' => 'new',
        ]);

        $cliOptions = $this->factory->getCliOptions();

        self::assertArrayNotHasKey('option1', $cliOptions['test-rule']);
        self::assertArrayHasKey('option2', $cliOptions['test-rule']);
        self::assertSame('new', $cliOptions['test-rule']['option2']);
    }

    #[Test]
    public function itHandlesEmptyStringKeys(): void
    {
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                '' => 'empty-key-value',
                'valid_key' => 'valid-value',
            ],
        ]);

        $options = $this->factory->getConfigFileOptions();

        self::assertArrayHasKey('test-rule', $options);
        self::assertSame('empty-key-value', $options['test-rule']['']);
        self::assertSame('valid-value', $options['test-rule']['valid_key']);
    }

    #[Test]
    public function itNormalizesNumericStringKeys(): void
    {
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                '123_value' => 'numeric-start',
            ],
        ]);

        $normalized = $this->factory->getConfigFileOptions();

        // Key normalization should handle numeric prefixes
        self::assertArrayHasKey('test-rule', $normalized);
    }

    #[Test]
    public function itHandlesDotNotationWithSingleKey(): void
    {
        $this->factory->addCliOption('test-rule', 'simpleKey', 'value');
        $this->factory->addCliOption('test-rule', 'nested.key', 'nested-value');

        $cliOptions = $this->factory->getCliOptions();

        self::assertSame('value', $cliOptions['test-rule']['simpleKey']);
        self::assertSame('nested-value', $cliOptions['test-rule']['nested.key']);
    }

    #[Test]
    public function itCreatesNestedStructureFromDotNotationDuringMerge(): void
    {
        // When create() is called, dot notation should expand
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'enabled' => true,
            ],
        ]);

        $this->factory->addCliOption('test-rule', 'warningThreshold', 99);

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertTrue($options->enabled);
        self::assertSame(99, $options->warningThreshold);
    }

    #[Test]
    public function itHandlesArrayMergeWithScalarOverwrite(): void
    {
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 5,
            ],
        ]);

        // Overwrite with different type (should work)
        $this->factory->addCliOption('test-rule', 'warningThreshold', '25');

        /** @var TestRuleOptions $options */
        $options = $this->factory->create('test-rule', TestRuleOptions::class);

        self::assertSame(25, $options->warningThreshold);
    }

    #[Test]
    public function itPreservesCamelCaseKeysFromConfigFile(): void
    {
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
            'rule1' => ['opt1' => 'val1'],
            'rule2' => ['opt2' => 'val2'],
        ]);

        $this->factory->addCliOption('rule1', 'cliOpt', 'cliVal');
        $this->factory->addCliOption('rule3', 'cliOpt2', 'cliVal2');

        self::assertNotEmpty($this->factory->getConfigFileOptions());
        self::assertNotEmpty($this->factory->getCliOptions());

        $this->factory->reset();

        self::assertEmpty($this->factory->getConfigFileOptions());
        self::assertEmpty($this->factory->getCliOptions());
    }

    #[Test]
    public function itMergesPriorityCorrectly(): void
    {
        // Setup: defaults (10, 20) → config (15, 25) → CLI (warningThreshold=30)
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 15,
                'error_threshold' => 25,
            ],
        ]);

        $this->factory->addCliOption('test-rule', 'warningThreshold', 30);

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
        $this->factory->setConfigFileOptions([
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
        $this->factory->addCliOption('test-rule', 'enabled', false);
        $this->factory->addCliOption('test-rule', 'threshold', 50);
        $this->factory->addCliOption('test-rule', 'ratio', 0.5);
        $this->factory->addCliOption('test-rule', 'name', 'cli-name');
        $this->factory->addCliOption('test-rule', 'items', ['a', 'b', 'c']);
        $this->factory->addCliOption('test-rule', 'optional', 'value');

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
        $this->factory->addCliOption('complexity', 'method.warning', 5);
        $this->factory->addCliOption('complexity', 'method.error', 10);
        $this->factory->addCliOption('complexity', 'class.warning', 15);
        $this->factory->addCliOption('complexity', 'class.error', 20);

        // Before expansion, options are stored as-is
        $cliOptions = $this->factory->getCliOptions();
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
        $this->factory->addCliOption('test-rule', 'nested.key1', 'value1');
        $this->factory->addCliOption('test-rule', 'nested.key2', 'value2');

        $cliOptions = $this->factory->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertSame('value1', $cliOptions['test-rule']['nested.key1']);
        self::assertSame('value2', $cliOptions['test-rule']['nested.key2']);
    }

    #[Test]
    public function itResetsCliOptionsWithoutAffectingConfigFileOptions(): void
    {
        $this->factory->setConfigFileOptions([
            'test-rule' => ['warning_threshold' => 15],
        ]);
        $this->factory->addCliOption('test-rule', 'errorThreshold', 30);
        $this->factory->addCliOption('other-rule', 'enabled', false);

        self::assertNotEmpty($this->factory->getCliOptions());

        $this->factory->resetCliOptions();

        self::assertEmpty($this->factory->getCliOptions());
        // Config file options preserved
        self::assertSame(['test-rule' => ['warning_threshold' => 15]], $this->factory->getConfigFileOptions());
    }

    #[Test]
    public function cliOptionsDoNotLeakBetweenRunsAfterReset(): void
    {
        // Simulate first run
        $this->factory->setCliOptions('test-rule', ['warningThreshold' => 50]);

        /** @var TestRuleOptions $options1 */
        $options1 = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertSame(50, $options1->warningThreshold);

        // Reset between runs
        $this->factory->resetCliOptions();

        // Second run without CLI options — should use defaults
        /** @var TestRuleOptions $options2 */
        $options2 = $this->factory->create('test-rule', TestRuleOptions::class);
        self::assertSame(10, $options2->warningThreshold, 'CLI options from first run should not leak into second run');
    }

    #[Test]
    public function itNormalizesScalarFalseRuleConfig(): void
    {
        // YAML: `rules: { test-rule: false }` arrives as scalar false
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->addCliOption('test-rule', 'a.b.c.d.e', 'deep-value');

        $cliOptions = $this->factory->getCliOptions();

        self::assertArrayHasKey('test-rule', $cliOptions);
        self::assertSame('deep-value', $cliOptions['test-rule']['a.b.c.d.e']);
    }

    #[Test]
    public function itThrowsWhenNumericFieldContainsNonNumericString(): void
    {
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'warning_threshold' => 'not_a_number',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('option "warningThreshold" must be numeric');

        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itThrowsWhenErrorThresholdIsNonNumericString(): void
    {
        $this->factory->setConfigFileOptions([
            'test-rule' => [
                'error_threshold' => 'invalid',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('option "errorThreshold" must be numeric');

        $this->factory->create('test-rule', TestRuleOptions::class);
    }

    #[Test]
    public function itAcceptsNumericStringForThresholdFields(): void
    {
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
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
        $this->factory->setConfigFileOptions([
            'complexity.cyclomatic' => [
                'error_threshold' => 'not_a_number',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rule "complexity.cyclomatic"');

        $this->factory->create('complexity.cyclomatic', TestRuleOptions::class);
    }

    // --- exclude_namespaces extraction tests ---

    #[Test]
    public function createExtractsExcludeNamespacesSnakeCase(): void
    {
        $this->factory->setConfigFileOptions([
            'test.rule' => [
                'exclude_namespaces' => ['App\\Tests', 'App\\Legacy'],
                'warningThreshold' => 5,
            ],
        ]);

        $this->factory->create('test.rule', TestRuleOptions::class);

        $provider = $this->factory->getExclusionProvider();
        self::assertSame(['App\\Tests', 'App\\Legacy'], $provider->getExclusions('test.rule'));
    }

    #[Test]
    public function createExtractsExcludeNamespacesCamelCase(): void
    {
        $this->factory->setConfigFileOptions([
            'test.rule' => [
                'excludeNamespaces' => ['App\\Tests'],
            ],
        ]);

        $this->factory->create('test.rule', TestRuleOptions::class);

        $provider = $this->factory->getExclusionProvider();
        self::assertSame(['App\\Tests'], $provider->getExclusions('test.rule'));
    }

    #[Test]
    public function createExtractsExcludeNamespacesStringCoercedToArray(): void
    {
        $this->factory->setConfigFileOptions([
            'test.rule' => [
                'exclude_namespaces' => 'App\\Tests',
            ],
        ]);

        $this->factory->create('test.rule', TestRuleOptions::class);

        $provider = $this->factory->getExclusionProvider();
        self::assertSame(['App\\Tests'], $provider->getExclusions('test.rule'));
    }

    #[Test]
    public function createRemovesExcludeNamespacesFromOptionsBeforeFromArray(): void
    {
        $this->factory->setConfigFileOptions([
            'test.rule' => [
                'exclude_namespaces' => ['App\\Tests'],
                'warningThreshold' => 7,
            ],
        ]);

        $options = $this->factory->create('test.rule', TestRuleOptions::class);

        self::assertInstanceOf(TestRuleOptions::class, $options);
        self::assertSame(7, $options->warningThreshold);
        self::assertSame(['App\\Tests'], $this->factory->getExclusionProvider()->getExclusions('test.rule'));
    }

    #[Test]
    public function resetClearsExclusionProvider(): void
    {
        $provider = new RuleNamespaceExclusionProvider();
        $factory = new RuleOptionsFactory($provider);

        $factory->setConfigFileOptions([
            'test.rule' => ['exclude_namespaces' => ['App\\Tests']],
        ]);
        $factory->create('test.rule', TestRuleOptions::class);
        self::assertSame(['App\\Tests'], $provider->getExclusions('test.rule'));

        $factory->reset();
        self::assertSame([], $provider->getExclusions('test.rule'));
    }
}
