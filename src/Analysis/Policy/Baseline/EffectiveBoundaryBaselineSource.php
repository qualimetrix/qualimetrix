<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;

/** The stored acceptance and its verdict, independently of current measurements. */
final readonly class EffectiveBoundaryBaselineSource
{
    private function __construct(
        public ?AcceptedLevel $accepted,
        public ?BaselineEntryMode $mode,
        public ?InertBaselineEntry $inert,
        public ?string $verdict,
        public ?string $reason,
    ) {}

    public static function applicable(BaselineEntry $entry, ?string $verdict, ?string $reason): self
    {
        return new self(new AcceptedLevel($entry->magnitudes, $entry->count), $entry->mode, null, $verdict, $reason);
    }

    public static function inert(InertBaselineEntry $entry): self
    {
        return new self(null, null, $entry, 'inert', null);
    }
}
