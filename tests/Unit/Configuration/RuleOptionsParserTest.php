<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Configuration;

use AiMessDetector\Configuration\RuleOptionsParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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

    public function testParseRuleOptionsBasic(): void
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

    public function testParseRuleOptionsMultipleForSameRule(): void
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

    public function testParseRuleOptionsNormalizesKebabCase(): void
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

    public function testParseRuleOptionsBooleanValues(): void
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

    public function testParseRuleOptionsFloatValues(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:threshold=3.14',
        ]);

        self::assertSame([
            'test-rule' => ['threshold' => 3.14],
        ], $result);
    }

    public function testParseRuleOptionsNegativeInt(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:threshold=-10',
        ]);

        self::assertSame([
            'test-rule' => ['threshold' => -10],
        ], $result);
    }

    public function testParseRuleOptionsStringValues(): void
    {
        $result = $this->parser->parseRuleOptions([
            'test-rule:format=json',
        ]);

        self::assertSame([
            'test-rule' => ['format' => 'json'],
        ], $result);
    }

    public function testParseRuleOptionsIgnoresInvalidFormat(): void
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

    public function testParseShortAlias(): void
    {
        $result = $this->parser->parseShortAlias('cyclomatic-warning', 10);

        self::assertSame([
            'rule' => 'cyclomatic-complexity',
            'option' => 'warningThreshold',
            'value' => 10,
        ], $result);
    }

    public function testParseShortAliasUnknown(): void
    {
        $result = $this->parser->parseShortAlias('unknown-alias', 10);

        self::assertNull($result);
    }

    public function testParseDisabledRules(): void
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

    public function testParseOnlyRules(): void
    {
        $result = $this->parser->parseOnlyRules([
            'cyclomatic-complexity',
        ]);

        self::assertSame(['cyclomatic-complexity'], $result);
    }

    public function testParserWithoutAliases(): void
    {
        $parser = new RuleOptionsParser();

        $result = $parser->parseShortAlias('cyclomatic-warning', 10);

        self::assertNull($result);
    }

    public function testParseDisabledRulesWithDotNotation(): void
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

    public function testParseOnlyRulesWithDotNotation(): void
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
}
