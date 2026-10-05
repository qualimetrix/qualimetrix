<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;

/** The complete measured membership of one baseline identity. */
final readonly class GroupMeasurement
{
    /** @param ?list<float> $magnitudes Null when any required magnitude is unavailable. */
    private function __construct(
        public int $count,
        public ?array $magnitudes,
        public int $membersWithoutMagnitude,
    ) {}

    /**
     * @param non-empty-list<Finding> $findings
     */
    public static function fromFindings(array $findings, bool $occurrence): self
    {
        $count = \count($findings);
        if ($occurrence) {
            return new self($count, null, 0);
        }

        $magnitudes = [];
        $missing = 0;
        foreach ($findings as $finding) {
            if ($finding->metricValue === null) {
                ++$missing;

                continue;
            }

            try {
                $magnitudes[] = BaselineEntry::normalizeMagnitude($finding->metricValue);
            } catch (InvalidArgumentException) {
                ++$missing;
            }
        }

        return new self($count, $missing === 0 ? $magnitudes : null, $missing);
    }

    public function complete(): bool
    {
        return $this->membersWithoutMagnitude === 0;
    }
}
