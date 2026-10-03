<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Contract\Rule;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;

#[\PHPUnit\Framework\Attributes\CoversClass(\Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues::class)]
final class ResolvedRuleOptionValuesTest extends TestCase
{
    #[Test]
    public function itDefaultsOnlyUnwrittenValuesAndKeepsFalseZeroAndEmptyCollections(): void
    {
        $values = new ResolvedRuleOptionValues(self::document(['enabled' => false, 'count' => 0, 'text' => '', 'names' => [], 'map' => []]), 'fixture');
        self::assertFalse($values->boolean('enabled', true));
        self::assertSame(0, $values->integer('count', 9));
        self::assertSame(0, $values->number('count', 0.5));
        self::assertSame('', $values->text('text', 'default'));
        self::assertSame([], $values->strings('names', ['default']));
        self::assertNull($values->map('map'));
        self::assertSame(13, $values->atLevel('class')->integer('count', 13));
        self::assertNull($values->atLevel('class')->node('count'));
    }

    #[Test]
    public function itNarrowsActualNodeTypesInsteadOfCoercingMixedValues(): void
    {
        $values = new ResolvedRuleOptionValues(self::document(['text' => 'true']), 'fixture');
        self::expectException(LogicException::class);
        self::expectExceptionMessage('violates its declared value type');
        $values->boolean('text', false);
    }

    #[Test]
    public function itRefusesAnUndeclaredResolvedRead(): void
    {
        $values = new ResolvedRuleOptionValues(self::document([]), 'fixture');
        self::expectException(LogicException::class);
        self::expectExceptionMessage('rules.fixture.unknown');
        $values->integer('unknown', 1);
    }

    #[Test]
    public function itAppliesTheDecidedRootEnablementWithoutRewritingAuthoredValues(): void
    {
        $document = self::document(['enabled' => false, 'class' => ['enabled' => false, 'count' => 7]]);
        $values = (new ResolvedRuleOptionValues($document, 'fixture'))->withEnabled(true);

        self::assertTrue($values->boolean('enabled', false));
        self::assertFalse($values->atLevel('class')->boolean('enabled', true));
        self::assertSame(7, $values->atLevel('class')->integer('count', 0));
        self::assertFalse($document->get('rules', 'fixture', 'enabled')?->plain());
        self::assertFalse((new ResolvedRuleOptionValues($document, 'fixture'))->boolean('enabled', true));
    }

    /** @param array<string, mixed> $values */
    private static function document(array $values): ResolvedDocument
    {
        $fields = [
            'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
            'count' => NodeSchema::scalar(ScalarForm::Integer),
            'text' => NodeSchema::scalar(ScalarForm::String),
            'names' => NodeSchema::list(NodeSchema::scalar(ScalarForm::String)),
            'map' => NodeSchema::map([]),
            'class' => NodeSchema::map([
                'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
                'count' => NodeSchema::scalar(ScalarForm::Integer),
            ]),
        ];
        $section = new class ($fields) implements DocumentSectionSchemaInterface {
            /** @param array<string, NodeSchema> $fields */
            public function __construct(private readonly array $fields) {}
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('rules', NodeSchema::namedMap(NodeSchema::map($this->fields), NameVocabulary::fixed(['fixture'])));
            }
        };
        return DocumentComposer::compose(new DocumentSchema([$section]), [new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml'),
            AuthoredNode::fromPlain(['rules' => ['fixture' => $values]]),
        )]);
    }
}
