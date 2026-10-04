<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Normalization;

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Original source coordinates stay aligned with normalized token values.
 * Byte intervals are half-open; line intervals and covered prefixes are inclusive.
 * Coordinate widths belong to one file; q preserves the full signed integer range.
 */
final readonly class TokenStream
{
    private string $formats;
    private int $width;

    /** @var list<int> */
    private array $offsets;

    /**
     * @param list<string> $values
     * @param string $coordinates Five C/v/V/q format bytes followed by interleaved coordinate records
     * @param string $dataMask One ASCII '0' or '1' per token
     */
    public function __construct(
        public array $values,
        private string $coordinates,
        public string $dataMask,
    ) {
        $formats = substr($coordinates, 0, 5);
        if (\strlen($formats) !== 5 || strspn($formats, 'CvVq') !== 5) {
            throw new InvalidArgumentException('Token stream columns must have the same length');
        }
        [$width, $offsets] = $this->fieldOffsets($formats);
        if (\strlen($coordinates) !== 5 + \count($values) * $width || \strlen($dataMask) !== \count($values)) {
            throw new InvalidArgumentException('Token stream columns must have the same length');
        }
        $this->formats = $formats;
        $this->width = $width;
        $this->offsets = $offsets;
    }

    public function count(): int
    {
        return \count($this->values);
    }

    public function startLine(int $index): int
    {
        return $this->coordinate($index, 0);
    }

    public function endLine(int $index): int
    {
        return $this->coordinate($index, 1);
    }

    public function coveredPrefix(int $index): int
    {
        return $this->coordinate($index, 2);
    }

    public function startByte(int $index): int
    {
        return $this->coordinate($index, 3);
    }

    public function endByte(int $index): int
    {
        return $this->coordinate($index, 4);
    }

    /** @return array{int, list<int>} Record width and field offsets */
    private function fieldOffsets(string $formats): array
    {
        $offsets = [];
        $width = 0;
        foreach (str_split($formats) as $format) {
            $offsets[] = $width;
            $width += match ($format) {
                'C' => 1,
                'v' => 2,
                'V' => 4,
                'q' => 8,
                default => throw new InvalidArgumentException('Token stream columns must have the same length'),
            };
        }

        return [$width, $offsets];
    }

    private function coordinate(int $index, int $field): int
    {
        if ($index < 0 || $index >= \count($this->values)) {
            throw new OutOfBoundsException('Token coordinate index is outside the stream.');
        }
        /** @var array{1: int} $value Validated integer format and record boundary */
        $value = unpack($this->formats[$field], $this->coordinates, 5 + $index * $this->width + $this->offsets[$field]);

        return $value[1];
    }
}
