<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;

#[CoversClass(RuleOptionsParser::class)]
final class RuleOptionsParserTest extends TestCase
{
    private RuleOptionsParser $parser;

    protected function setUp(): void
    {
        // Create parser with short aliases like rules would provide
        $this->parser = new RuleOptionsParser([
            'cyclomatic-warning' => ['rule' => 'cyclomatic-complexity', 'option' => 'warningThreshold'],
            'cyclomatic-error' => ['rule' => 'cyclomatic-complexity', 'option' => 'errorThreshold'],
            'class-count-warning' => ['rule' => 'namespace-size', 'option' => 'warningThreshold'],
            'class-count-error' => ['rule' => 'namespace-size', 'option' => 'errorThreshold'],
        ]);
    }

    #[Test]
    public function itParsesRuleOptionsBasic(): void
    {
        $result = $this->parser->parseRuleOptions([
            'cyclomatic-complexity:warningThreshold=15',
            'namespace-size:errorThreshold=20',
        ]);

        self::assertSame([
            'cyclomatic-complexity' => ['warningThreshold' => 15],
            'namespace-size' => ['errorThreshold' => 20],
        ], $result);
    }

    #[Test]
    public function itParsesRuleOptionsMultipleForSameRule(): void
    {
        $result = $this->parser->parseRuleOptions([
            'cyclomatic-complexity:warningThreshold=10',
            'cyclomatic-complexity:errorThreshold=20',
        ]);

        self::assertSame([
            'cyclomatic-complexity' => [
                'warningThreshold' => 10,
                'errorThreshold' => 20,
            ],
        ], $result);
    }

    #[Test]
    public function itNormalizesKebabCaseInRuleOptions(): void
    {
        $result = $this->parser->parseRuleOptions([
            'cyclomatic-complexity:warning-threshold=15',
            'namespace-size:count-interfaces=true',
        ]);

        self::assertSame([
            'cyclomatic-complexity' => ['warningThreshold' => 15],
            'namespace-size' => ['countInterfaces' => true],
        ], $result);
    }

    #[Test]
    public function itNormalizesSnakeCaseInRuleOptions(): void
    {
        $result = $this->parser->parseRuleOptions([
            'cyclomatic-complexity:warning_threshold=15',
            'namespace-size:count_interfaces=true',
        ]);

        self::assertSame([
            'cyclomatic-complexity' => ['warningThreshold' => 15],
            'namespace-size' => ['countInterfaces' => true],
        ], $result);
    }

    #[Test]
    public function itNormalizesMixedKebabAndSnakeCaseInRuleOptions(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:my_option-name=value',
        ]);

        self::assertSame([
            'test-rule' => ['myOptionName' => 'value'],
        ], $result);
    }

    #[Test]
    public function itParsesBooleanValuesInRuleOptions(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:enabled=true',
            'test-rule:disabled=false',
        ]);

        self::assertSame([
            'test-rule' => [
                'enabled' => true,
                'disabled' => false,
            ],
        ], $result);
    }

    #[Test]
    public function itParsesFloatValuesInRuleOptions(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:threshold=3.14',
        ]);

        self::assertSame([
            'test-rule' => ['threshold' => 3.14],
        ], $result);
    }

    #[Test]
    public function itParsesNegativeIntInRuleOptions(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:threshold=-10',
        ]);

        self::assertSame([
            'test-rule' => ['threshold' => -10],
        ], $result);
    }

    #[Test]
    public function itParsesStringValuesInRuleOptions(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:format=json',
        ]);

        self::assertSame([
            'test-rule' => ['format' => 'json'],
        ], $result);
    }

    #[Test]
    public function itIgnoresInvalidFormatInRuleOptions(): void
    {
        $result = $this->parser->parseRuleOptions([
            'invalid-no-colon',
            'no-equals:option',
            'valid-rule:option=value',
        ]);

        self::assertSame([
            'valid-rule' => ['option' => 'value'],
        ], $result);
    }

    #[Test]
    public function itParsesShortAlias(): void
    {
        $result = $this->parser->parseShortAlias('cyclomatic-warning', 10);

        self::assertSame([
            'rule' => 'cyclomatic-complexity',
            'option' => 'warningThreshold',
            'value' => 10,
        ], $result);
    }

    #[Test]
    public function itReturnsNullForUnknownShortAlias(): void
    {
        $result = $this->parser->parseShortAlias('unknown-alias', 10);

        self::assertNull($result);
    }

    #[Test]
    public function itParsesDisabledRules(): void
    {
        $result = $this->parser->parseDisabledRules([
            'cyclomatic-complexity',
            'NAMESPACE-SIZE',
            '  some-rule  ',
        ]);

        self::assertSame([
            'cyclomatic-complexity',
            'namespace-size',
            'some-rule',
        ], $result);
    }

    #[Test]
    public function itParsesOnlyRules(): void
    {
        $result = $this->parser->parseOnlyRules([
            'cyclomatic-complexity',
        ]);

        self::assertSame(['cyclomatic-complexity'], $result);
    }

    #[Test]
    public function itHandlesParserWithoutAliases(): void
    {
        $parser = new RuleOptionsParser();

        $result = $parser->parseShortAlias('cyclomatic-warning', 10);

        self::assertNull($result);
    }

    #[Test]
    public function itReturnsAllRegisteredAliasNames(): void
    {
        $aliases = $this->parser->getAliasNames();

        self::assertSame([
            'cyclomatic-warning',
            'cyclomatic-error',
            'class-count-warning',
            'class-count-error',
        ], $aliases);
    }

    #[Test]
    public function itReturnsEmptyAliasNamesForParserWithoutAliases(): void
    {
        $parser = new RuleOptionsParser();

        self::assertSame([], $parser->getAliasNames());
    }

    #[Test]
    public function itParsesDisabledRulesWithDotNotation(): void
    {
        $result = $this->parser->parseDisabledRules([
            'complexity',
            'complexity.class',
            'complexity.method',
            'size.namespace',
        ]);

        self::assertSame([
            'complexity',
            'complexity.class',
            'complexity.method',
            'size.namespace',
        ], $result);
    }

    #[Test]
    public function itParsesOnlyRulesWithDotNotation(): void
    {
        $result = $this->parser->parseOnlyRules([
            'complexity.method',
            'size.namespace',
        ]);

        self::assertSame([
            'complexity.method',
            'size.namespace',
        ], $result);
    }

    /**
     * Regression for the empty-value-on-the-CLI-door defect: an empty value
     * after `=` used to survive as the literal string `''`, which every
     * affected Options::fromArray() then wrapped into a genuine one-element
     * list `['']` instead of falling back to its default. `--rule-opt` is the
     * door where the defect was reproduced — this pins it at the door, not at
     * the Options class, because a test on `Options::fromArray()` never sees
     * what the door itself hands over.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideEmptyValueGridRows(): iterable
    {
        yield 'boolean-argument allowed-prefixes' => ['code-smell.boolean-argument:allowed-prefixes='];
        yield 'error-suppression allowed-functions' => ['code-smell.error-suppression:allowed-functions='];
        yield 'cohesion.lcom exclude-methods' => ['cohesion.lcom:exclude-methods='];
        yield 'coupling.distance include-namespaces' => ['coupling.distance:include-namespaces='];
    }

    #[Test]
    #[DataProvider('provideEmptyValueGridRows')]
    public function itNormalizesAnEmptyRuleOptValueToNullInsteadOfAOneElementEmptyString(string $ruleOpt): void
    {
        $result = $this->parser->parseRuleOptions([$ruleOpt]);

        [$ruleName, $rest] = explode(':', $ruleOpt, 2);
        [$option] = explode('=', $rest, 2);
        $option = ConfigKeySpelling::normalize($option);

        self::assertArrayHasKey($ruleName, $result);
        self::assertNull(
            $result[$ruleName][$option],
            'An empty CLI value must fold to null (the absent-value marker), never to the literal empty string.',
        );
    }

    /**
     * The boundary case: a single, non-empty value on the same keys must
     * keep working exactly as before — the cure must not turn "one written
     * value" into "no value" too. There is no legitimate empty-string case to
     * protect on this door for these keys: `--rule-opt` cannot type a real
     * empty PHP list at all (no bracket parsing — see
     * `promise-effect/door-normalization.tsv`, `rule-opt|list` row), so
     * before this fix an empty value here was never anything but the defect,
     * on every one of the four rows.
     */
    #[Test]
    public function itStillParsesANonEmptyValueOnTheSameKeyAsASingleElement(): void
    {
        $result = $this->parser->parseRuleOptions([
            'code-smell.boolean-argument:allowed-prefixes=is',
        ]);

        self::assertSame('is', $result['code-smell.boolean-argument']['allowedPrefixes']);
    }

    #[Test]
    public function itNormalizesAnEmptyPlainOptionValueToNullAsWell(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:some-option=',
        ]);

        self::assertNull($result['test-rule']['someOption']);
    }
}
