<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
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
    public static function fromFindings(array $findings, ChannelShape $shape): self
    {
        $count = \count($findings);
        if ($shape === ChannelShape::Occurrence) {
            return new self($count, null, 0);
        }

        return self::measureMagnitudes($findings);
    }

    /** @param non-empty-list<Finding> $findings */
    private static function measureMagnitudes(array $findings): self
    {
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

        return new self(\count($findings), $missing === 0 ? $magnitudes : null, $missing);
    }

    public function complete(): bool
    {
        return $this->membersWithoutMagnitude === 0;
    }
}
