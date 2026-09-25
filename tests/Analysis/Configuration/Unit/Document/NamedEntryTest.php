<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedBareName;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\RefusedName;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\Document\LayerMerge;
use Qualimetrix\Analysis\Configuration\Document\LayerReading;
use Qualimetrix\Analysis\Configuration\Document\NameRecognition;

/**
 * An entry of a named map: its name is judged whatever is written under it,
 * and a name written with no body stays in the document as a bare name,
 * which any body a layer wrote stands over.
 */
#[CoversClass(LayerReading::class)]
#[CoversClass(LayerMerge::class)]
#[CoversClass(NameRecognition::class)]
#[CoversClass(ResolvedBareName::class)]
#[CoversClass(NameVocabulary::class)]
final class NamedEntryTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function provideBodilessEntries(): iterable
    {
        yield 'tilde' => [null];
        yield 'empty map' => [[]];
        yield 'map of nothing but tilde' => [['warning' => null]];
    }

    #[Test]
    #[DataProvider('provideBodilessEntries')]
    public function itKeepsANameWrittenWithoutABodyAsABareNameWithItsWriter(mixed $body): void
    {
        $document = self::compose(self::file(['metrics' => ['computed.mine' => $body]]));
        $entry = $document->get('metrics', 'computed.mine');

        self::assertInstanceOf(ResolvedBareName::class, $entry);
        self::assertSame(['computed.mine' => null], $document->get('metrics')?->plain());
        self::assertSame(['metrics', 'computed.mine'], $entry->contributors()[0]->path);
        self::assertSame('/p/qmx.yaml', $entry->refusal('No formula.')->origin()->locator());
    }

    #[Test]
    public function itLetsALowerLayersBodyStandUnderABareName(): void
    {
        $document = self::compose(
            self::preset(['layers' => ['domain', 'infra'], 'metrics' => ['computed.mine' => ['warning' => 5]], 'allow' => ['infra' => ['domain']]]),
            self::file(['metrics' => ['computed.mine' => null], 'allow' => ['infra' => null]]),
        );

        self::assertSame(['warning' => 5], $document->get('metrics', 'computed.mine')?->plain());
        self::assertSame(['domain'], $document->get('allow', 'infra')?->plain(), 'A bare name does not replace a list.');
    }

    #[Test]
    public function itLetsAHigherLayersBodyStandOverABareName(): void
    {
        $document = self::compose(
            self::preset(['metrics' => ['computed.mine' => null]]),
            self::file(['metrics' => ['computed.mine' => ['warning' => 3]]]),
        );

        self::assertSame(['warning' => 3], $document->get('metrics', 'computed.mine')?->plain());
    }

    #[Test]
    public function itNamesEveryLayerThatWroteANameAloneWhenNoneGaveItABody(): void
    {
        $entry = self::compose(
            self::preset(['metrics' => ['computed.mine' => null]]),
            self::file(['metrics' => ['computed.mine' => []]]),
        )->get('metrics', 'computed.mine');

        self::assertInstanceOf(ResolvedBareName::class, $entry);
        self::assertSame(
            ['strict', '/p/qmx.yaml'],
            array_map(static fn(Provenance $writer): ?string => $writer->origin->locator(), $entry->contributors()),
        );
    }

    #[Test]
    #[DataProvider('provideBodilessEntries')]
    public function itJudgesAPredicateNameInTheLayerThatWroteItWhateverItsBody(mixed $body): void
    {
        $refusal = self::refusal(static fn() => self::compose(
            self::preset(['metrics' => ['health.typng' => $body]]),
            self::file(['metrics' => ['health.typng' => ['warning' => 3]]]),
        ));

        self::assertSame('"health.typng" is no health dimension.', $refusal->summary());
        self::assertSame([ConfigurationSource::Preset], array_map(static fn(ConfigurationOrigin $origin): ConfigurationSource => $origin->source(), $refusal->sources()));
        self::assertSame(['metrics', 'health.typng'], $refusal->position()?->segments);
        self::assertSame(['health.typing'], $refusal->position()->accepted);
    }

    #[Test]
    public function itGivesAnOpenPositionToANameRefusedByAnOpenGrammar(): void
    {
        $refusal = self::refusal(static fn() => self::compose(self::file(['metrics' => ['my-metric' => null]])));

        self::assertSame('"my-metric" breaks the grammar.', $refusal->summary());
        self::assertFalse($refusal->position()?->closed);
    }

    #[Test]
    public function itRefusesAPredicateNameOnTheCommandLineByItsOption(): void
    {
        $layer = new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine),
            AuthoredNode::mapping(['metrics' => AuthoredNode::fromPlain(['my-metric' => null], '--metric')]),
            false,
        );

        $refusal = self::refusal(static fn() => self::compose($layer));

        self::assertNull($refusal->position());
        self::assertSame('--metric', $refusal->origin()->locator());
    }

    #[Test]
    public function itJudgesASiblingDrawnNameWrittenWithoutABody(): void
    {
        $refusal = self::refusal(static fn() => self::compose(
            self::file(['layers' => ['domain', 'infra'], 'allow' => ['infrq' => null]]),
        ));

        self::assertSame(['allow', 'infrq'], $refusal->position()?->segments);
        self::assertSame(['domain', 'infra'], $refusal->position()->accepted);
    }

    #[Test]
    public function itAdmitsASiblingDrawnNameTheVocabularysJudgeRefersToTheDeclaredOnes(): void
    {
        $document = self::compose(self::file(['layers' => ['domain'], 'allow' => ['dom*' => ['domain'], 'domain' => null]]));

        self::assertSame(['dom*' => ['domain'], 'domain' => null], $document->get('allow')?->plain());
    }

    #[Test]
    public function itComparesASiblingDrawnNameExactlyWithoutAJudge(): void
    {
        $vocabulary = NameVocabulary::fromSibling('layers', static fn(mixed $layers): array => []);

        self::assertTrue($vocabulary->admits('domain', ['domain']));
        self::assertFalse($vocabulary->admits('dom*', ['domain']));
        self::assertFalse($vocabulary->admits('Domain', ['domain']));
    }

    #[Test]
    public function itJudgesAPredicateNameInsideAListItem(): void
    {
        $schema = new DocumentSchema([self::section('groups', NodeSchema::list(NodeSchema::namedMap(
            NodeSchema::scalar(ScalarForm::Number),
            NameVocabulary::predicate(static fn(string $name): ?RefusedName => $name === 'ok' ? null : RefusedName::open('Not ok.')),
        )))]);

        $refusal = self::refusal(static fn() => DocumentComposer::compose($schema, [self::file(['groups' => [['ok' => 1, 'bad' => null]]])]));

        self::assertSame('Not ok.', $refusal->summary());
        self::assertSame(['groups', '0', 'bad'], $refusal->position()?->segments);
    }

    private static function compose(AuthoredLayer ...$layers): ResolvedDocument
    {
        return DocumentComposer::compose(new DocumentSchema([
            self::section('layers', NodeSchema::stringList()),
            self::section('allow', NodeSchema::namedMap(
                NodeSchema::stringList(),
                NameVocabulary::fromSibling(
                    'layers',
                    static fn(mixed $layers): array => \is_array($layers) ? array_values(array_filter($layers, 'is_string')) : [],
                    static fn(string $name, array $known): bool => str_ends_with($name, '*') || \in_array($name, $known, true),
                ),
            )),
            self::section('metrics', NodeSchema::namedMap(
                NodeSchema::map(['warning' => NodeSchema::scalar(ScalarForm::Number)]),
                NameVocabulary::predicate(static fn(string $name): ?RefusedName => match (true) {
                    $name === 'health.typing', str_starts_with($name, 'computed.') => null,
                    str_starts_with($name, 'health.') => RefusedName::among(\sprintf('"%s" is no health dimension.', $name), ['health.typing']),
                    default => RefusedName::open(\sprintf('"%s" breaks the grammar.', $name)),
                }),
            )),
        ]), array_values($layers));
    }

    /** @param Closure(): mixed $action */
    private static function refusal(Closure $action): ConfigurationRefusal
    {
        try {
            $action();
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('Expected a refusal.');
    }

    /** @param array<string, mixed> $document */
    private static function preset(array $document): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict'), AuthoredNode::fromPlain($document));
    }

    /** @param array<string, mixed> $document */
    private static function file(array $document): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/p/qmx.yaml'), AuthoredNode::fromPlain($document));
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
