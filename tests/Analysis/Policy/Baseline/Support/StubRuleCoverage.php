<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Support;

use LogicException;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\OptionActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;

/**
 * A {@see RunRuleCoverage} with a known answer, where every channel is
 * produced by the rule of the same name.
 *
 * The baseline tests are about what a command says once it knows which rules
 * ran, not about how the run decided it; that decision belongs to rule
 * execution and is exercised there.
 */
final class StubRuleCoverage
{
    private function __construct() {}

    public static function everyRuleRan(): RunRuleCoverage
    {
        return self::withSkipped();
    }

    /**
     * @param list<string> $notSelected producers a selector left out of the run
     * @param list<string> $disabledEverywhere producers configuration switched off at every level
     */
    public static function withSkipped(array $notSelected = [], array $disabledEverywhere = []): RunRuleCoverage
    {
        return new RunRuleCoverage(
            self::execution($notSelected, $disabledEverywhere),
            self::channelIsItsOwnProducer(),
        );
    }

    /**
     * @param list<string> $notSelected
     * @param list<string> $disabledEverywhere
     */
    private static function execution(array $notSelected, array $disabledEverywhere): RuleExecutionInterface
    {
        $decisions = [];
        $universe = self::universe();
        foreach ($universe->channels() as $channel) {
            $producer = $universe->producerOf($channel->code);
            if ($producer === null) {
                continue;
            }
            foreach (SymbolLevel::cases() as $level) {
                $decisions[] = new EnablementDecision(
                    $producer,
                    $channel,
                    $level,
                    true,
                    !\in_array($producer, $notSelected, true),
                    ChannelSelectionRole::Selectable,
                    null,
                    null,
                    new OptionActivity(!\in_array($producer, $disabledEverywhere, true)),
                );
            }
        }
        $enablement = new RuleEnablement($decisions, null);

        return new readonly class ($notSelected, $disabledEverywhere, $enablement) implements RuleExecutionInterface {
            /**
             * @param list<string> $notSelected
             * @param list<string> $disabledEverywhere
             */
            public function __construct(private array $notSelected, private array $disabledEverywhere, private RuleEnablement $enablement) {}

            public function execute(AnalysisContext $context, ?string $restrictToProducer = null): RuleExecutionResult
            {
                throw new LogicException('A coverage stub executes nothing.');
            }

            public function publishable(array $findings): array
            {
                return $findings;
            }

            public function publication(): ChannelPublication
            {
                return new ChannelPublication($this->enablement);
            }

            public function allRules(): array
            {
                return array_map(
                    static fn(string $name): RuleMetadata => new RuleMetadata($name, ComplexityOptions::class, '', [], false),
                    $this->notSelected,
                );
            }

            public function levelActivity(): LevelActivity
            {
                return LevelActivity::fromMap(array_fill_keys($this->disabledEverywhere, ['callable' => false]));
            }
        };
    }

    private static function universe(): ChannelUniverseInterface
    {
        static $universe = null;
        if ($universe === null) {
            $factory = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
            \assert($factory instanceof RuleChannelSnapshotFactoryInterface);
            $universe = $factory->snapshot(new ResolvedComputedMetricDefinitions([]));
        }
        return $universe;
    }

    private static function channelIsItsOwnProducer(): ChannelIdentityInterface
    {
        return new class implements ChannelIdentityInterface {
            public function ruleNames(): array
            {
                return [];
            }

            public function hasRule(string $ruleName): bool
            {
                return false;
            }

            public function channels(): array
            {
                return [];
            }

            public function hasChannel(string $code): bool
            {
                return false;
            }

            public function producerOf(string $code): string
            {
                return $code;
            }

            public function supportsThresholdOverride(string $ruleName): bool
            {
                return false;
            }

            public function expand(NameSelector $selector): array
            {
                return [];
            }

            public function levelsOf(string $code): array
            {
                return [];
            }
        };
    }
}
