<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * One resolved numeric band shared by the metric-owned options that consume it.
 *
 * @qmx-threshold coupling.cbo 21 -- One threshold language owns resolved parsing and direction-aware comparison; splitting those operations would repeat the same axis semantics in every metric Options object.
 */
final readonly class ThresholdPair
{
    public function __construct(
        public int|float $warning,
        public int|float $error,
        public BandDirection $direction = BandDirection::Rising,
    ) {}

    public static function fromResolved(
        ResolvedRuleOptionValues $values,
        RuleOptionBand $band,
        int|float $defaultWarning,
        int|float $defaultError,
    ): self {
        $thresholds = ThresholdParser::parse($values, $band, $defaultWarning, $defaultError);
        return new self($thresholds['warning'], $thresholds['error'], $band->direction);
    }

    public function integerWarning(): int
    {
        return \is_int($this->warning) ? $this->warning : throw new LogicException('An integer band resolved a non-integer warning.');
    }

    public function integerError(): int
    {
        return \is_int($this->error) ? $this->error : throw new LogicException('An integer band resolved a non-integer error.');
    }

    public function severity(int|float $value): ?Severity
    {
        return match ($this->direction) {
            BandDirection::Rising => $value >= $this->error ? Severity::Error : ($value >= $this->warning ? Severity::Warning : null),
            BandDirection::Falling => $value < $this->error ? Severity::Error : ($value < $this->warning ? Severity::Warning : null),
        };
    }
}
