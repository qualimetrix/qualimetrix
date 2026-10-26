<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\EditorSchema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaJson;
use Qualimetrix\Analysis\Configuration\EditorSchema\NodeProjection;
use Qualimetrix\Analysis\Configuration\EditorSchema\PortableRegex;
use Qualimetrix\Analysis\Configuration\EditorSchema\ScalarProjection;

#[CoversClass(ScalarProjection::class)]
#[CoversClass(NodeProjection::class)]
#[CoversClass(PortableRegex::class)]
final class ScalarNodeTest extends TestCase
{
    use EditorSchemaAssertions;

    /** @return iterable<string, array{ScalarForm, string, string}> */
    public static function provideForms(): iterable
    {
        yield 'string' => [ScalarForm::String, 'text', '5'];
        yield 'integer' => [ScalarForm::Integer, '5', '"5"'];
        yield 'integer is not a fraction' => [ScalarForm::Integer, '-3', '1.5'];
        yield 'number' => [ScalarForm::Number, '1.5', 'text'];
        yield 'boolean' => [ScalarForm::Boolean, 'false', '1'];
    }

    #[Test]
    #[DataProvider('provideForms')]
    public function itProjectsADeclaredFormAsItsJsonType(ScalarForm $form, string $accepted, string $refused): void
    {
        $schema = self::documentWith(NodeSchema::scalar($form));

        self::assertAccepted($schema, 'key: ' . $accepted);
        self::assertAccepted($schema, 'key: ~');
        self::assertRefusedAt($schema, 'key: ' . $refused, '/key');
        self::assertRefusedAt($schema, 'key: {}', '/key');
        self::assertRefusedAt($schema, 'key: []', '/key');
    }

    #[Test]
    public function itAcceptsEveryScalarWhereNoFormIsDeclared(): void
    {
        $schema = self::documentWith(NodeSchema::scalar());

        self::assertSame(['string', 'number', 'boolean', 'null'], $schema['properties']['key']['type']);
        foreach (['text', '5', '1.5', 'true', '~'] as $value) {
            self::assertAccepted($schema, 'key: ' . $value);
        }
        self::assertRefusedAt($schema, 'key: {}', '/key');
        self::assertRefusedAt($schema, 'key: [a]', '/key');
    }

    #[Test]
    public function itProjectsSeveralFormsAsATypeUnion(): void
    {
        $schema = self::documentWith(NodeSchema::scalar(ScalarForm::String, ScalarForm::Integer));

        self::assertSame(['string', 'integer', 'null'], $schema['properties']['key']['type']);
        self::assertAccepted($schema, 'key: 1G');
        self::assertAccepted($schema, 'key: 512');
        self::assertRefusedAt($schema, 'key: true', '/key');
    }

    #[Test]
    public function itProjectsANumericFloor(): void
    {
        $schema = self::documentWith(NodeSchema::scalar(ScalarForm::Integer)->atLeast(1));

        self::assertSame(1, $schema['properties']['key']['minimum']);
        self::assertAccepted($schema, 'key: 1');
        self::assertRefusedAt($schema, 'key: 0', '/key');
    }

    #[Test]
    public function itProjectsCaseSensitiveWordsAsAnEnum(): void
    {
        $schema = self::documentWith(NodeSchema::scalar(ScalarForm::String)->words(SchemaWordSet::of('class', 'namespace')));

        self::assertSame(['class', 'namespace', null], $schema['properties']['key']['enum']);
        self::assertAccepted($schema, 'key: class');
        self::assertAccepted($schema, 'key: ~');
        self::assertRefusedAt($schema, 'key: Class', '/key');
    }

    #[Test]
    public function itJudgesWordsOnlyWhenTheValueIsText(): void
    {
        $schema = self::documentWith(NodeSchema::scalar(ScalarForm::String, ScalarForm::Integer)->words(SchemaWordSet::of('auto')));

        self::assertAccepted($schema, 'key: auto');
        self::assertAccepted($schema, 'key: 4');
        self::assertRefusedAt($schema, 'key: manual', '/key');
    }

    #[Test]
    public function itProjectsCaseFoldingWordsAsAnEnumAndALetterCasePattern(): void
    {
        $schema = self::documentWith(NodeSchema::scalar(ScalarForm::String)->words(SchemaWordSet::foldingCase('error', 'warning')));

        self::assertSame(
            [['enum' => ['error', 'warning']], ['pattern' => '^(?:[eE][rR][rR][oO][rR]|[wW][aA][rR][nN][iI][nN][gG])$']],
            $schema['properties']['key']['anyOf'],
        );
        self::assertAccepted($schema, 'key: ERROR');
        self::assertAccepted($schema, 'key: Warning');
        self::assertAccepted($schema, 'key: ~');
        self::assertRefusedAt($schema, 'key: errors', '/key');
    }

    /** @return iterable<string, array{string}> */
    public static function provideTextTrimKeeps(): iterable
    {
        yield 'form feed' => ['"\f"'];
        yield 'no-break space' => ['" "'];
        yield 'text inside blanks' => ['"  a  "'];
    }

    #[Test]
    #[DataProvider('provideTextTrimKeeps')]
    public function itAcceptsAsNonBlankWhatTrimKeeps(string $value): void
    {
        self::assertAccepted(self::documentWith(NodeSchema::scalar(ScalarForm::String)->nonEmpty()), 'key: ' . $value);
    }

    /** @return iterable<string, array{string}> */
    public static function provideTextTrimEmpties(): iterable
    {
        yield 'spaces' => ['"  "'];
        yield 'empty' => ['""'];
        yield 'tab and newline' => ['"\t\n"'];
        yield 'nul and vertical tab' => ['"\0\v"'];
    }

    #[Test]
    #[DataProvider('provideTextTrimEmpties')]
    public function itRefusesAsBlankWhatTrimEmpties(string $value): void
    {
        self::assertRefusedAt(self::documentWith(NodeSchema::scalar(ScalarForm::String)->nonEmpty()), 'key: ' . $value, '/key');
    }

    #[Test]
    public function itWritesTheBlankCharactersOfTheNonBlankPatternAsJsonEscapes(): void
    {
        $schema = self::documentWith(NodeSchema::scalar(ScalarForm::String)->nonEmpty());

        self::assertSame("[^ \t\n\r\0\x0B]", $schema['properties']['key']['pattern']);
        self::assertStringContainsString('"pattern": "[^ \\t\\n\\r\\u0000\\u000b]"', EditorSchemaJson::encode($schema));
    }
}
