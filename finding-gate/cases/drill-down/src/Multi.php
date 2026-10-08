<?php

declare(strict_types=1);

namespace Shop\Inner {
    final class InnerCopy
    {
        public function summarise(array $rows, string $mode, int $limit): array
        {
            $result = [];
            $index = 0;
            foreach ($rows as $key => $row) {
                if ($index >= $limit) {
                    break;
                }
                if ($row === []) {
                    continue;
                }
                $total = 0;
                $size = 0;
                foreach ($row as $cell) {
                    ++$size;
                    if (is_numeric($cell)) {
                        $total += (int) $cell;
                    }
                }
                $result[$key] = [
                    'total' => $total,
                    'mode' => $mode,
                    'size' => $size,
                    'label' => strtoupper((string) $key),
                ];
                ++$index;
            }

            return $result;
        }
    }
}

namespace Other {
    final class Marker {}
}
