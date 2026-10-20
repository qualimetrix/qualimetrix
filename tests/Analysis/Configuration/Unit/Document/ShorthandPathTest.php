<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\Document\LayerReading;
use Qualimetrix\Analysis\Configuration\Document\ShorthandExpansion;
use Qualimetrix\Analysis\Configuration\Document\ShorthandTarget;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\SampleDocument;

#[CoversClass(LayerReading::class)]
#[CoversClass(ShorthandTarget::class)]
#[CoversClass(ShorthandExpansion::class)]
#[CoversClass(NodeSchema::class)]
final class ShorthandPathTest extends TestCase
{
    #[Test]
    public function itExpandsThroughAChildShorthandWithCanonicalJudgementAndAuthoredProvenance(): void
    {
        $judged = [];
        $number = NodeSchema::scalar(ScalarForm::Integer)->judgedInEachLayer(
            static function (ResolvedValueInterface $value, array $path) use (&$judged): void {
                $judged[] = $path;
            },
        );
        $schema = self::schema(NodeSchema::map([
            'callable' => NodeSchema::map(['warning' => $number, 'error' => $number], Shorthand::spreading('threshold', ['warning', 'error'])),
        ], Shorthand::spreading('threshold', ['callable.threshold'])));

        $layer = new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'),
            AuthoredNode::mapping(['rules' => AuthoredNode::mapping(['threshold' => AuthoredNode::scalar(5, 12)])]),
        );
        $document = DocumentComposer::compose($schema, [$layer]);

        self::assertSame(['callable' => ['warning' => 5, 'error' => 5]], $document->get('rules')?->plain());
        self::assertSame([['rules', 'callable', 'warning'], ['rules', 'callable', 'error']], $judged);
        foreach (['warning', 'error'] as $key) {
            $leaf = $document->get('rules', 'callable', $key);
            self::assertInstanceOf(ResolvedWriteHistoryInterface::class, $leaf);
            self::assertSame(['rules', 'threshold'], $leaf->writes()[0]['provenance']->path);
            self::assertSame(12, $leaf->writes()[0]['provenance']->line);
        }
    }

    #[Test]
    public function itRefusesLeafOverlapAcrossTwoDictionariesInOneLayer(): void
    {
        $schema = self::schema(self::classOptions());

        try {
            DocumentComposer::compose($schema, [SampleDocument::file(['rules' => [
                'warning' => 3,
                'class' => ['threshold' => 5],
            ]])]);
            self::fail('The two written paths reach class.warning.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('"warning" and "class.threshold"', $refusal->summary());
            self::assertSame(['rules', 'warning'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itRefusesAShortcutAgainstAFullNestedLeaf(): void
    {
        $schema = self::schema(self::classOptions());

        try {
            DocumentComposer::compose($schema, [SampleDocument::file(['rules' => [
                'warning' => 3,
                'class' => ['warning' => 5],
            ]])]);
            self::fail('The direct nested leaf and shorthand reach the same path.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('"warning" and "class.warning"', $refusal->summary());
            self::assertSame(['rules', 'warning'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itAllowsAShortcutBesideADifferentNestedLeaf(): void
    {
        $number = NodeSchema::scalar(ScalarForm::Integer);
        $schema = self::schema(NodeSchema::map([
            'callable' => NodeSchema::map([
                'warning' => $number,
                'error' => $number,
                'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
            ], Shorthand::spreading('threshold', ['warning', 'error'])),
        ], Shorthand::spreading('threshold', ['callable.threshold'])));

        $document = DocumentComposer::compose($schema, [SampleDocument::file(['rules' => [
            'threshold' => 5,
            'callable' => ['enabled' => true],
        ]])]);

        self::assertSame(['callable' => ['enabled' => true, 'warning' => 5, 'error' => 5]], $document->get('rules')?->plain());
    }

    #[Test]
    public function itMergesTheSameLeafWhenDifferentLayersWriteIt(): void
    {
        $document = DocumentComposer::compose(self::schema(self::classOptions()), [
            SampleDocument::preset(['rules' => ['warning' => 3]]),
            SampleDocument::file(['rules' => ['class' => ['threshold' => 5]]]),
        ]);

        $leaf = $document->get('rules', 'class', 'warning');
        self::assertInstanceOf(ResolvedWriteHistoryInterface::class, $leaf);
        self::assertSame(5, $leaf->plain());
        self::assertSame([3, 5], array_column($leaf->writes(), 'value'));
    }

    #[Test]
    public function itRejectsACycleAtDeclaration(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Shorthand cycle');

        NodeSchema::map(
            ['warning' => NodeSchema::scalar(ScalarForm::Integer)],
            Shorthand::spreading('first', ['second']),
            Shorthand::spreading('second', ['first']),
        );
    }

    #[Test]
    public function itRefusesMalformedShortcutInTheLayerThatWroteIt(): void
    {
        try {
            DocumentComposer::compose(self::schema(self::classOptions()), [
                SampleDocument::preset(['rules' => ['warning' => 'bad']]),
                SampleDocument::file(['rules' => ['class' => ['warning' => 5]]]),
            ]);
            self::fail('The lower layer must be judged before the merge.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(['rules', 'warning'], $refusal->position()?->segments);
            self::assertSame('strict', $refusal->sources()[0]->locator());
        }
    }

    private static function classOptions(): NodeSchema
    {
        $number = NodeSchema::scalar(ScalarForm::Integer);

        return NodeSchema::map(
            [
                'class' => NodeSchema::map(['warning' => $number, 'error' => $number], Shorthand::spreading('threshold', ['warning', 'error'])),
            ],
            Shorthand::spreading('warning', ['class.warning']),
            Shorthand::spreading('threshold', ['class.threshold']),
        );
    }

    private static function schema(NodeSchema $entry): DocumentSchema
    {
        return new DocumentSchema([new readonly class ($entry) implements DocumentSectionSchemaInterface {
            public function __construct(private NodeSchema $entry) {}

            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('rules', $this->entry);
            }
        }]);
    }
}
