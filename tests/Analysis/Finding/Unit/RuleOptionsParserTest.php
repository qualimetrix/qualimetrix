<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;

#[CoversClass(RuleOptionsParser::class)]
final class RuleOptionsParserTest extends TestCase
{
    private RuleOptionsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RuleOptionsParser([
            'cyclomatic-warning' => ['rule' => 'cyclomatic-complexity', 'option' => 'warningThreshold'],
            'cyclomatic-error' => ['rule' => 'cyclomatic-complexity', 'option' => 'errorThreshold'],
            'class-count-warning' => ['rule' => 'namespace-size', 'option' => 'warningThreshold'],
            'class-count-error' => ['rule' => 'namespace-size', 'option' => 'errorThreshold'],
        ]);
    }

    #[Test]
    public function itKeepsAuthoredRuleOptionTextAndSpellingForTheDocumentDoor(): void
    {
        self::assertSame([
            'rule' => 'complexity.ccn',
            'option' => 'callable.warning',
            'text' => '["a,b"]',
        ], $this->parser->parseAuthoredRuleOption('complexity.ccn:callable.warning=["a,b"]'));
        self::assertSame([
            'rule' => 'COMPLEXITY.CCN',
            'option' => 'callable.max_warning',
            'text' => '1e0',
        ], $this->parser->parseAuthoredRuleOption('COMPLEXITY.CCN:callable.max_warning=1e0'));
    }

    #[Test]
    public function itKeepsBasicRuleOptionOwnersSeparate(): void
    {
        self::assertSame([
            'rule' => 'cyclomatic-complexity', 'option' => 'warningThreshold', 'text' => '15',
        ], $this->parser->parseAuthoredRuleOption('cyclomatic-complexity:warningThreshold=15'));
        self::assertSame([
            'rule' => 'namespace-size', 'option' => 'errorThreshold', 'text' => '20',
        ], $this->parser->parseAuthoredRuleOption('namespace-size:errorThreshold=20'));
    }

    #[Test]
    public function itKeepsMultipleWritesForTheSameRuleDistinct(): void
    {
        self::assertSame([
            'rule' => 'cyclomatic-complexity', 'option' => 'warningThreshold', 'text' => '10',
        ], $this->parser->parseAuthoredRuleOption('cyclomatic-complexity:warningThreshold=10'));
        self::assertSame([
            'rule' => 'cyclomatic-complexity', 'option' => 'errorThreshold', 'text' => '20',
        ], $this->parser->parseAuthoredRuleOption('cyclomatic-complexity:errorThreshold=20'));
    }

    #[Test]
    public function itKeepsKebabCaseInTheAuthoredIngress(): void
    {
        self::assertSame('warning-threshold', $this->parser->parseAuthoredRuleOption('cyclomatic-complexity:warning-threshold=15')['option']);
        self::assertSame('count-interfaces', $this->parser->parseAuthoredRuleOption('namespace-size:count-interfaces=true')['option']);
    }

    #[Test]
    public function itKeepsSnakeCaseInTheAuthoredIngress(): void
    {
        self::assertSame('warning_threshold', $this->parser->parseAuthoredRuleOption('cyclomatic-complexity:warning_threshold=15')['option']);
        self::assertSame('count_interfaces', $this->parser->parseAuthoredRuleOption('namespace-size:count_interfaces=true')['option']);
    }

    #[Test]
    public function itKeepsMixedKebabAndSnakeCaseInTheAuthoredIngress(): void
    {
        self::assertSame('my_option-name', $this->parser->parseAuthoredRuleOption('test-rule:my_option-name=value')['option']);
    }

    #[Test]
    public function itKeepsBooleanTextsForTheDeclaredForm(): void
    {
        self::assertSame('true', $this->parser->parseAuthoredRuleOption('test-rule:enabled=true')['text']);
        self::assertSame('false', $this->parser->parseAuthoredRuleOption('test-rule:disabled=false')['text']);
    }

    #[Test]
    public function itKeepsFloatTextForTheDeclaredForm(): void
    {
        self::assertSame('3.14', $this->parser->parseAuthoredRuleOption('test-rule:threshold=3.14')['text']);
    }

    #[Test]
    public function itKeepsNegativeIntegerTextForTheDeclaredForm(): void
    {
        self::assertSame('-10', $this->parser->parseAuthoredRuleOption('test-rule:threshold=-10')['text']);
    }

    #[Test]
    public function itKeepsStringTextForTheDeclaredForm(): void
    {
        self::assertSame('json', $this->parser->parseAuthoredRuleOption('test-rule:format=json')['text']);
    }

    #[Test]
    public function itRefusesMalformedRuleOptionsAndStillAcceptsAValidSpelling(): void
    {
        foreach (['invalid-no-colon', 'no-equals:option'] as $text) {
            try {
                $this->parser->parseAuthoredRuleOption($text);
                self::fail('Malformed rule option input must be refused.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertSame('Invalid --rule-opt "' . $text . '". Expected RULE:OPTION=VALUE.', $refusal->summary());
            }
        }

        self::assertSame([
            'rule' => 'valid-rule',
            'option' => 'option',
            'text' => 'value',
        ], $this->parser->parseAuthoredRuleOption('valid-rule:option=value'));
    }

    #[Test]
    public function itResolvesShortAliasTarget(): void
    {
        self::assertSame([
            'rule' => 'cyclomatic-complexity',
            'option' => 'warningThreshold',
        ], $this->parser->aliasTarget('cyclomatic-warning'));
    }

    #[Test]
    public function itReturnsNullForUnknownShortAlias(): void
    {
        self::assertNull($this->parser->aliasTarget('unknown-alias'));
    }

    #[Test]
    public function itHandlesParserWithoutAliases(): void
    {
        self::assertNull((new RuleOptionsParser())->aliasTarget('cyclomatic-warning'));
    }

    #[Test]
    public function itReturnsAllRegisteredAliasNames(): void
    {
        self::assertSame([
            'cyclomatic-warning',
            'cyclomatic-error',
            'class-count-warning',
            'class-count-error',
        ], $this->parser->getAliasNames());
    }

    #[Test]
    public function itReturnsEmptyAliasNamesForParserWithoutAliases(): void
    {
        self::assertSame([], (new RuleOptionsParser())->getAliasNames());
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideEmptyValueGridRows(): iterable
    {
        yield 'boolean-argument allowed-prefixes' => ['code-smell.boolean-argument:allowed-prefixes='];
        yield 'error-suppression allowed-functions' => ['code-smell.error-suppression:allowed-functions='];
        yield 'cohesion.lcom exclude-methods' => ['cohesion.lcom:exclude-methods='];
        yield 'coupling.distance include-namespaces' => ['coupling.distance:include-namespaces='];
    }

    #[Test]
    #[DataProvider('provideEmptyValueGridRows')]
    public function itRefusesAnEmptyRuleOptValueInsteadOfFoldingItToAOneElementEmptyString(string $ruleOpt): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('was written with an empty value');

        $this->parser->parseAuthoredRuleOption($ruleOpt);
    }

    #[Test]
    public function itStillParsesANonEmptyValueOnTheSameKeyAsASingleElement(): void
    {
        self::assertSame('is', $this->parser->parseAuthoredRuleOption('code-smell.boolean-argument:allowed-prefixes=is')['text']);
    }

    #[Test]
    public function itRefusesAnEmptyPlainOptionValueAsWell(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Option "someOption" of rule "test-rule" was written with an empty value');

        $this->parser->parseAuthoredRuleOption('test-rule:some-option=');
    }

    #[Test]
    public function itNamesTheRuleAndOptionInTheEmptyValueRefusal(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage(
            'Option "allowedPrefixes" of rule "code-smell.boolean-argument" was written with an empty value'
            . ' ("--rule-opt code-smell.boolean-argument:allowed-prefixes=").',
        );

        $this->parser->parseAuthoredRuleOption('code-smell.boolean-argument:allowed-prefixes=');
    }
}
