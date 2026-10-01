<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** The decision for one declared channel and level. */
final readonly class EnablementDecision
{
    public string $producer;
    public FindingChannel $channel;
    public ?SymbolLevel $level;
    public bool $on;
    public bool $direct;
    public ChannelSelectionRole $role;
    public ?string $statement;
    public ?Provenance $provenance;
    /** @var list<array{text: string, provenance: Provenance}> */
    public array $decisiveStatements;

    /**
     * @param SelectionCellAddress $address The declared cell being decided.
     * @param AuthoredCellDecision $authored Its complete authored selection decision.
     */
    public function __construct(
        SelectionCellAddress $address,
        AuthoredCellDecision $authored,
        public OptionActivity $activity = new OptionActivity(true),
    ) {
        $this->producer = $address->producer;
        $this->channel = $address->channel;
        $this->level = $address->level;
        $this->role = $address->role;
        $this->on = $authored->switch === CellSwitch::On;
        $this->direct = $authored->admission === CellAdmission::Direct;
        $this->decisiveStatements = $authored->decisiveStatements;
        $this->statement = $authored->decisiveStatements[0]['text'] ?? null;
        $this->provenance = $authored->decisiveStatements[0]['provenance'] ?? null;
    }

    public function live(): bool
    {
        return $this->on && $this->activity->active;
    }

    public function rank(): int
    {
        return $this->provenance === null ? -1 : $this->provenance->layerIndex;
    }
}
