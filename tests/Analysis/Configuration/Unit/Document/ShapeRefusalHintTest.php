<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
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

/** A node's hint follows the engine's refusal of the form written at that node, and only there. */
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

    /** @param array<string, mixed> $document */
    private static function refusal(array $document): ConfigurationRefusal
    {
        $schema = new DocumentSchema([
            self::section('name', NodeSchema::scalar(ScalarForm::String)->withHint('Scalar hint.')),
            self::section('cache', NodeSchema::map(['dir' => NodeSchema::scalar(ScalarForm::String)])->withHint('Map hint.')),
            self::section('paths', NodeSchema::list(NodeSchema::scalar(ScalarForm::String)->withHint('Element hint.'))->withHint('List hint.')),
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
