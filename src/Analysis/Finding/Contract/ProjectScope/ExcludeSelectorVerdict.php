<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;

/** What one authored exclude bound to in a measured run. */
final readonly class ExcludeSelectorVerdict
{
    /**
     * @param non-empty-list<ConfigurationOrigin> $sources
     * @param list<string> $removedEntries
     */
    public function __construct(
        public string $display,
        public array $sources,
        public ExcludeSelectorOutcome $outcome,
        public array $removedEntries = [],
        public ?string $phpEvidence = null,
        public ?string $coveredBy = null,
        public ?string $blockedAt = null,
    ) {
        if ($display === '' || $sources === []) {
            throw new InvalidArgumentException('An exclude verdict requires a selector and source');
        }
        if (($outcome === ExcludeSelectorOutcome::Removed) !== ($removedEntries !== [])) {
            throw new InvalidArgumentException('Removed verdicts require removed entries');
        }
        if ($outcome === ExcludeSelectorOutcome::Unjudgeable && $blockedAt === null) {
            throw new InvalidArgumentException('Unjudgeable verdicts require a blocked entry');
        }
        if (\in_array($outcome, [ExcludeSelectorOutcome::CoveredBySameSource, ExcludeSelectorOutcome::CoveredByOtherSource], true)
            && $coveredBy === null) {
            throw new InvalidArgumentException('Covered verdicts require a covering selector');
        }
    }
}
