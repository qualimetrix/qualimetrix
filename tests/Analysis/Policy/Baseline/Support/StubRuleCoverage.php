<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Support;

use LogicException;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;

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
        return new readonly class ($notSelected, $disabledEverywhere) implements RuleExecutionInterface {
            /**
             * @param list<string> $notSelected
             * @param list<string> $disabledEverywhere
             */
            public function __construct(private array $notSelected, private array $disabledEverywhere) {}

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
                return new ChannelPublication(
                    new RuleSelector(new InMemoryRuleChannelRegistry()),
                    new RuleSelection(disabled: $this->notSelected),
                    $this->levelActivity(),
                );
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
