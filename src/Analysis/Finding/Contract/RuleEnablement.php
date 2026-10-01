<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** One immutable answer to execution, publication and audit selection. */
final readonly class RuleEnablement
{
    /** @param list<EnablementDecision> $decisions */
    public function __construct(private array $decisions, private ?SelectionFilter $filter) {}

    /** @return list<EnablementDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    public function filter(): ?SelectionFilter
    {
        return $this->filter;
    }

    public function decisionFor(string $producer): EnablementDecision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->producer === $producer) {
                return $decision;
            }
        }

        throw new LogicException(\sprintf('No declared cell for producer "%s".', $producer));
    }

    public function isEnabled(string $producer): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->producer === $producer && $decision->on) {
                return true;
            }
        }

        return false;
    }

    public function runs(string $producer): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->producer === $producer && $decision->live()
                && ($decision->direct || $decision->role !== ChannelSelectionRole::Selectable)) {
                return true;
            }
        }

        return false;
    }

    public function publishes(FindingChannel $channel, ?SymbolLevel $level, ?string $addressedProducer = null): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->channel->code !== $channel->code || ($level !== null && $decision->level !== null && $decision->level !== $level)
                || !$decision->live()) {
                continue;
            }

            if ($decision->direct || $decision->role === ChannelSelectionRole::FilterExempt) {
                return true;
            }

            if ($decision->role === ChannelSelectionRole::FollowsAddressedRule
                && $addressedProducer !== null && $this->filterCovers($addressedProducer)) {
                return true;
            }
        }

        return false;
    }

    public function levelActivity(): LevelActivity
    {
        $levels = [];
        foreach ($this->decisions as $decision) {
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
        $producers = [];
        foreach ($this->decisions as $decision) {
            $producers[$decision->producer] ??= [];
            $producers[$decision->producer][] = $decision;
        }

        $records = [];
        foreach ($producers as $producer => $decisions) {
            if ($this->runs($producer)) {
                continue;
            }
            $cause = $decisions[0];
            foreach ($decisions as $decision) {
                if (!$decision->on || !$decision->activity->active) {
                    $cause = $decision;
                    break;
                }
            }
            $reason = !$cause->on || !$cause->activity->active ? 'disabled' : 'filtered';
            [$statement, $writer] = $reason === 'filtered'
                ? $this->filterCause()
                : self::cellCause($cause);
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
        foreach ($this->decisions as $decision) {
            if ($decision->channel->code !== $channel->code
                || ($decision->level !== null && $level !== null && $decision->level !== $level)) {
                continue;
            }
            [$statement, $writer] = $decision->live() ? $this->filterCause() : self::cellCause($decision);
            return $statement . ' (' . ($writer?->origin->describe() ?? 'default') . ')';
        }
        throw new LogicException(\sprintf('No declared selection cell for channel "%s".', $channel->code));
    }

    /** @return array{string, ?Provenance} */
    private static function cellCause(EnablementDecision $decision): array
    {
        if (!$decision->on) {
            $statements = $decision->decisiveStatements;
            return [$statements === [] ? ($decision->statement ?? 'default')
                : implode('; ', array_column($statements, 'text')), $decision->provenance];
        }
        return [$decision->activity->written ?? 'inactive by default', $decision->activity->decidedBy];
    }

    /** @return array{string, ?Provenance} */
    private function filterCause(): array
    {
        $filter = $this->filter ?? throw new LogicException('A filtered cell requires a written rule filter.');
        $selectors = '[' . implode(', ', $filter->selectors) . ']';
        $writer = $filter->provenance;
        $statement = $writer->path === null
            ? ($writer->origin->locator() ?? '--only-rule') . '=' . $selectors
            : $writer->displayPath() . ': ' . $selectors;
        return [$statement, $writer];
    }

    private function filterCovers(string $producer): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->producer === $producer && $decision->direct) {
                return true;
            }
        }

        return false;
    }
}
