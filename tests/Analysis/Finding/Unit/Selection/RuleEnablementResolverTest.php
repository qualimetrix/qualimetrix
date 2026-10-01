<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Selection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\SelectionRecord;
use Qualimetrix\Analysis\Finding\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(RuleEnablementResolver::class)]
final class RuleEnablementResolverTest extends TestCase
{
    #[Test]
    public function itLetsTheHigherLayerOutrankAProblemFromTheMoreSpecificLowerLayer(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['complexity.alpha' => ['enabled' => true]]]],
            ['source' => 'preset', 'values' => ['disabled_rules' => ['complexity.*']]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        self::assertFalse($enablement->runs('complexity.alpha'));
        self::assertFalse($enablement->runs('complexity.beta'));
        self::assertSame('disabled_rules[0]: complexity.*', $enablement->decisionFor('complexity.alpha')->statement);
        self::assertSame(1, $enablement->decisionFor('complexity.alpha')->rank());
    }

    #[Test]
    public function itUsesTheMoreSpecificStatementInsideOneLayer(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => [
                'rules' => ['complexity.alpha' => ['enabled' => true]],
                'disabled_rules' => ['complexity.*'],
            ]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        self::assertTrue($enablement->runs('complexity.alpha'));
        self::assertFalse($enablement->runs('complexity.beta'));
        self::assertSame('rules.complexity.alpha.enabled: true', $enablement->decisionFor('complexity.alpha')->statement);
        self::assertSame(0, $enablement->decisionFor('complexity.alpha')->rank());
    }

    #[Test]
    public function itClearsTheOnlyFilterWhenAHighLayerWritesAnEmptyList(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['only_rules' => ['complexity.alpha']]],
            ['source' => 'preset', 'values' => ['only_rules' => []]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        self::assertNull($enablement->filter());
        self::assertTrue($enablement->runs('complexity.alpha'));
        self::assertTrue($enablement->runs('complexity.beta'));
    }

    #[Test]
    public function itLetsAnUpperDisableNarrowAnEarlierFilterWhileAnotherCellLives(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['only_rules' => ['complexity.alpha', 'complexity.beta']]],
            ['source' => 'preset', 'values' => ['disabled_rules' => ['complexity.alpha']]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        self::assertFalse($enablement->runs('complexity.alpha'));
        self::assertTrue($enablement->runs('complexity.beta'));
        self::assertSame(['complexity.alpha', 'complexity.beta'], $enablement->filter()?->selectors);
        self::assertSame('disabled_rules[0]: complexity.alpha', $enablement->decisionFor('complexity.alpha')->statement);
        self::assertSame(1, $enablement->decisionFor('complexity.alpha')->rank());
    }

    #[Test]
    public function itAttributesFinalModeActivityToTheUpperWrittenLayer(): void
    {
        $metadata = [new RuleMetadata('architecture.unassigned-class', UnassignedClassOptions::class, 'Unassigned', [], false)];
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['architecture.unassigned-class' => ['mode' => 'warn']]]],
            ['source' => 'preset', 'values' => ['rules' => ['architecture.unassigned-class' => ['mode' => 'ignore']]]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $decision = $ready->enablement?->decisionFor('architecture.unassigned-class');
        self::assertNotNull($decision);
        self::assertTrue($decision->on);
        self::assertFalse($decision->live());
        self::assertSame('rules.architecture.unassigned-class.mode: ignore', $decision->activity->written);
        self::assertSame(1, $decision->activity->rank());
        $writer = $decision->activity->decidedBy;
        self::assertNotNull($writer);
        self::assertSame('rules.architecture.unassigned-class.mode', $writer->displayPath());
        self::assertSame(ConfigurationSource::Preset, $writer->origin->source());
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        $records = $enablement->notRun();
        self::assertCount(1, $records);
        self::assertSame(
            [['disabled', 'rules.architecture.unassigned-class.mode: ignore', 'preset "fixture"']],
            array_map(static fn(SelectionRecord $record): array => [$record->reason, $record->statement, $record->layer], $records),
        );
    }

    #[Test]
    public function itNamesTheWinningOnlyFilterForANotRunProducer(): void
    {
        $metadata = self::metadata();
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['only_rules' => ['complexity.alpha']]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        $records = $enablement->notRun();
        self::assertCount(1, $records);
        self::assertSame(
            [['complexity.beta', 'filtered', 'only_rules: [complexity.alpha]', 'configuration file "/project/qmx.yaml"']],
            array_map(static fn(SelectionRecord $record): array => [$record->producer, $record->reason, $record->statement, $record->layer], $records),
        );
    }

    /** @return list<RuleMetadata> */
    private static function metadata(): array
    {
        return [
            new RuleMetadata('complexity.alpha', CodeSmellOptions::class, 'Alpha', [], false),
            new RuleMetadata('complexity.beta', CodeSmellOptions::class, 'Beta', [], false),
        ];
    }
}
