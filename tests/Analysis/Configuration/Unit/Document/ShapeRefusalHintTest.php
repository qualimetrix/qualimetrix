<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\LiteralHint;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\Document\LayerReading;
use Qualimetrix\Analysis\Configuration\Document\ScalarConstraints;
use Qualimetrix\Analysis\Configuration\Document\WrittenForm;

/** A node's advice follows every engine refusal of a value written at that node, and only there. */
#[CoversClass(LayerReading::class)]
#[CoversClass(WrittenForm::class)]
#[CoversClass(ScalarConstraints::class)]
final class ShapeRefusalHintTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, non-empty-string}> */
    public static function provideMalformedValues(): iterable
    {
        yield 'a scalar of the wrong type' => [['name' => 5], '"name" in configuration file "/p/qmx.yaml" must be string, got int. Scalar hint.'];
        yield 'a collection for a scalar' => [['name' => ['a']], 'must be string, got a list. Scalar hint.'];
        yield 'a scalar for a map' => [['cache' => 'on'], 'must be a map, got string. Map hint.'];
        yield 'a list for a map' => [['cache' => ['on']], 'must be a map, got a list. Map hint.'];
        yield 'a scalar for a list' => [['paths' => 'src'], 'must be a list, got string. List hint.'];
        yield 'a map for a list' => [['paths' => ['a' => 'src']], 'must be a list, got a map. List hint.'];
        yield 'a list element' => [['paths' => [5]], '"paths[0]" in configuration file "/p/qmx.yaml" must be string, got int. Element hint.'];
    }

    /**
     * @param array<string, mixed> $document
     * @param non-empty-string $expected
     */
    #[Test]
    #[DataProvider('provideMalformedValues')]
    public function itAddsTheNodesHintToTheRefusalOfItsForm(array $document, string $expected): void
    {
        self::assertStringEndsWith($expected, self::refusal($document)->summary());
    }

    #[Test]
    public function itAddsNoHintToARefusalAboutAnotherNode(): void
    {
        $summary = self::refusal(['paths' => [null]])->summary();

        self::assertStringEndsWith('remove it or write a value.', $summary);
        self::assertStringNotContainsString('hint', $summary);
    }

    /** @param array<mixed> $collection */
    #[Test]
    #[TestWith([[]])]
    #[TestWith([[false]])]
    #[TestWith([['value' => false]])]
    public function itDoesNotTreatACollectionAsTheScalarItHoldsForAHint(array $collection): void
    {
        $schema = new DocumentSchema([self::section(
            'name',
            NodeSchema::scalar(ScalarForm::String)->withWording(literalHints: [new LiteralHint(false, 'False hint.')]),
        )]);

        try {
            DocumentComposer::compose($schema, [new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
                AuthoredNode::fromPlain(['name' => $collection]),
            )]);
            self::fail('A collection cannot be written for a string scalar.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringNotContainsString('False hint.', $refusal->summary());
        }
    }

    #[Test]
    public function itReadsABareBooleanAsTheDeclaredFieldWithoutTreatingNullAsFalse(): void
    {
        $schema = new DocumentSchema([self::section('rules', NodeSchema::namedMap(
            NodeSchema::map(['enabled' => NodeSchema::scalar(ScalarForm::Boolean)])->bareFor('enabled'),
            NameVocabulary::fixed(['X']),
        ))]);
        $document = DocumentComposer::compose($schema, [
            new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'first'), AuthoredNode::fromPlain(['rules' => ['X' => true]])),
            new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'), AuthoredNode::fromPlain(['rules' => ['X' => false]])),
        ]);

        self::assertSame(['enabled' => false], $document->get('rules', 'X')?->plain());
        self::assertSame(['rules', 'X'], $document->get('rules', 'X', 'enabled')?->contributors()[0]->path);

        $null = DocumentComposer::compose($schema, [new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
            AuthoredNode::fromPlain(['rules' => ['X' => null]]),
        )]);
        self::assertNull($null->get('rules', 'X')?->plain());
    }

    #[Test]
    public function itAcceptsOneBareElementOnlyWhereTheListDeclaresIt(): void
    {
        $schema = new DocumentSchema([
            self::section('selected', NodeSchema::list(NodeSchema::scalar(ScalarForm::String))->admittingBareElement()),
            self::section('strict', NodeSchema::list(NodeSchema::scalar(ScalarForm::String))),
        ]);
        $document = DocumentComposer::compose($schema, [new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
            AuthoredNode::fromPlain(['selected' => 'is']),
        )]);
        self::assertSame(['is'], $document->get('selected')?->plain());
        self::assertSame(['selected'], $document->get('selected', '0')?->contributors()[0]->path);

        try {
            DocumentComposer::compose($schema, [new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
                AuthoredNode::fromPlain(['strict' => 'is']),
            )]);
            self::fail('The other list has no bare-element declaration.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(['strict'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itJudgesRetiredKeysEvenWhenWrittenAsNull(): void
    {
        $schema = new DocumentSchema([self::section('settings', NodeSchema::map([
            'active' => NodeSchema::scalar(ScalarForm::Boolean),
        ])->retiring(['old_value' => 'Use "active" instead.']))]);

        try {
            DocumentComposer::compose($schema, [new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
                AuthoredNode::fromPlain(['settings' => ['oldValue' => null]]),
            )]);
            self::fail('A retired name is judged before a null value is discarded.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(['settings', 'oldValue'], $refusal->position()?->segments);
            self::assertStringContainsString('Use "active" instead.', $refusal->summary());
        }
    }

    #[Test]
    public function itJudgesClosedWordsNumericFloorsAndNonEmptyTextInEachLayer(): void
    {
        $schema = new DocumentSchema([
            self::section('mode', NodeSchema::scalar(ScalarForm::String)->words(SchemaWordSet::foldingCase('warn', 'error'))),
            self::section('count', NodeSchema::scalar(ScalarForm::Integer)->atLeast(1)),
            self::section('name', NodeSchema::scalar(ScalarForm::String)->nonEmpty()),
        ]);
        $good = DocumentComposer::compose($schema, [new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
            AuthoredNode::fromPlain(['mode' => 'WARN', 'count' => 1, 'name' => 'known']),
        )]);
        self::assertSame('WARN', $good->get('mode')?->plain());

        foreach ([['count' => 0], ['mode' => 'unknown'], ['name' => '  ']] as $bad) {
            try {
                DocumentComposer::compose($schema, [
                    new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'bad'), AuthoredNode::fromPlain($bad)),
                    new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'), AuthoredNode::fromPlain(['count' => 2, 'mode' => 'warn', 'name' => 'good'])),
                ]);
                self::fail('A malformed lower layer must be refused.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertSame('bad', $refusal->sources()[0]->locator());
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>, non-empty-string}> */
    public static function provideConstraintRefusals(): iterable
    {
        yield 'below the floor' => [['count' => 0], '"count" in configuration file "/p/qmx.yaml" must be at least 1, got 0. Count hint.'];
        yield 'blank text' => [['label' => ' '], '"label" in configuration file "/p/qmx.yaml" must be non-empty text. Label hint.'];
        yield 'a word outside the vocabulary' => [['mode' => 'loud'], '"mode" in configuration file "/p/qmx.yaml" must be one of warn, error, got "loud". Mode hint.'];
        yield 'a word with its own hint' => [['mode' => 'warning'], '"mode" in configuration file "/p/qmx.yaml" must be one of warn, error, got "warning". Write "warn".'];
        yield 'another case of a word with its own hint' => [['mode' => 'Warning'], 'got "Warning". Mode hint.'];
    }

    /**
     * @param array<string, mixed> $document
     * @param non-empty-string $expected
     */
    #[Test]
    #[DataProvider('provideConstraintRefusals')]
    public function itAddsTheAdviceForTheWrittenValueToAConstraintRefusal(array $document, string $expected): void
    {
        self::assertStringEndsWith($expected, self::constraintRefusal($document)->summary());
    }

    #[Test]
    public function itRefusesAWordOutsideTheVocabularyAtAClosedPositionNamingTheValue(): void
    {
        $position = self::constraintRefusal(['mode' => 'Loud'])->position();

        self::assertNotNull($position);
        self::assertSame(['mode'], $position->segments);
        self::assertSame('Loud', $position->written);
        self::assertSame(['warn', 'error'], $position->accepted);
        self::assertTrue($position->closed);
    }

    #[Test]
    public function itRefusesAListElementOutsideTheVocabularyAtItsIndex(): void
    {
        $position = self::constraintRefusal(['levels' => ['class', 'module']])->position();

        self::assertNotNull($position);
        self::assertSame(['levels', '1'], $position->segments);
        self::assertSame('module', $position->written);
        self::assertSame(['class', 'project'], $position->accepted);
        self::assertTrue($position->closed);
    }

    #[Test]
    public function itKeepsTheOtherConstraintRefusalsOpen(): void
    {
        foreach ([['count' => 0], ['label' => ' ']] as $document) {
            $position = self::constraintRefusal($document)->position();

            self::assertNotNull($position);
            self::assertSame([(string) array_key_first($document)], [$position->written]);
            self::assertFalse($position->closed);
        }
    }

    #[Test]
    public function itPublishesNoPositionForAWordAnUnpositionedLayerWrote(): void
    {
        try {
            DocumentComposer::compose(self::constraintSchema(), [new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--mode'),
                AuthoredNode::fromPlain(['mode' => 'loud']),
                positioned: false,
            )]);
            self::fail('A word outside the vocabulary was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertNull($refusal->position());
            self::assertStringEndsWith('must be one of warn, error, got "loud". Mode hint.', $refusal->summary());
        }
    }

    private static function constraintSchema(): DocumentSchema
    {
        return new DocumentSchema([
            self::section('count', NodeSchema::scalar(ScalarForm::Integer)->atLeast(1)->withWording(hint: 'Count hint.')),
            self::section('label', NodeSchema::scalar(ScalarForm::String)->nonEmpty()->withWording(hint: 'Label hint.')),
            self::section('mode', NodeSchema::scalar(ScalarForm::String)->words(SchemaWordSet::of('warn', 'error'))->withWording(
                hint: 'Mode hint.',
                literalHints: [new LiteralHint('warning', 'Write "warn".')],
            )),
            self::section('levels', NodeSchema::list(NodeSchema::scalar(ScalarForm::String)->words(SchemaWordSet::of('class', 'project')))),
        ]);
    }

    /** @param array<string, mixed> $document */
    private static function constraintRefusal(array $document): ConfigurationRefusal
    {
        try {
            DocumentComposer::compose(self::constraintSchema(), [new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
                AuthoredNode::fromPlain($document),
            )]);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('Expected a refusal.');
    }

    /** @param array<string, mixed> $document */
    private static function refusal(array $document): ConfigurationRefusal
    {
        $schema = new DocumentSchema([
            self::section('name', NodeSchema::scalar(ScalarForm::String)->withWording(hint: 'Scalar hint.')),
            self::section('cache', NodeSchema::map(['dir' => NodeSchema::scalar(ScalarForm::String)])->withWording(hint: 'Map hint.')),
            self::section('paths', NodeSchema::list(NodeSchema::scalar(ScalarForm::String)->withWording(hint: 'Element hint.'))->withWording(hint: 'List hint.')),
        ]);

        try {
            DocumentComposer::compose($schema, [new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
                AuthoredNode::fromPlain($document),
            )]);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('Expected a refusal.');
    }

    private static function section(string $key, NodeSchema $schema): DocumentSectionSchemaInterface
    {
        return new readonly class ($key, $schema) implements DocumentSectionSchemaInterface {
            public function __construct(private string $key, private NodeSchema $schema) {}

            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration($this->key, $this->schema);
            }
        };
    }
}
