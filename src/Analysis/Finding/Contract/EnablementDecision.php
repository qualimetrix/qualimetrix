<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** The decision for one declared channel and level. */
final readonly class EnablementDecision
{
    public function __construct(
        public string $producer,
        public FindingChannel $channel,
        public ?SymbolLevel $level,
        public bool $on,
        public bool $direct,
        public ChannelSelectionRole $role,
        public ?string $statement,
        public ?Provenance $provenance,
        public OptionActivity $activity = new OptionActivity(true),
        /** @var list<array{text: string, provenance: Provenance}> */
        public array $decisiveStatements = [],
    ) {}

    public function live(): bool
    {
        return $this->on && $this->activity->active;
    }

    public function rank(): int
    {
        return $this->provenance === null ? -1 : $this->provenance->layerIndex;
    }
}
