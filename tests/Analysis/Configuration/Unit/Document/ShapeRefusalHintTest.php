<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\Document\LayerReading;
use Qualimetrix\Analysis\Configuration\Document\WrittenForm;

/** A node's hint follows the engine's refusal of the form written at that node, and only there. */
#[CoversClass(LayerReading::class)]
#[CoversClass(WrittenForm::class)]
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

            public function key(): string
            {
                return $this->key;
            }

            public function schema(): NodeSchema
            {
                return $this->schema;
            }
        };
    }
}
