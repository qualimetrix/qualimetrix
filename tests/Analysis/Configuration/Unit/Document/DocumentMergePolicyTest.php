<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaqueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\LayerMerge;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedScalar;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\SampleDocument;

/**
 * One test per row of the merge-policy table: what each declared policy does
 * with a preset under a configuration file, and which layer each surviving
 * value is attributed to.
 */
#[CoversClass(DocumentComposer::class)]
#[CoversClass(LayerMerge::class)]
final class DocumentMergePolicyTest extends TestCase
{
    #[Test]
    public function itLetsTheLastLayerThatWritesAScalarWinIt(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['fail_on' => 'warning']),
            SampleDocument::file(['fail_on' => 'error']),
        );

        $leaf = self::scalar($document->get('fail_on'));
        self::assertSame('error', $leaf->value);
        self::assertSame(ConfigurationSource::ConfigFile, $leaf->provenance->origin->source());
        self::assertSame(['fail_on'], $leaf->provenance->path);
    }

    #[Test]
    public function itMergesAMapKeyByKeyAndNamesEveryContributor(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['cache' => ['dir' => '/tmp/c', 'enabled' => true]]),
            SampleDocument::file(['cache' => ['enabled' => false]]),
        );

        self::assertSame(['dir' => '/tmp/c', 'enabled' => false], $document->get('cache')?->plain());
        self::assertSame('strict', self::scalar($document->get('cache', 'dir'))->provenance->origin->locator());
        self::assertSame('/p/qmx.yaml', self::scalar($document->get('cache', 'enabled'))->provenance->origin->locator());
        self::assertSame(['strict', '/p/qmx.yaml'], self::locators($document->get('cache')));
    }

    #[Test]
    public function itReadsAWrittenEmptyMapAsChangingNothing(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['cache' => ['dir' => '/tmp/c']]),
            SampleDocument::file(['cache' => []]),
        );

        self::assertSame(['dir' => '/tmp/c'], $document->get('cache')?->plain());
        self::assertSame(['strict'], self::locators($document->get('cache')));
    }

    #[Test]
    public function itExpandsAShorthandInItsOwnLayerBeforeTheMerge(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['computed_metrics' => ['health.x' => ['formula' => 'a + b', 'threshold' => 5]]]),
            SampleDocument::file(['computed_metrics' => ['health.x' => ['warning' => 3]]]),
        );

        self::assertSame(
            ['formula' => 'a + b', 'warning' => 3, 'error' => 5],
            $document->get('computed_metrics', 'health.x')?->plain(),
        );
        $error = self::scalar($document->get('computed_metrics', 'health.x', 'error'));
        self::assertSame(['computed_metrics', 'health.x', 'threshold'], $error->provenance->path);
        self::assertSame('strict', $error->provenance->origin->locator());
        self::assertSame('/p/qmx.yaml', self::scalar($document->get('computed_metrics', 'health.x', 'warning'))->provenance->origin->locator());
    }

    #[Test]
    public function itReplacesAListWholeWithTheLastWrittenOne(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['paths' => ['src', 'lib']]),
            SampleDocument::file(['paths' => ['app']]),
        );

        self::assertSame(['app'], $document->get('paths')?->plain());
        self::assertSame(['/p/qmx.yaml'], self::locators($document->get('paths')));
    }

    #[Test]
    public function itReplacesAListWithAWrittenEmptyList(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['paths' => ['src']]),
            SampleDocument::file(['paths' => []]),
        );

        self::assertSame([], $document->get('paths')?->plain());
        self::assertSame([], $document->diagnostics(), 'Only a list declared to announce it says anything.');
    }

    #[Test]
    public function itAccumulatesASetAcrossLayersAndKeepsEachItemsWriter(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['exclude' => ['vendor', 'build']]),
            SampleDocument::file(['exclude' => ['build', 'var']]),
        );

        $set = $document->get('exclude');
        self::assertInstanceOf(ResolvedListInterface::class, $set);
        self::assertSame(['vendor', 'build', 'var'], $set->plain());
        self::assertSame(
            ['strict', 'strict', '/p/qmx.yaml'],
            array_map(static fn(ResolvedValueInterface $item): ?string => self::scalar($item)->provenance->origin->locator(), $set->items()),
        );
        self::assertSame(['strict', '/p/qmx.yaml'], self::locators($set));
    }

    #[Test]
    public function itAddsNothingToASetForAWrittenEmptyList(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['exclude' => ['vendor']]),
            SampleDocument::file(['exclude' => []]),
        );

        self::assertSame(['vendor'], $document->get('exclude')?->plain());
    }

    #[Test]
    public function itMergesComputedMetricsByNameKeepingEveryPresetMetric(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['computed_metrics' => [
                'health.x' => ['formula' => 'a', 'threshold' => 5],
                'health.y' => ['formula' => 'b'],
            ]]),
            SampleDocument::file(['computed_metrics' => [
                'health.x' => ['enabled' => false],
                'health.z' => ['formula' => 'c'],
            ]]),
        );

        self::assertSame(
            [
                'health.x' => ['formula' => 'a', 'warning' => 5, 'error' => 5, 'enabled' => false],
                'health.y' => ['formula' => 'b'],
                'health.z' => ['formula' => 'c'],
            ],
            $document->get('computed_metrics')?->plain(),
        );
    }

    #[Test]
    public function itMergesAllowByLayerNameAndReplacesTheTargetsOfEachLayer(): void
    {
        $layers = ['layers' => [['name' => 'domain'], ['name' => 'app'], ['name' => 'infra']]];

        $document = SampleDocument::compose(
            SampleDocument::preset(['architecture' => $layers + ['allow' => ['infra' => ['domain'], 'app' => ['domain']]]]),
            SampleDocument::file(['architecture' => ['allow' => ['infra' => ['app']]]]),
        );

        self::assertSame(['infra' => ['app'], 'app' => ['domain']], $document->get('architecture', 'allow')?->plain());
        self::assertSame(['/p/qmx.yaml'], self::locators($document->get('architecture', 'allow', 'infra')));
    }

    #[Test]
    public function itAnnouncesAnEmptyListThatLiftsALowerLayersFilter(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['only_rules' => ['complexity']]),
            SampleDocument::file(['only_rules' => []]),
        );

        self::assertSame([], $document->get('only_rules')?->plain());
        self::assertCount(1, $document->diagnostics());
        $diagnostic = $document->diagnostics()[0];
        self::assertStringContainsString('"only_rules" is written empty in configuration file "/p/qmx.yaml"', $diagnostic->message);
        self::assertStringContainsString('preset "strict"', $diagnostic->message);
        self::assertStringContainsString('Every rule runs.', $diagnostic->message);
        self::assertSame(['strict', '/p/qmx.yaml'], array_map(static fn(Provenance $source): ?string => $source->origin->locator(), $diagnostic->sources));
    }

    #[Test]
    public function itSaysNothingWhenAHigherLayerWritesTheFilterAgain(): void
    {
        // The warning describes the merged document: a filter the command line
        // writes over the file's empty list is the filter the run applies.
        $document = SampleDocument::compose(
            SampleDocument::preset(['only_rules' => ['complexity']]),
            SampleDocument::file(['only_rules' => []]),
            SampleDocument::cli(['only_rules' => ['size']], ['only_rules' => '--only-rule']),
        );

        self::assertSame(['size'], $document->get('only_rules')?->plain());
        self::assertSame([], $document->diagnostics());
    }

    #[Test]
    public function itNamesTheLiftedFilterWhenAnotherEmptyListIsWrittenAbove(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['only_rules' => ['complexity']]),
            SampleDocument::preset(['only_rules' => []], 'relaxed'),
            SampleDocument::file(['only_rules' => []]),
        );

        self::assertCount(1, $document->diagnostics());
        self::assertSame(
            ['strict', '/p/qmx.yaml'],
            array_map(static fn(Provenance $source): ?string => $source->origin->locator(), $document->diagnostics()[0]->sources),
        );
    }

    #[Test]
    public function itSaysNothingWhenAnEmptyListReplacesNothing(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['only_rules' => []]),
            SampleDocument::file(['only_rules' => []]),
        );

        self::assertSame([], $document->diagnostics());
    }

    #[Test]
    public function itKeepsAnOpaqueSubtreePerLayerForItsOwner(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['rules' => ['size.loc' => ['warning' => 1]]]),
            SampleDocument::file(['rules' => ['size.loc' => ['error' => 2]]]),
        );

        $rules = $document->get('rules');
        self::assertInstanceOf(ResolvedOpaqueInterface::class, $rules);
        self::assertSame([['size.loc' => ['warning' => 1]], ['size.loc' => ['error' => 2]]], $rules->plain());
        self::assertSame(['strict', '/p/qmx.yaml'], self::locators($rules));
    }

    #[Test]
    public function itRefusesAMergedNodeNamingEveryContributingLayer(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['cache' => ['dir' => '/tmp/c']]),
            SampleDocument::file(['cache' => ['enabled' => false]]),
        );

        try {
            $document->get('cache')?->refuse('cache is inconsistent');
            self::fail('refuse() must throw');
        } catch (ConfigurationRefusal $refusal) {
        }

        self::assertSame(['strict', '/p/qmx.yaml'], array_map(static fn($origin): ?string => $origin->locator(), $refusal->sources()));
        self::assertSame(['cache'], $refusal->position()?->segments);
    }

    #[Test]
    public function itRefusesAWinningLeafNamingOnlyTheLayerThatWonIt(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['memory_limit' => '1G']),
            SampleDocument::file(['memory_limit' => 'lots']),
        );

        try {
            $document->get('memory_limit')?->refuse('not a size');
            self::fail('refuse() must throw');
        } catch (ConfigurationRefusal $refusal) {
        }

        self::assertSame(['/p/qmx.yaml'], array_map(static fn($origin): ?string => $origin->locator(), $refusal->sources()));
        self::assertCount(1, $refusal->sources());
        self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
        self::assertSame(['memory_limit'], $refusal->position()?->segments);
    }

    #[Test]
    public function itKeepsAnExplicitlyPositionlessRefusal(): void
    {
        $document = SampleDocument::compose(SampleDocument::file(['memory_limit' => 'lots']));
        $writers = self::scalar($document->get('memory_limit'))->contributors();

        self::assertSame(['memory_limit'], Provenance::refusalOf($writers, 'not a size')->position()?->segments);
        self::assertNull(Provenance::refusalOf($writers, 'not a size', null)->position());
    }

    private static function scalar(?ResolvedValueInterface $value): ResolvedScalar
    {
        self::assertInstanceOf(ResolvedScalar::class, $value);

        return $value;
    }

    /** @return list<?string> */
    private static function locators(?ResolvedValueInterface $value): array
    {
        self::assertNotNull($value);

        return array_map(static fn(Provenance $writer): ?string => $writer->origin->locator(), $value->contributors());
    }
}
