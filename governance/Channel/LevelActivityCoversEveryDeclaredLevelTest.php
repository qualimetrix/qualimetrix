<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\Channel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection;
use Qualimetrix\Analysis\Finding\Selection\RuleEnablementResolver;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Two things know what levels a producer works at, and they must agree.
 *
 * The final options and enablement snapshot answers what can run; channels declare, through
 * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration::$levels},
 * where a producer reports. The directive audit reads the first and addresses
 * the second, so a pair the channels declare and the activity omits is read as
 * "this producer does not report there" — which silently turns a real
 * disablement into no answer at all.
 *
 * This is not hypothetical: writing the snapshot, five channels were missing
 * on the first attempt — `architecture.coverage-gap`,
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
 * "guarded by nothing" rather than as guarded here. Omitting a declared
 * validator pair from the enablement snapshot makes this test name the
 * missing channel and level.
 *
 * **Population.** An explicitly empty immutable computed-metric catalog
 * limits this guard to all statically declared channel/level pairs. Dynamic
 * computed channels are checked by their own option and definition tests.
 */
final class LevelActivityCoversEveryDeclaredLevelTest extends TestCase
{
    #[Test]
    public function itRecordsEveryLevelTheChannelsDeclare(): void
    {
        $container = (new ContainerFactory())->create();

        $universe = $container->get(ChannelUniverseInterface::class);
        self::assertInstanceOf(ChannelUniverseInterface::class, $universe);
        self::assertInstanceOf(RuleChannelSnapshotFactoryInterface::class, $universe);
        $universe = $universe->snapshot(new ResolvedComputedMetricDefinitions([]));

        $executor = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $executor);

        self::prepareEmptyInvocation($container, $executor, $universe);
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
        $universe = $container->get(ChannelUniverseInterface::class);
        self::assertInstanceOf(ChannelUniverseInterface::class, $universe);
        self::assertInstanceOf(RuleChannelSnapshotFactoryInterface::class, $universe);
        $universe = $universe->snapshot(new ResolvedComputedMetricDefinitions([]));
        self::prepareEmptyInvocation($container, $executor, $universe);

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
    private static function prepareEmptyInvocation(
        ContainerBuilder $container,
        RuleExecutionInterface $executor,
        ChannelUniverseInterface $universe,
    ): void {
        $document = DocumentComposer::compose(new DocumentSchema([
            new RulesSection($executor, 'rules'),
            new RulesSection($executor, 'only_rules'),
            new RulesSection($executor, 'disabled_rules'),
        ]), []);
        $empty = FindingConfiguration::none();
        $configuration = new FindingConfiguration(
            $empty->ruleOptions,
            $empty->cliOverrides,
            document: $document,
        );
        $resolver = new RuleEnablementResolver();
        $stated = $resolver->decide($document, $universe);
        $builder = $container->get(RuleOptionsBuild::class);
        self::assertInstanceOf(RuleOptionsBuild::class, $builder);
        $options = $builder->build($configuration, $stated);
        $registry = $container->get(RuleConfigurationInterface::class);
        self::assertInstanceOf(RuleConfigurationInterface::class, $registry);
        $registry->replace($configuration->withChannelUniverse($universe)->withResolvedOptions($options)
            ->withEnablement($resolver->conclude($stated, $options)));
    }

}
