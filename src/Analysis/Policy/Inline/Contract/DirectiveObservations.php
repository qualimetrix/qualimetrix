<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;

final readonly class DirectiveObservations
{
    /**
     * @param array<string, list<Suppression>> $suppressions
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides
     */
    public function __construct(
        public array $suppressions,
        public array $thresholdOverrides,
    ) {}

    public function merge(self $other): self
    {
        return new self(
            self::mergedFileLists($this->suppressions, $other->suppressions),
            self::mergedFileLists($this->thresholdOverrides, $other->thresholdOverrides),
        );
    }

    /**
     * @template T of Suppression|ThresholdOverride
     *
     * @param array<string, list<T>> $left
     * @param array<string, list<T>> $right
     *
     * @return array<string, list<T>>
     */
    private static function mergedFileLists(array $left, array $right): array
    {
        foreach ($right as $file => $list) {
            $left[$file] = array_merge($left[$file] ?? [], $list);
        }

        return $left;
    }
}
