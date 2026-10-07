<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;

/** Typed result of asking whether absent named types can be reported. */
final readonly class UnmatchedTypeJudgement
{
    /**
     * @param list<UnmatchedTypeOccurrence> $occurrences
     * @param list<ProjectScopeDoor> $withheldBy
     */
    public function __construct(
        public array $occurrences,
        public array $withheldBy,
        public bool $installConsulted,
    ) {}

    public function isJudged(): bool
    {
        return $this->withheldBy === [] && $this->installConsulted;
    }
}
