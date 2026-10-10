<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaseDefinition;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Finding\Support\CorpusCaseRun;
use RuntimeException;

/**
 * The metric a channel *declares* it judges against the catalog value its
 * findings are *observed* publishing, over the external corpus in
 * `finding-gate/cases/`.
 *
 * The third of the three checks ADR 0046 puts on
 * {@see ChannelDeclaration::judging()}, and the only one a build cannot make.
 * Registry assembly already refuses a key the catalog does not publish, and
 * refuses any key at all from an `occurrence` producer — both are properties
 * of the declaration alone. Neither of them looks at a rule body, so a
 * declaration naming `complexity.ccn` on a channel whose rule publishes
 * `cohesion.lcom` builds cleanly and reports wrong numbers. That is what this
 * guard is for: it runs the product, reads each finding's `metricValue`, and
 * requires it to be one of the metrics the channel declared, measured on that
 * finding's own subject.
 *
 * **The comparison is not equality, and cannot be.** Published magnitude
 * can differ from the raw catalog value of a base key:
 *
 * - rounding — {@see \Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule}
 *   publishes `round($miValue, 1)`;
 * - a key chosen by configuration — {@see \Qualimetrix\Analysis\Evidence\Coupling\CboRule}
 *   reads `coupling.cbo` or `coupling.cbo-app` depending on its `scope`
 *   option, and a complexity channel reads a base key at callable level and
 *   that key's `.max` aggregate at class level.
 *
 * So the declaration names the **exact published spelling**, aggregate
 * strategy included, a channel may name more than one key and matching any
 * one of them is enough, and the values are compared within
 * {@see MAGNITUDE_TOLERANCE} rather than for identity.
 *
 * **What that buys, measured, and what it does not.** The rounding class is
 * genuinely exercised: `maintainability.mi` publishes 49.5 where the
 * catalog holds 49.4541…, so a strict equality here would be red.
 * `size.class-count` now judges its own namespace count; its published
 * subtree `.sum` remains a separate metric.
 *
 * The "any one candidate is enough" rule has two relevant limits here:
 *
 * - the three complexity channels name a base key at `method`/`function`
 *   level and its `.max` aggregate at `class`/`namespace` level. The lookup
 *   is subject-scoped; this description does not assert that their numeric
 *   values diverge in the current corpus;
 * - `coupling.cbo` is **not**: it names `coupling.cbo` and `coupling.cbo-app`,
 *   and on all twelve classes of the `coupling` corpus case the two hold the
 *   same number, because no fixture there depends on a symbol outside the
 *   case. A rule body reading the candidate its `scope` option did not select
 *   would pass this run unnoticed. Separating the two needs a corpus fixture
 *   with a vendor dependency — a change to the gate's input, which is its own
 *   declared step, not a side effect of this test.
 *
 * **What this guard does not cover — six, named, so the set cannot grow in
 * silence.** The same six the build-time half names, for the same reasons
 * (see `assertJudgedMetricsAreDeclarable()` in
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}):
 *
 * 1. `architecture.circular-dependency` — magnitude of its own making (a
 *    cycle's member count).
 * 2. `architecture.unassigned-class` — magnitude of its own making (a count
 *    of unassigned declarations).
 * 3. `duplication.clone` — magnitude of its own making (the lines one
 *    copy of a duplicated block covers, excluding comments and blank rows).
 * 4. `design.god-class` — magnitude of its own making (how many of its
 *    criteria matched).
 * 5. `coupling.class-rank` — the one this guard is silent over while a live
 *    catalog value is exactly what it publishes: it is deliberately
 *    `occurrence` (ADR 0017 point 5), so it declares no judged metric and
 *    nothing here compares its number.
 * 6. every channel of the computed-metric family — its vocabulary comes from
 *    a user's own configuration, so there is no declaration authored in the
 *    tree to disagree with.
 *
 * The first four and the sixth are outside by construction: they publish no
 * catalog metric, or none this repository declares. Only the fifth is a
 * standing trade, and it is recorded in ADR 0017 rather than overlooked.
 */
#[CoversClass(ChannelDeclaration::class)]
final class ChannelJudgedMetricDriftTest extends TestCase
{
    /**
     * How far a published magnitude may sit from the catalog value it is read
     * from before the two count as different numbers.
     *
     * Half of the last place kept by the coarsest rounding any judging
     * producer applies — `round($value, 1)` in
     * {@see \Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule},
     * which is the only one that rounds at all. It is a tolerance, not an
     * epsilon: it exists to let a rounded value match its source, and it is
     * wide enough that two catalog metrics of the same subject sitting within
     * 0.05 of each other would both satisfy it. That is the price of covering
     * rounding without asking each producer to declare its own, and it does
     * not weaken the property this guard is here for — a channel repointed at
     * a different metric moves its number by far more than this.
     *
     * The tolerance is one number for channels whose scales are not one, and
     * that asymmetry is the price. For an unbounded metric — CCN, CBO, NPath,
     * LOC — 0.05 is nothing. For the six judging channels whose metric lives
     * in [0, 1] — `coupling.distance`, `coupling.instability`,
     * `design.data-class` (judging `design.woc`) and the three
     * `design.type-coverage.*` — it is five percent of the whole scale, so a
     * published value drifting from its catalog value by less than that would
     * pass here. Making the tolerance a property of the channel would fix it,
     * at the price of asking every producer to declare a rounding it does not
     * do; today only one producer rounds at all.
     */
    private const float MAGNITUDE_TOLERANCE = 0.05;

    /**
     * Every observed finding of a channel that declares a judged metric.
     *
     * @var list<array{case: string, channel: string, subject: string, symbol: string, value: int|float}>|null
     */
    private static ?array $observed = null;

    /**
     * Case directory => canonical subject => metric key => value.
     *
     * @var array<string, array<string, array<string, int|float>>>|null
     */
    private static ?array $catalog = null;

    /**
     * @var array<string, list<string>>|null channel name => declared judged metric keys
     */
    private static ?array $judging = null;

    #[Test]
    public function itRequiresEveryFindingToPublishOneOfTheMetricsItsChannelDeclares(): void
    {
        $judging = self::judgingChannels();

        foreach (self::observe() as $finding) {
            $keys = $judging[$finding['channel']];
            $measured = self::measuredOn($finding);
            $candidates = [];

            foreach ($keys as $key) {
                if (!\array_key_exists($key, $measured)) {
                    continue;
                }

                $candidates[$key] = $measured[$key];

                if (abs($measured[$key] - $finding['value']) <= self::MAGNITUDE_TOLERANCE) {
                    continue 2;
                }
            }

            self::fail(\sprintf(
                'Channel "%s" published %s on subject "%s" (%s), but declares it judges [%s], which measured'
                . ' [%s] on that subject. The declaration and the rule body disagree about where the reported'
                . ' number comes from.',
                $finding['channel'],
                (string) $finding['value'],
                $finding['subject'],
                basename($finding['case']),
                implode(', ', $keys),
                $candidates === []
                    ? 'nothing — none of those keys exists on this subject'
                    : implode(', ', array_map(
                        static fn(string $key, int|float $value): string => $key . '=' . $value,
                        array_keys($candidates),
                        array_values($candidates),
                    )),
            ));
        }
    }

    /**
     * A channel no case reaches is a channel this guard says nothing about,
     * and nothing else would notice: the comparison above only walks findings
     * that happened. The corpus reaches all of them today, so the expectation
     * is the declared set itself — a new judging channel with no fixture
     * turns this red rather than joining a silently unchecked remainder.
     */
    #[Test]
    public function itRequiresTheCorpusToReachEveryChannelThatDeclaresAJudgedMetric(): void
    {
        $reached = [];

        foreach (self::observe() as $finding) {
            $reached[$finding['channel']] = true;
        }

        $reached = array_keys($reached);
        $declared = array_keys(self::judgingChannels());
        sort($reached);
        sort($declared);

        self::assertSame(
            $declared,
            $reached,
            'Some channel declaring a judged metric fires nowhere in finding-gate/cases. Its declaration is'
            . ' checked for spelling by registry assembly and by nothing else — add the fixture that reaches it.',
        );
    }

    /**
     * @return list<array{case: string, channel: string, subject: string, symbol: string, value: int|float}>
     */
    private static function observe(): array
    {
        self::measure();
        \assert(self::$observed !== null);

        return self::$observed;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function judgingChannels(): array
    {
        if (self::$judging !== null) {
            return self::$judging;
        }

        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        $judging = [];

        foreach ($registry->staticDeclarations() as $channel => $declaration) {
            if ($declaration->judges === null) {
                continue;
            }

            $judging[$channel] = $declaration->judges->keys;
        }

        return self::$judging = $judging;
    }

    /**
     * One pass over the corpus, feeding both halves of the comparison from
     * the same case: the findings, and the catalog every metric was measured
     * into. Two runs per case rather than one — the finding report and the
     * metric export are different formats — and deliberately so: reading the
     * catalog out of the same process that produced the finding would compare
     * a rule's answer against the object the rule was handed, not against
     * what the product publishes.
     */
    private static function measure(): void
    {
        if (self::$observed !== null) {
            return;
        }

        $judging = self::judgingChannels();
        $observed = [];
        $catalog = [];

        foreach (CorpusCaseRun::cases(CorpusCaseRun::repositoryRoot()) as $directory => $case) {
            if (!CorpusCaseRun::isAnalysis($case)) {
                continue;
            }
            $catalog[$directory] = self::indexMetrics($directory, $case);

            foreach (CorpusCaseRun::findings($directory, $case) as $finding) {
                $channel = $finding['channel'] ?? null;

                $subject = $finding['subject'] ?? null;
                $symbol = $finding['symbol'] ?? null;
                $value = $finding['metricValue'] ?? null;

                if (!\is_string($channel) || !isset($judging[$channel])) {
                    continue;
                }

                if (!\is_string($subject) || !\is_string($symbol)) {
                    throw new RuntimeException(\sprintf(
                        'A finding of channel "%s" in %s carries no subject or no symbol.',
                        $channel,
                        $directory,
                    ));
                }

                // Not an assertion about the corpus but about the channel: a
                // channel that names the metric its magnitude comes from and
                // then publishes no magnitude has already broken the relation
                // this guard exists to hold.
                self::assertIsNumeric(
                    $value,
                    \sprintf('Channel "%s" declares a judged metric but published no magnitude on "%s".', $channel, $subject),
                );
                \assert(\is_int($value) || \is_float($value));

                $observed[] = [
                    'case' => $directory,
                    'channel' => $channel,
                    'subject' => $subject,
                    'symbol' => $symbol,
                    'value' => $value,
                ];
            }
        }

        self::$catalog = $catalog;
        self::$observed = $observed;
    }

    /** @return array<string, array<string, int|float>> */
    private static function indexMetrics(string $directory, CaseDefinition $case): array
    {
        $index = [];

        foreach (CorpusCaseRun::metrics($directory, $case) as $symbol) {
            $subject = $symbol['subject'] ?? null;
            $metrics = $symbol['metrics'] ?? null;

            if (!\is_string($subject) || !\is_array($metrics)) {
                throw new RuntimeException(\sprintf('The metric export of %s carries a malformed symbol.', $directory));
            }

            /** @var array<string, int|float> $metrics */
            self::assertArrayNotHasKey($subject, $index, 'The metric export repeats an exact subject.');
            $index[$subject] = $metrics;
        }

        return $index;
    }

    /**
     * Everything the catalog measured on one finding's own subject.
     *
     * @param array{case: string, channel: string, subject: string, symbol: string, value: int|float} $finding
     *
     * @return array<string, int|float>
     */
    private static function measuredOn(array $finding): array
    {
        self::measure();
        \assert(self::$catalog !== null);

        $measured = self::$catalog[$finding['case']][$finding['subject']] ?? null;

        self::assertIsArray($measured, \sprintf(
            'The metric export of %s has no subject "%s", which channel "%s" reported a finding on. The two'
            . ' runs of the same case disagree about what exists.',
            basename($finding['case']),
            $finding['subject'],
            $finding['channel'],
        ));

        return $measured;
    }

}
