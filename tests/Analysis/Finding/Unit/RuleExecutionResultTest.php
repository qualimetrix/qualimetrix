<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionAttribution;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(RuleExecutionResult::class)]
final class RuleExecutionResultTest extends TestCase
{
    /**
     * `$excludedFindings` and `$attributions` are index-aligned: entry `i` of
     * the second says why entry `i` of the first was removed. A merge that
     * concatenates one list and not the other leaves every excluded finding
     * without a reason while the counters still say findings were excluded.
     */
    #[Test]
    public function itMergesIndependentPopulationPartitionsWithoutDoublingAdoption(): void
    {
        $left = new \Qualimetrix\Analysis\Finding\Population\PopulationTrace();
        $right = new \Qualimetrix\Analysis\Finding\Population\PopulationTrace();
        foreach (['zeta', 'alpha'] as $producer) {
            foreach (range(0, 5) as $ordinal) {
                $identity = \Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity::occurrence($producer, $ordinal);
                $left->record($producer, new \Qualimetrix\Analysis\Finding\Contract\FindingChannel($producer), \Qualimetrix\Core\Symbol\SymbolLevel::Project, $identity, 'present', 'Missing publication.');
                $right->record($producer, new \Qualimetrix\Analysis\Finding\Contract\FindingChannel($producer), \Qualimetrix\Core\Symbol\SymbolLevel::Project, $identity, 'present', 'Missing publication.');
            }
        }
        $first = new RuleExecutionResult([], [], new RuleExclusionStats(), LevelActivity::empty(), population: $left->freeze());
        $second = new RuleExecutionResult([], [], new RuleExclusionStats(), LevelActivity::empty(), population: $right->freeze());
        $merged = $first->merge($second)->merge($first);
        self::assertSame(24, $merged->population->unjudgedCount());
        self::assertSame(['alpha', 'zeta'], array_column($merged->population->abstentions(), 'producer'));
        self::assertSame([12, 12], array_column($merged->population->abstentions(), 'count'));
        self::assertCount(5, $merged->population->abstentions()[0]->examples);
        self::assertSame(12, $first->population->unjudgedCount());
    }

    #[Test]
    public function itMergesAttributionsAlongsideTheFindingsTheyExplain(): void
    {
        $left = $this->finding('src/Left.php');
        $right = $this->finding('src/Right.php');
        $leftWhy = new RuleExclusionAttribution('rule1', isPathExclusion: true);
        $rightWhy = new RuleExclusionAttribution('rule2', isPathExclusion: false);

        $merged = $this->withExclusion($left, $leftWhy, pathExclusion: true)
            ->merge($this->withExclusion($right, $rightWhy, pathExclusion: false));

        self::assertSame([$left, $right], $merged->exclusions->excludedFindings);
        self::assertSame([$leftWhy, $rightWhy], $merged->exclusions->attributions);
        self::assertSame(['rule1' => 1], $merged->exclusions->pathExclusionsByRule);
        self::assertSame(['rule2' => 1], $merged->exclusions->namespaceExclusionsByRule);
    }

    private function withExclusion(Finding $finding, RuleExclusionAttribution $why, bool $pathExclusion): RuleExecutionResult
    {
        return new RuleExecutionResult(
            produced: [$finding],
            published: [],
            exclusions: new RuleExclusionStats(
                namespaceExclusionsByRule: $pathExclusion ? [] : [$why->producerRuleName => 1],
                pathExclusionsByRule: $pathExclusion ? [$why->producerRuleName => 1] : [],
                excludedFindings: [$finding],
                attributions: [$why],
            ),
            levelActivity: LevelActivity::empty(),
        );
    }

    private function finding(string $file): Finding
    {
        $path = RelativePath::fromString($file);

        return new Finding(
            location: new Location($path, 1),
            symbolPath: SymbolPath::forFile($path),
            subject: MetricSubject::aggregate(SymbolPath::forFile($path)),
            ruleName: 'rule1',
            code: 'rule1',
            message: 'excluded',
            severity: Severity::Warning,
        );
    }
}
