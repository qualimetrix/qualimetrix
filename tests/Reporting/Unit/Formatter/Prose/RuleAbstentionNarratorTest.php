<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Prose;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Population\PopulationTrace;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Qualimetrix\Reporting\Formatter\Prose\RuleAbstentionNarrator;
use Qualimetrix\Reporting\Report;

#[CoversClass(RuleAbstentionNarrator::class)]
final class RuleAbstentionNarratorTest extends TestCase
{
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
        $report = new Report([], 0, 0, 0, 0, 0, population: $trace->freeze());
        self::assertSame(['Rule population incomplete — declaration: 1 judged, 7 not judged; invocation: 0 judged, 1 not judged.'], RuleAbstentionNarrator::lines($report));
        $verbose = RuleAbstentionNarrator::verboseLines($report);
        self::assertCount(3, $verbose);
        self::assertSame('  fixture.rule / fixture.channel (class), gate graph: Graph unavailable. — 1 invocation judgement(s) not judged; examples: fixture.rule', $verbose[1]);
        self::assertSame('  fixture.rule / fixture.channel (class), gate published: Value absent. — 7 declaration judgement(s) not judged; examples: class:0, class:1, class:2, class:3, class:4', $verbose[2]);
        self::assertStringNotContainsString('8 not judged', $verbose[0]);
    }

    #[Test]
    public function itLeavesEmptyAndHealthyReportsQuietAndUsesNativeProseByteEscaping(): void
    {
        self::assertSame([], RuleAbstentionNarrator::verboseLines(new Report([], 0, 0, 0, 0, 0)));
        $trace = new PopulationTrace();
        $channel = new FindingChannel('fixture.channel');
        $trace->record('fixture.rule', $channel, SymbolLevel::Project, PopulationIdentity::selector('project', 'project'), null, null);
        self::assertSame([], RuleAbstentionNarrator::verboseLines(new Report([], 0, 0, 0, 0, 0, population: $trace->freeze())));
        $unknown = new PopulationTrace();
        $unknown->record('fixture.rule', $channel, SymbolLevel::Project, PopulationIdentity::selector("project:\xFF", 'project'), 'published', 'Value absent.');
        $report = new Report([], 0, 0, 0, 0, 0, population: $unknown->freeze());
        $published = ProseText::publish(implode("\n", RuleAbstentionNarrator::verboseLines($report)), GlyphMode::Unicode);
        self::assertSame(1, $published->escapedStrings);
        self::assertStringContainsString('project:%FF', $published->body);
        self::assertTrue(mb_check_encoding($published->body, 'UTF-8'));
    }
}
