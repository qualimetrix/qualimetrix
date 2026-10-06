<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\LayerReading;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionWordSet;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionSchemaProjection;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionShapeMatcher;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionShapeWording;

#[CoversClass(RuleOptionShape::class)]
#[CoversClass(RuleOptionSchemaProjection::class)]
#[CoversClass(RuleOptionShapeMatcher::class)]
#[CoversClass(RuleOptionShapeWording::class)]
#[CoversClass(RuleOptionValueForm::class)]
final class RuleOptionShapeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAcceptedValues')]
    public function itAcceptsAValueOfTheFormItNames(RuleOptionShape $shape, mixed $value): void
    {
        self::assertTrue((new RuleOptionShapeMatcher())->matches($shape, $value));
    }

    /**
     * @return iterable<string, array{RuleOptionShape, mixed}>
     */
    public static function provideAcceptedValues(): iterable
    {
        yield 'boolean' => [RuleOptionShape::boolean(), true];
        yield 'whole number' => [RuleOptionShape::integer(), 10];
        yield 'number takes a whole one' => [RuleOptionShape::number(), 10];
        yield 'number takes a fraction' => [RuleOptionShape::number(), 0.3];
        yield 'string' => [RuleOptionShape::text(), 'all'];
        yield 'the empty string where text is named' => [RuleOptionShape::text(), ''];
        yield 'non-empty string' => [RuleOptionShape::nonEmptyText(), 'App\\'];
        yield 'list of strings' => [RuleOptionShape::listOf(RuleOptionShape::text()), ['a', 'b']];
        yield 'empty list' => [RuleOptionShape::listOf(RuleOptionShape::text()), []];
        yield 'map of lists' => [
            RuleOptionShape::mapOf(RuleOptionShape::listOf(RuleOptionShape::text())),
            ['health.cohesion' => ['App\\']],
        ];
        yield 'block' => [RuleOptionShape::block(), ['warning' => 1]];
        yield 'either, first branch' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            'App\\',
        ];
        yield 'either, second branch' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            ['App\\'],
        ];
        yield 'null where null is named' => [RuleOptionShape::integer()->orNull(), null];
    }

    #[Test]
    #[DataProvider('provideRefusedValues')]
    public function itRefusesAValueOfAnyOtherForm(RuleOptionShape $shape, mixed $value): void
    {
        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, $value));
    }

    /**
     * @return iterable<string, array{RuleOptionShape, mixed}>
     */
    public static function provideRefusedValues(): iterable
    {
        yield 'a whole number is not a boolean' => [RuleOptionShape::boolean(), 1];
        yield 'a numeric string is not a whole number' => [RuleOptionShape::integer(), '10'];
        yield 'a fraction is not a whole number' => [RuleOptionShape::integer(), 10.5];
        yield 'a list is not a number' => [RuleOptionShape::number(), [1]];
        yield 'a blank string is not a non-empty one' => [RuleOptionShape::nonEmptyText(), '  '];
        yield 'a map is not a list' => [RuleOptionShape::listOf(RuleOptionShape::text()), ['a' => 'b']];
        yield 'a wrongly typed element fails its list' => [RuleOptionShape::listOf(RuleOptionShape::text()), ['a', 7]];
        yield 'a list is not a map' => [RuleOptionShape::mapOf(RuleOptionShape::text()), ['a']];
        yield 'a scalar is not a block' => [RuleOptionShape::block(), false];
        yield 'no branch of a union matches' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            7331,
        ];
        yield 'null where null is not named' => [RuleOptionShape::integer(), null];
    }

    #[Test]
    #[DataProvider('provideDescriptions')]
    public function itNamesTheFormItExpects(RuleOptionShape $shape, string $expected): void
    {
        self::assertSame($expected, (new RuleOptionShapeWording())->describe($shape));
    }

    /**
     * @return iterable<string, array{RuleOptionShape, string}>
     */
    public static function provideDescriptions(): iterable
    {
        yield 'boolean' => [RuleOptionShape::boolean(), 'a boolean'];
        yield 'nullable whole number' => [RuleOptionShape::integer()->orNull(), 'a non-negative whole number or null'];
        yield 'list names its elements in the plural' => [
            RuleOptionShape::listOf(RuleOptionShape::nonEmptyText()),
            'a list of non-empty strings',
        ];
        yield 'a nullable element keeps the singular, so the "or null" stays attached to one value' => [
            RuleOptionShape::listOf(RuleOptionShape::integer()->orNull()),
            'a list of a non-negative whole number or null',
        ];
        yield 'map names its value' => [
            RuleOptionShape::mapOf(RuleOptionShape::listOf(RuleOptionShape::text())),
            'a map of a list of strings',
        ];
        yield 'block' => [RuleOptionShape::block(), 'a block of options'];
        yield 'union names both branches' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            'a string or a list of strings',
        ];
    }

    #[Test]
    #[DataProvider('provideWrittenForms')]
    public function itNamesTheFormThatWasWritten(mixed $value, string $expected): void
    {
        self::assertSame($expected, RuleOptionValueForm::describeWritten($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideWrittenForms(): iterable
    {
        yield 'list' => [['a'], 'a list'];
        yield 'map' => [['a' => 'b'], 'a map'];
        yield 'string' => ['x', 'a string'];
        yield 'empty string' => ['   ', 'an empty string'];
        yield 'boolean' => [false, 'a boolean'];
        yield 'whole number' => [7331, 'a whole number'];
        yield 'fraction' => [1.9, 'a number'];
        yield 'null' => [null, 'null'];
    }

    #[Test]
    public function itRefusesAUnionOfOneForm(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least two alternatives');

        RuleOptionShape::either(RuleOptionShape::text());
    }

    #[Test]
    public function itAdmitsBareTextOnlyForTheDirectTextAndListPair(): void
    {
        $text = RuleOptionShape::text();
        $list = RuleOptionShape::listOf($text);
        $schema = (new RuleOptionSchemaProjection())->project(RuleOptionShape::either($text, $list));
        self::assertSame('a list or one element', $schema->describe());
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/scope.yaml');
        $reader = new LayerReading();
        foreach (['App\\', ['App\\']] as $written) {
            $layer = new AuthoredLayer($origin, AuthoredNode::fromPlain(['scope' => $written]));
            self::assertSame(['App\\'], $reader->readRoot(NodeSchema::map(['scope' => $schema]), $layer, 0)?->plain()['scope']);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The declared union has no document form.');
        (new RuleOptionSchemaProjection())->project(RuleOptionShape::either($text, RuleOptionShape::either($text, $list)));
    }

    #[Test]
    public function itAcceptsOnlyTheWordsAClosedSetNames(): void
    {
        $shape = RuleOptionShape::words(RuleOptionWordSet::of('info', 'warning', 'error'));

        self::assertTrue((new RuleOptionShapeMatcher())->matches($shape, 'warning'));
        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, 'warnin'));
        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, ''));
        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, 7331));
        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, ['warning']));
    }

    #[Test]
    public function itRefusesAClosedSetWordSpelledInAnotherLetterCase(): void
    {
        // The set used to fold case, and that made the declaration wider than
        // the reader it stands for: `scope: APPLICATION` passed the shape and
        // then fell back to the reader's default in silence -- the very defect
        // declaring a closed set exists to close.
        $shape = RuleOptionShape::words(RuleOptionWordSet::of('info', 'warning', 'error'));

        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, 'WARNING'));
        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, 'Error'));
        self::assertTrue((new RuleOptionShapeMatcher())->matches($shape, 'warning'));
    }

    #[Test]
    public function itNamesEveryWordOfAClosedSetInTheRefusal(): void
    {
        self::assertSame(
            'one of "info", "warning", "error"',
            (new RuleOptionShapeWording())->describe(RuleOptionShape::words(RuleOptionWordSet::of('info', 'warning', 'error'))),
        );
        self::assertSame(
            'one of "all", "application" or null',
            (new RuleOptionShapeWording())->describe(RuleOptionShape::words(RuleOptionWordSet::of('all', 'application'))->orNull()),
        );
    }

    #[Test]
    public function itKeepsTheWordsWhenTheClosedSetIsMadeNullable(): void
    {
        $shape = RuleOptionShape::words(RuleOptionWordSet::of('all', 'application'))->orNull();

        self::assertTrue((new RuleOptionShapeMatcher())->matches($shape, null));
        self::assertTrue((new RuleOptionShapeMatcher())->matches($shape, 'application'));
        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, 'applicaton'));
    }

    #[Test]
    public function itNamesTheWordThatWasWrittenWhenAClosedSetRefuses(): void
    {
        $shape = RuleOptionShape::words(RuleOptionWordSet::of('info', 'warning', 'error'));

        self::assertSame('"warnin"', (new RuleOptionShapeWording())->describeWritten($shape, 'warnin'));
        self::assertSame('a whole number', (new RuleOptionShapeWording())->describeWritten($shape, 7331));
        self::assertSame('an empty string', (new RuleOptionShapeWording())->describeWritten($shape, ''));
    }

    #[Test]
    public function itNamesAWrittenValueByItsFormForEveryShapeButAClosedSet(): void
    {
        self::assertSame('a string', (new RuleOptionShapeWording())->describeWritten(RuleOptionShape::text(), 'warnin'));
        self::assertSame('a list', (new RuleOptionShapeWording())->describeWritten(RuleOptionShape::listOf(RuleOptionShape::text()), ['a']));
    }

    /**
     * A built-in threshold or count is never negative, and a negative value
     * of the right type is refused by the form itself — so the recognition
     * walk and the threshold-shorthand unfolding, which both ask the form,
     * cannot disagree about it. Zero is inside the floor.
     */
    #[Test]
    public function itRefusesANegativeValueForABuiltInNumberAndKeepsZero(): void
    {
        self::assertFalse((new RuleOptionShapeMatcher())->matches(RuleOptionShape::integer(), -1));
        self::assertFalse((new RuleOptionShapeMatcher())->matches(RuleOptionShape::number(), -0.5));
        self::assertFalse((new RuleOptionShapeMatcher())->matches(RuleOptionShape::number(), -3));
        self::assertTrue((new RuleOptionShapeMatcher())->matches(RuleOptionShape::integer(), 0));
        self::assertTrue((new RuleOptionShapeMatcher())->matches(RuleOptionShape::number(), 0.0));
        self::assertFalse((new RuleOptionShapeMatcher())->matches(RuleOptionShape::listOf(RuleOptionShape::integer()), [1, -1]));
        self::assertFalse(RuleOptionValueForm::WholeNumber->accepts(-1));
        self::assertTrue(RuleOptionValueForm::WholeNumber->accepts(0));

        foreach ([[RuleOptionShape::number(), -0.5], [RuleOptionShape::integer(), -1]] as [$shape, $invalid]) {
            $schema = NodeSchema::map(['boundary' => (new RuleOptionSchemaProjection())->project($shape->atLeast(-1))]);
            $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/floor.yaml');
            $reader = new LayerReading();
            self::assertSame(0, $reader->readRoot($schema, new AuthoredLayer($origin, AuthoredNode::fromPlain(['boundary' => 0])), 0)?->plain()['boundary']);
            try {
                $reader->readRoot($schema, new AuthoredLayer($origin, AuthoredNode::fromPlain(['boundary' => $invalid])), 0);
                self::fail('An explicit minimum weakened the native non-negative form.');
            } catch (ConfigurationRefusal $error) {
                self::assertSame(\sprintf('"boundary" in configuration file "/floor.yaml" must be at least 0, got %s.', $invalid), $error->summary());
                self::assertSame(['boundary'], $error->position()?->segments);
            }
        }
    }

    /**
     * A computed metric's formula is written by the user and may be negative,
     * so its boundary keeps both signs.
     */
    #[Test]
    public function itKeepsBothSignsForASignedNumber(): void
    {
        $shape = RuleOptionShape::signedNumber()->orNull();

        self::assertTrue((new RuleOptionShapeMatcher())->matches($shape, -2.5));
        self::assertTrue((new RuleOptionShapeMatcher())->matches($shape, -2));
        self::assertTrue((new RuleOptionShapeMatcher())->matches($shape, 7));
        self::assertFalse((new RuleOptionShapeMatcher())->matches($shape, '-2'));
        self::assertSame('a number or null', (new RuleOptionShapeWording())->describe($shape));
    }

    #[Test]
    public function itAppliesAnExplicitSignedFloorToAuthoredDocumentValues(): void
    {
        $schema = NodeSchema::map(['boundary' => (new RuleOptionSchemaProjection())->project(RuleOptionShape::signedNumber()->atLeast(-1))]);
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/floor.yaml');
        $reader = new LayerReading();
        $valid = new AuthoredLayer($origin, AuthoredNode::fromPlain(['boundary' => -1]));
        self::assertSame(-1, $reader->readRoot($schema, $valid, 0)?->plain()['boundary']);

        try {
            $reader->readRoot($schema, new AuthoredLayer($origin, AuthoredNode::fromPlain(['boundary' => -2])), 0);
            self::fail('The authored value below its explicit signed floor was accepted.');
        } catch (ConfigurationRefusal $error) {
            self::assertSame('"boundary" in configuration file "/floor.yaml" must be at least -1, got -2.', $error->summary());
            self::assertSame(['/floor.yaml'], array_map(static fn(ConfigurationOrigin $source): ?string => $source->locator(), $error->sources()));
            self::assertSame(['boundary'], $error->position()?->segments);
        }
    }

    /**
     * The value is the answer to a range question, as the word is to a
     * membership question: "got a whole number" is true of `-1` and would
     * contradict the expectation printed beside it.
     */
    #[Test]
    public function itNamesTheValueThatWasOutOfRange(): void
    {
        self::assertSame('-1', (new RuleOptionShapeWording())->describeWritten(RuleOptionShape::integer()->orNull(), -1));
        self::assertSame('-0.5', (new RuleOptionShapeWording())->describeWritten(RuleOptionShape::number(), -0.5));
        self::assertSame(
            '-1',
            (new RuleOptionShapeWording())->describeWritten(RuleOptionShape::either(RuleOptionShape::integer(), RuleOptionShape::text()), -1),
        );
        self::assertSame('a string', (new RuleOptionShapeWording())->describeWritten(RuleOptionShape::integer(), '-1'));
        self::assertSame('a number', (new RuleOptionShapeWording())->describeWritten(RuleOptionShape::integer(), -1.5));
        self::assertSame('a non-negative number', (new RuleOptionShapeWording())->describe(RuleOptionShape::number()));
    }

    #[Test]
    public function itRefusesAnEmptyClosedSet(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least one word');

        RuleOptionShape::words(RuleOptionWordSet::of());
    }

    #[Test]
    public function itRefusesABlankWordInAClosedSet(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('blank word');

        RuleOptionShape::words(RuleOptionWordSet::of('info', '  '));
    }
}
