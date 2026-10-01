<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Selection;

use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** The producer and declared channel-level cell whose selection is decided. */
final readonly class SelectionCellAddress
{
    public function __construct(
        public string $producer,
        public FindingChannel $channel,
        public ?SymbolLevel $level,
        public ChannelSelectionRole $role,
    ) {}
}
