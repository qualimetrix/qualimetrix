<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Two things know what levels a producer works at, and they must agree.
 *
 * The rules answer {@see \Qualimetrix\Analysis\Finding\Rule\RuleInterface::levelActivity()}
 * for what ran; the channels declare, through
 * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration::$levels},
 * where a producer reports. The directive audit reads the first and addresses
 * the second, so a pair the channels declare and the activity omits is read as
 * "this producer does not report there" — which silently turns a real
 * disablement into no answer at all.
 *
 * This is not hypothetical: writing the snapshot, five channels were missing
 * on the first attempt — `architecture.coverage`,
 * `architecture.unreachable-layer`, `architecture.potential-shadow`,
 * `architecture.empty-template` and `architecture.pending-layer-matched`, all
 * at project level. They belong to `architecture.layer-violation` but are
 * declared by its configuration validator rather than by the rule class, so a
 * rule that answers only for its own declarations never mentions them.
 *
 * **This guard is not in `directives:controls`, and deliberately so.** That
 * stand judges a probe by whether one of its 81 directive cases reddens, and
 * the channels this guard is about are `architecture.*` at project level, which
 * no case in that population carries a directive for: the probe was measured
 * there and missed its case, which would have recorded the mutation as
 * "guarded by nothing" rather than as guarded here. Removing the completion in
 * `RuleExecution::levelActivity()` reddens this test with the five channel
 * names in its message; that was verified by doing it.
 *
 * **Population.** The container this builds has no `computed_metrics`
 * configuration, so the computed family contributes no definitions and the
 * check covers the producers that have a rule class — 44 of the 51 names
 * `allRules()` reports. The `health.*` seven are covered where their record is
 * actually decided, in
 * {@see \Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit\ComputedMetricRuleTest::itRecordsOneProducerAsSwitchedOffWithoutItsNeighbours()}.
 * Saying "every producer" here without that sentence would claim seven names
 * this test never sees.
 */
final class LevelActivityCoversEveryDeclaredLevelTest extends TestCase
{
    #[Test]
    public function itRecordsEveryLevelTheChannelsDeclare(): void
    {
        $container = (new ContainerFactory())->create();

        $universe = $container->get(ChannelUniverseInterface::class);
        self::assertInstanceOf(ChannelUniverseInterface::class, $universe);

        $executor = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $executor);

        $activity = $executor->levelActivity();

        $missing = [];

        foreach ($universe->channels() as $channel) {
            $producer = $universe->producerOf($channel->code);

            if ($producer === null) {
                continue;
            }

            foreach ($universe->levelsOf($channel->code) as $level) {
                if (!$activity->declares($producer, $level)) {
                    $missing[] = \sprintf('%s (producer %s) at %s', $channel->code, $producer, $level->value);
                }
            }
        }

        self::assertSame([], $missing, implode("\n", $missing));
    }

    /**
     * The other direction, which the coverage check above cannot see: a record
     * that declared every pair and set them all to `false` would satisfy the
     * check above while reporting the whole product as switched off, and every
     * directive in the tree as unmeasured.
     *
     * So the pairs that are off with no configuration at all are named, with
     * why, and the list is checked in both directions: an unnamed one means the
     * record started reading a live producer as disabled, and a named one that
     * is no longer off means the list has begun to rot.
     *
     * @var array<string, string>
     */
    private const array OFF_BY_DEFAULT = [
        'complexity.npath at class' =>
            'ClassNpathComplexityOptions defaults to enabled: false — the class-level NPath boundary is'
            . ' opt-in, and the callable level beside it is on',
        'architecture.unassigned-class at project' =>
            'UnassignedClassOptions derives isEnabled() from mode, which defaults to Ignore: one key is'
            . ' the gate, so the channel is silent until an author picks a mode',
    ];

    #[Test]
    public function itRecordsALevelAsNotRunOnlyWhereADefaultSwitchesItOff(): void
    {
        $container = (new ContainerFactory())->create();

        $executor = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $executor);

        $off = [];

        foreach ($executor->levelActivity()->toMap() as $producer => $levels) {
            foreach ($levels as $level => $ran) {
                if (!$ran) {
                    $off[] = $producer . ' at ' . $level;
                }
            }
        }

        sort($off);
        $named = array_keys(self::OFF_BY_DEFAULT);
        sort($named);

        self::assertSame($named, $off);
    }
}
