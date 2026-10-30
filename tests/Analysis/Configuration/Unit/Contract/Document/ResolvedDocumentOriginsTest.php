<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\SampleDocument;

#[CoversClass(ResolvedDocument::class)]
final class ResolvedDocumentOriginsTest extends TestCase
{
    #[Test]
    public function itNamesNoOriginForAnEmptyDocument(): void
    {
        self::assertSame([], ResolvedDocument::empty()->origins());
    }

    #[Test]
    public function itKeepsTheWriterOfAReplacedScalarAndAReplacedList(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['fail_on' => 'warning'], 'scalar'),
            SampleDocument::preset(['only_rules' => ['complexity']], 'list'),
            SampleDocument::file(['fail_on' => 'error', 'only_rules' => []]),
        );

        self::assertSame(['configuration file "/p/qmx.yaml"', 'preset "list"', 'preset "scalar"'], self::described($document));
    }

    #[Test]
    public function itReachesMapEntriesListItemsAndAccumulatedSets(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['cache' => ['dir' => '/tmp']], 'map'),
            SampleDocument::preset(['architecture' => ['layers' => [['name' => 'Core', 'patterns' => ['App\\Core']]]]], 'item'),
            SampleDocument::preset(['exclude' => ['a']], 'set-a'),
            SampleDocument::file(['exclude' => ['b']]),
        );

        self::assertSame(['configuration file "/p/qmx.yaml"', 'preset "item"', 'preset "map"', 'preset "set-a"'], self::described($document));
    }

    #[Test]
    public function itReachesEveryLayerAnOpaqueValueKeeps(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['rules' => ['size.loc' => ['enabled' => false]]], 'opaque'),
            SampleDocument::file(['rules' => ['size.loc' => ['enabled' => true]]]),
        );

        self::assertSame(['configuration file "/p/qmx.yaml"', 'preset "opaque"'], self::described($document));
    }

    #[Test]
    public function itNamesEachOriginOnce(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::file(['fail_on' => 'error', 'paths' => ['src'], 'cache' => ['dir' => '/tmp', 'enabled' => true]]),
        );

        self::assertSame(['configuration file "/p/qmx.yaml"'], self::described($document));
    }

    /** @return list<string> sorted, since the order is not promised */
    private static function described(ResolvedDocument $document): array
    {
        $described = array_map(static fn(ConfigurationOrigin $origin): string => $origin->describe(), $document->origins());
        self::assertSame(array_values(array_unique($described)), $described);
        sort($described);

        return $described;
    }
}
