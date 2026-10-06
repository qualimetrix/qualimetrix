<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\BooleanArgumentOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Finding\FindingPublication;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(BooleanArgumentRule::class)]
#[CoversClass(FindingPublication::class)]
final class BooleanArgumentSelectionTest extends TestCase
{
    /** @param array<string, int|string> $entry */
    #[Test]
    #[DataProvider('subjectEntries')]
    public function itPublishesBooleanArgumentsFromAnonymousAndNamedMethods(array $entry): void
    {
        $file = RelativePath::fromString('src/Example.php');
        $fileSymbol = SymbolPath::forFile($file);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([new SymbolInfo($fileSymbol, $file, null)]);
        $repository->method('get')->willReturn((new MetricBag())->withEntry('codeSmell.boolean_argument', $entry));

        $rule = new BooleanArgumentRule(new BooleanArgumentOptions(allowedPrefixes: []));
        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);
        self::assertSame($findings[0]->subject->toSymbolPath()->toString(), $findings[0]->symbolPath->toString());

        $decisions = [];
        foreach (BooleanArgumentRule::channelDeclarations()[BooleanArgumentRule::NAME]->levels as $level) {
            $decisions[] = new EnablementDecision(
                new SelectionCellAddress(BooleanArgumentRule::NAME, new FindingChannel(BooleanArgumentRule::NAME), $level, ChannelSelectionRole::Selectable),
                new AuthoredCellDecision(CellSwitch::On, CellAdmission::Direct),
            );
        }
        $enablement = new RuleEnablement($decisions, null);
        $universe = self::createStub(ChannelUniverseInterface::class);
        $universe->method('producerOf')->willReturn(BooleanArgumentRule::NAME);
        $configuration = self::createStub(RuleConfigurationInterface::class);
        $configuration->method('channelUniverse')->willReturn($universe);
        $publication = new FindingPublication($configuration);
        $publication->begin();
        $removed = [];
        self::assertSame($findings, $publication->published(BooleanArgumentRule::NAME, $findings, $enablement, null, $removed));
        self::assertSame([], $removed);
    }

    /** @return iterable<string, array{array<string, int|string>}> */
    public static function subjectEntries(): iterable
    {
        yield 'anonymous class method' => [[
            'subjectKind' => 'file', 'line' => 3, 'extra' => 'secret',
        ]];
        yield 'named method' => [[
            'subjectKind' => 'declaration', 'logicalKind' => 'method',
            'namespace' => 'App', 'class' => 'Example', 'member' => 'run',
            'line' => 4, 'extra' => 'flag',
        ]];
    }
}
