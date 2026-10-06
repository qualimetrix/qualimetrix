<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\CommandLineSyntax;
use Qualimetrix\Analysis\Configuration\Loader\CommandLineValue;

#[CoversClass(CommandLineValue::class)]
#[CoversClass(CommandLineSyntax::class)]
final class CommandLineValueTest extends TestCase
{
    #[Test]
    public function itExplainsWhyAnAuthoredPositiveDecimalIsNotAnInteger(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Value was read as float (1.0). YAML interprets +2 and 2.0 as float; write 2 for an integer.');
        CommandLineValue::read('+1', NodeSchema::scalar(ScalarForm::Integer), '--rule-opt');
    }

    #[Test]
    public function itKeepsTheAuthoredExpressionWhenYamlScalarSyntaxIsMalformed(): void
    {
        $expression = '--rule-opt=complexity.ccn:callable.warning=[';
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage($expression);
        CommandLineValue::read('[', NodeSchema::scalar(ScalarForm::Integer), '--rule-opt', authoredExpression: $expression);
    }

    #[Test]
    public function itParsesBooleanIntegerFloatAndStringThroughTheirDeclaredForms(): void
    {
        self::assertTrue(CommandLineValue::read('true', NodeSchema::scalar(ScalarForm::Boolean), '--rule-opt')->plain());
        self::assertFalse(CommandLineValue::read('false', NodeSchema::scalar(ScalarForm::Boolean), '--rule-opt')->plain());
        self::assertSame(-10, CommandLineValue::read('-10', NodeSchema::scalar(ScalarForm::Integer), '--rule-opt')->plain());
        self::assertSame(3.14, CommandLineValue::read('3.14', NodeSchema::scalar(ScalarForm::Number), '--rule-opt')->plain());
        self::assertSame(1000.0, CommandLineValue::read('1e3', NodeSchema::scalar(ScalarForm::Number), '--rule-opt')->plain());
        self::assertSame(150.0, CommandLineValue::read('1.5e2', NodeSchema::scalar(ScalarForm::Number), '--rule-opt')->plain());
        self::assertSame('json', CommandLineValue::read('json', NodeSchema::scalar(ScalarForm::String), '--rule-opt')->plain());
    }

    #[Test]
    public function itJudgesNumbersAndQuotedScalarsAgainstTheDeclaredForm(): void
    {
        $integer = NodeSchema::scalar(ScalarForm::Integer)->atLeast(0);
        self::assertSame(3, CommandLineValue::read('3', $integer, '--rule-opt')->plain());
        self::assertSame('3', CommandLineValue::read('"3"', NodeSchema::scalar(ScalarForm::String), '--rule-opt')->plain());

        foreach (['-1', '+1', '"+2"', '1e0', '1.0', '+1e0', '"3"', "'3'", '+' . \PHP_INT_MAX . '0'] as $text) {
            try {
                CommandLineValue::read($text, $integer, '--rule-opt');
                self::fail($text . ' should not satisfy the integer declaration.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertNotSame('', $refusal->getMessage());
            }
        }
    }

    #[Test]
    public function itReadsOnlyFlowListsAndPreservesQuotedCommas(): void
    {
        $list = NodeSchema::list(NodeSchema::scalar(ScalarForm::String));
        self::assertSame(['a', 'b'], CommandLineValue::read('[a,b]', $list, '--rule-opt')->plain());
        self::assertSame(['a,b'], CommandLineValue::read('["a,b"]', $list, '--rule-opt')->plain());

        try {
            CommandLineValue::read('a,b', $list, '--rule-opt');
            self::fail('A comma without flow-list brackets must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('[a,b]', $refusal->getMessage());
        }
    }

    #[Test]
    public function itAdmitsQuotedCommaAsOneBareElementOnlyWhereTheSchemaAllowsIt(): void
    {
        $list = NodeSchema::list(NodeSchema::scalar(ScalarForm::String))->admittingBareElement();
        self::assertSame(['a,b'], CommandLineValue::read('"a,b"', $list, '--rule-opt')->plain());
        self::assertSame(['a,b'], CommandLineValue::read('["a,b"]', $list, '--rule-opt')->plain());

        try {
            CommandLineValue::read('a,b', $list, '--rule-opt');
            self::fail('An unquoted comma should not silently split a list.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('[a,b]', $refusal->getMessage());
        }

        $this->expectException(ConfigurationRefusal::class);
        CommandLineValue::read('"a,b"', NodeSchema::list(NodeSchema::scalar(ScalarForm::String)), '--rule-opt');
    }

    #[Test]
    public function itRefusesNullAndMappingsAtTheCliDoor(): void
    {
        foreach (['null', '~', '{a: b}', '[{a: b}]'] as $text) {
            try {
                CommandLineValue::read($text, NodeSchema::list(NodeSchema::scalar(ScalarForm::String)), '--rule-opt');
                self::fail($text . ' should be refused.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertNotSame('', $refusal->getMessage());
            }
        }
    }
}
