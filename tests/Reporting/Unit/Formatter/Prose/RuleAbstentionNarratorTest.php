<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Prose;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Population\PopulationTrace;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Reporting\Contract\Report\Report;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Qualimetrix\Reporting\Formatter\Prose\RuleAbstentionNarrator;

#[CoversClass(RuleAbstentionNarrator::class)]
final class RuleAbstentionNarratorTest extends TestCase
{
    #[Test]
    #[\PHPUnit\Framework\Attributes\TestWith(['html'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['github'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['sarif'])]
    public function itDoesNotPresentAnIntentionalPopulationBoundaryAsIncomplete(string $format): void
    {
        $trace = new PopulationTrace();
        $trace->record('fixture.rule', new FindingChannel('fixture.channel'), SymbolLevel::Class_, PopulationIdentity::selector('outside', 'declaration'), 'minimum', 'Configured minimum excludes this class.', false);
        $report = \Qualimetrix\Reporting\ReportBuilder::create()->population($trace->freeze())->build();
        $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
        $registry = $container->get(\Qualimetrix\Reporting\Contract\Formatting\FormatterRegistryInterface::class);
        \assert($registry instanceof \Qualimetrix\Reporting\Contract\Formatting\FormatterRegistryInterface);
        $body = $registry->get($format)->format($report, new \Qualimetrix\Reporting\Contract\Formatting\FormatterContext())->body;

        self::assertStringNotContainsString('Selected judgement incomplete', $body);
        self::assertStringNotContainsString('data-qmx-population="incomplete"', $body);
        self::assertStringNotContainsString('::notice title=rule-population.incomplete::', $body);
    }

    #[Test]
    public function itSeparatesUnitsInOneCompactIndicationAndPublishesEveryTypedGroupWhenVerbose(): void
    {
        $trace = new PopulationTrace();
        $channel = new FindingChannel('fixture.channel');
        $trace->record('fixture.rule', $channel, SymbolLevel::Class_, PopulationIdentity::selector('healthy', 'declaration'), null, null);
        for ($i = 0; $i < 7; $i++) {
            $trace->record('fixture.rule', $channel, SymbolLevel::Class_, PopulationIdentity::selector('class:' . $i, 'declaration'), 'published', 'Value absent.');
        }
        $trace->record('fixture.rule', $channel, SymbolLevel::Class_, PopulationIdentity::invocation('fixture.rule'), 'graph', 'Graph unavailable.');
        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0,
            population: $trace->freeze(),
        );
        self::assertSame(['Rule population incomplete — declaration: 1 judged, 7 not judged; invocation: 0 judged, 1 not judged.'], RuleAbstentionNarrator::lines($report));
        $verbose = RuleAbstentionNarrator::verboseLines($report);
        self::assertCount(3, $verbose);
        self::assertSame('  fixture.rule / fixture.channel (class), gate graph: Graph unavailable. — 1 invocation judgement(s) not judged; examples: fixture.rule', $verbose[1]);
        self::assertSame('  fixture.rule / fixture.channel (class), gate published: Value absent. — 7 declaration judgement(s) not judged; examples: class:0, class:1, class:2, class:3, class:4', $verbose[2]);
        self::assertStringNotContainsString('8 not judged', $verbose[0]);
    }

    #[Test]
    public function itCountsOnlyMissingEvidenceWhenTheSameGateAlsoExcludesDefinedAbsence(): void
    {
        $trace = new PopulationTrace();
        $channel = new FindingChannel('fixture.channel');
        $trace->record('fixture.rule', $channel, SymbolLevel::Class_, PopulationIdentity::selector('interface', 'declaration'), 'published', 'Value absent.', false);
        $trace->record('fixture.rule', $channel, SymbolLevel::Class_, PopulationIdentity::selector('broken-class', 'declaration'), 'published', 'Value absent.');
        $population = $trace->freeze();
        self::assertSame(2, $population->unjudgedCount());
        self::assertCount(2, $population->abstentions());
        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0,
            population: $population,
        );
        self::assertSame(['Rule population incomplete — declaration: 0 judged, 1 not judged.'], RuleAbstentionNarrator::lines($report));
    }

    #[Test]
    public function itLeavesEmptyAndHealthyReportsQuietAndUsesNativeProseByteEscaping(): void
    {
        self::assertSame([], RuleAbstentionNarrator::verboseLines(new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0,
        )));
        $trace = new PopulationTrace();
        $channel = new FindingChannel('fixture.channel');
        $trace->record('fixture.rule', $channel, SymbolLevel::Project, PopulationIdentity::selector('project', 'project'), null, null);
        self::assertSame([], RuleAbstentionNarrator::verboseLines(new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0,
            population: $trace->freeze(),
        )));
        $unknown = new PopulationTrace();
        $unknown->record('fixture.rule', $channel, SymbolLevel::Project, PopulationIdentity::selector("project:\xFF", 'project'), 'published', 'Value absent.');
        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0,
            population: $unknown->freeze(),
        );
        $published = ProseText::publish(implode("\n", RuleAbstentionNarrator::verboseLines($report)), GlyphMode::Unicode);
        self::assertSame(1, $published->escapedStrings);
        self::assertStringContainsString('project:%FF', $published->body);
        self::assertTrue(mb_check_encoding($published->body, 'UTF-8'));
    }
}
