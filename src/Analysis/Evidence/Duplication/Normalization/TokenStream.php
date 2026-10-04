<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Normalization;

use InvalidArgumentException;

/**
 * Original source coordinates stay aligned with normalized token values.
 * Byte intervals are half-open; line intervals and covered prefixes are inclusive.
 */
final readonly class TokenStream
{
    /**
     * @param list<string> $values
     * @param list<int> $startLines
     * @param list<int> $endLines
     * @param list<int> $coveredPrefix Cumulative number of distinct covered source lines
     * @param string $dataMask One ASCII '0' or '1' per token
     * @param list<int> $startBytes
     * @param list<int> $endBytes
     */
    public function __construct(
        public array $values,
        public array $startLines,
        public array $endLines,
        public array $coveredPrefix,
        public string $dataMask,
        public array $startBytes,
        public array $endBytes,
    ) {
        $count = \count($values);
        if (\count($startLines) !== $count
            || \count($endLines) !== $count
            || \count($coveredPrefix) !== $count
            || \strlen($dataMask) !== $count
            || \count($startBytes) !== $count
            || \count($endBytes) !== $count
        ) {
            throw new InvalidArgumentException('Token stream columns must have the same length');
        }
    }

    public function count(): int
    {
        return \count($this->values);
    }
}
