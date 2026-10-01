<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedBareNameInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\SampleDocument;

#[CoversClass(NodeSchema::class)]
#[CoversClass(DocumentComposer::class)]
final class NamedMapOfTest extends TestCase
{
    #[Test]
    public function itReadsAndMergesEachNameUsingItsOwnSchema(): void
    {
        $schema = self::schema(NameVocabulary::fixed(['alpha', 'beta', 'accumulated', 'replaced']));
        $document = DocumentComposer::compose($schema, [
            SampleDocument::preset(['rules' => [
                'alpha' => ['warning' => 3],
                'beta' => ['enabled' => false],
                'accumulated' => ['old', 'shared'],
                'replaced' => ['old', 'shared'],
            ]]),
            SampleDocument::file(['rules' => [
                'alpha' => ['error' => 5],
                'beta' => ['enabled' => true],
                'accumulated' => ['shared', 'new'],
                'replaced' => ['new'],
            ]]),
        ]);

        self::assertSame(['warning' => 3, 'error' => 5], $document->get('rules', 'alpha')?->plain());
        self::assertSame(['enabled' => true], $document->get('rules', 'beta')?->plain());
        self::assertSame(5, $document->get('rules', 'alpha', 'error')?->plain());
        self::assertSame(true, $document->get('rules', 'beta', 'enabled')?->plain());
        self::assertSame(['old', 'shared', 'new'], $document->get('rules', 'accumulated')?->plain());
        self::assertSame('new', $document->get('rules', 'accumulated', '2')?->plain());
        self::assertSame(['new'], $document->get('rules', 'replaced')?->plain());
        self::assertSame('new', $document->get('rules', 'replaced', '0')?->plain());
        self::assertNull($document->get('rules', 'replaced', '1'));
    }

    #[Test]
    public function itKeepsAUnknownBareNameButRejectsAChildWithoutAnEntrySchema(): void
    {
        $document = DocumentComposer::compose(self::schema(NameVocabulary::predicate(static fn(string $name) => null)), [
            SampleDocument::file(['rules' => ['unknown' => null]]),
        ]);

        self::assertInstanceOf(ResolvedBareNameInterface::class, $document->get('rules', 'unknown'));
        self::assertNull($document->get('rules', 'unwritten'));

        $this->expectException(LogicException::class);
        $document->get('rules', 'unknown', 'enabled');
    }

    #[Test]
    public function itMergesAnUnknownBareNameAcrossLayersWithoutLosingItsWriters(): void
    {
        $document = DocumentComposer::compose(self::schema(NameVocabulary::predicate(static fn(string $name) => null)), [
            SampleDocument::preset(['rules' => ['unknown' => null]]),
            SampleDocument::file(['rules' => ['unknown' => null]]),
        ]);

        $entry = $document->get('rules', 'unknown');
        self::assertInstanceOf(ResolvedBareNameInterface::class, $entry);
        self::assertSame([0, 1], array_map(static fn($writer): int => $writer->layerIndex, $entry->contributors()));
        self::assertSame(['strict', '/p/qmx.yaml'], array_map(static fn($writer): ?string => $writer->origin->locator(), $entry->contributors()));
        self::assertSame([['rules', 'unknown'], ['rules', 'unknown']], array_map(static fn($writer): ?array => $writer->path, $entry->contributors()));
    }

    #[Test]
    public function itRefusesABodyWhoseNameHasNoSchema(): void
    {
        foreach ([['enabled' => true], false] as $body) {
            try {
                DocumentComposer::compose(self::schema(NameVocabulary::predicate(static fn(string $name) => null)), [
                    SampleDocument::file(['rules' => ['unknown' => $body]]),
                ]);
                self::fail('A body needs its name-specific schema.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertSame(['rules', 'unknown'], $refusal->position()?->segments);
                self::assertStringContainsString('No value schema', $refusal->summary());
            }
        }
    }

    #[Test]
    public function itJudgesAFixedNameEvenWhenItsBodyIsNull(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        DocumentComposer::compose(self::schema(NameVocabulary::fixed(['alpha'])), [
            SampleDocument::file(['rules' => ['typo' => null]]),
        ]);
    }

    private static function schema(NameVocabulary $names): DocumentSchema
    {
        $entry = NodeSchema::namedMapOf(
            static fn(string $name): ?NodeSchema => match ($name) {
                'alpha' => NodeSchema::map([
                    'warning' => NodeSchema::scalar(ScalarForm::Integer),
                    'error' => NodeSchema::scalar(ScalarForm::Integer),
                ]),
                'beta' => NodeSchema::map(['enabled' => NodeSchema::scalar(ScalarForm::Boolean)]),
                'accumulated' => NodeSchema::set(NodeSchema::scalar(ScalarForm::String)),
                'replaced' => NodeSchema::list(NodeSchema::scalar(ScalarForm::String)),
                default => null,
            },
            $names,
        );

        return new DocumentSchema([new readonly class ($entry) implements DocumentSectionSchemaInterface {
            public function __construct(private NodeSchema $entry) {}

            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('rules', $this->entry);
            }
        }]);
    }
}
