<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\SelectionTrace;

/** Projects produced findings through the existing exclusion ledger and selection. */
final readonly class FindingPublication
{
    private FindingExclusionLedger $exclusions;

    public function __construct(private RuleConfigurationInterface $ruleConfiguration)
    {
        $this->exclusions = new FindingExclusionLedger($ruleConfiguration);
    }

    public function begin(): void
    {
        $this->exclusions->begin();
    }

    /**
     * @param list<Finding> $produced
     * @param list<Finding> $published
     * @param list<array{finding: Finding, suppressor: string}> $removed
     */
    public function complete(
        array $produced,
        array $published,
        LevelActivity $levelActivity,
        array $removed,
        RuleEnablement $enablement,
    ): RuleExecutionResult {
        return new RuleExecutionResult(
            $produced,
            $published,
            $this->exclusions->stats(),
            $levelActivity,
            new SelectionTrace($removed, $enablement->notRun()),
        );
    }

    /**
     * @param list<Finding> $findings
     * @param list<array{finding: Finding, suppressor: string}> $removed
     *
     * @return list<Finding>
     */
    public function published(
        string $ruleName,
        array $findings,
        RuleEnablement $enablement,
        ?string $restrictToProducer,
        array &$removed,
    ): array {
        $kept = [];

        foreach ($findings as $finding) {
            $producer = $this->producerOf($finding, $ruleName);
            $this->assertAddressedProducer($finding);

            if (!$this->exclusions->keeps($producer, $finding)) {
                continue;
            }

            $enabled = $enablement->publishes($finding->channel(), $finding->level(), $finding->addressedProducer)
                && ($restrictToProducer === null || $producer === $restrictToProducer);

            if ($enabled) {
                $kept[] = $finding;
            } else {
                $removed[] = [
                    'finding' => $finding,
                    'suppressor' => $restrictToProducer !== null && $producer !== $restrictToProducer
                        ? \sprintf('restricted to producer "%s"', $restrictToProducer)
                        : $enablement->selectionSuppressor($finding->channel(), $finding->level(), $finding->addressedProducer),
                ];
            }
        }

        return $kept;
    }

    /**
     * Late findings obey selection without changing the execution ledger.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function publishable(array $findings, RuleEnablement $enablement): array
    {
        $kept = [];
        foreach ($findings as $finding) {
            // The producer lookup also refuses a static/computed channel name collision.
            $this->producerOf($finding, $finding->ruleName);
            $this->assertAddressedProducer($finding);
            if ($enablement->publishes($finding->channel(), $finding->level(), $finding->addressedProducer)) {
                $kept[] = $finding;
            }
        }

        return $kept;
    }

    /** The final universe identifies hosted channels; undeclared codes retain the instance name. */
    private function producerOf(Finding $finding, string $ruleName): string
    {
        return $this->ruleConfiguration->channelUniverse()->producerOf($finding->channel()->code) ?? $ruleName;
    }

    private function assertAddressedProducer(Finding $finding): void
    {
        $channels = $this->ruleConfiguration->channelUniverse();
        $role = $channels->declarationFor($finding->channel())?->selectionRole;
        if ($finding->addressedProducer === null) {
            return;
        }
        if ($role !== ChannelSelectionRole::FollowsAddressedRule || !$channels->hasRule($finding->addressedProducer)) {
            throw new LogicException(\sprintf('Channel "%s" cannot address producer "%s".', $finding->channel()->code, $finding->addressedProducer));
        }
    }
}
