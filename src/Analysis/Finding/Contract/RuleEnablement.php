<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use LogicException;
use Qualimetrix\Analysis\Finding\Selection\EnablementIndex;
use Qualimetrix\Analysis\Finding\Selection\SelectionCauses;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** One immutable answer to execution, publication and audit selection. */
final readonly class RuleEnablement
{
    private EnablementIndex $index;

    /** @param list<EnablementDecision> $decisions */
    public function __construct(array $decisions, private ?SelectionFilter $filter)
    {
        $this->index = new EnablementIndex($decisions);
    }

    /** @return list<EnablementDecision> */
    public function decisions(): array
    {
        return $this->index->all();
    }

    public function filter(): ?SelectionFilter
    {
        return $this->filter;
    }

    public function decisionFor(string $producer): EnablementDecision
    {
        return $this->index->firstForProducer($producer);
    }

    public function isEnabled(string $producer): bool
    {
        return $this->index->enabled($producer);
    }

    public function runs(string $producer): bool
    {
        foreach ($this->index->producerCells($producer) as $decision) {
            if ($decision->live()
                && ($decision->direct || $decision->role !== ChannelSelectionRole::Selectable)) {
                return true;
            }
        }

        return false;
    }

    public function publishes(FindingChannel $channel, ?SymbolLevel $level, ?string $addressedProducer = null): bool
    {
        foreach ($this->index->channelCells($channel, $level) as $decision) {
            if (!$decision->live()) {
                continue;
            }

            if ($decision->direct || $decision->role === ChannelSelectionRole::FilterExempt) {
                return true;
            }

            if ($decision->role === ChannelSelectionRole::FollowsAddressedRule
                && $addressedProducer !== null && $this->index->direct($addressedProducer)) {
                return true;
            }
        }

        return false;
    }

    public function levelActivity(): LevelActivity
    {
        $levels = [];
        foreach ($this->index->all() as $decision) {
            if ($decision->level === null) {
                continue;
            }
            $key = $decision->level->value;
            $levels[$decision->producer][$key] = ($levels[$decision->producer][$key] ?? false) || $decision->live();
        }

        return LevelActivity::fromMap($levels);
    }

    /** @return list<SelectionRecord> */
    public function notRun(): array
    {
        $records = [];
        foreach ($this->index->producers() as $producer) {
            $decisions = $this->index->producerCells($producer);
            if ($this->runs($producer)) {
                continue;
            }
            $cause = array_find($decisions, static fn(EnablementDecision $decision): bool => !$decision->live()) ?? $decisions[0];
            $reason = $cause->live() ? 'filtered' : 'disabled';
            [$statement, $writer] = $reason === 'filtered'
                ? SelectionCauses::filter($this->filter)
                : SelectionCauses::authoredCell($cause);
            $layer = $writer?->origin->describe() ?? 'default';
            $records[] = new SelectionRecord($producer, $reason, $statement, $layer);
        }

        return $records;
    }

    public function selectionSuppressor(FindingChannel $channel, ?SymbolLevel $level, ?string $addressedProducer = null): string
    {
        if ($this->publishes($channel, $level, $addressedProducer)) {
            throw new LogicException('A published finding has no selection suppressor.');
        }
        foreach ($this->index->channelCells($channel, $level) as $decision) {
            [$statement, $writer] = $decision->live() ? SelectionCauses::filter($this->filter) : SelectionCauses::authoredCell($decision);
            return $statement . ' (' . ($writer?->origin->describe() ?? 'default') . ')';
        }
        throw new LogicException(\sprintf('No declared selection cell for channel "%s".', $channel->code));
    }

}
