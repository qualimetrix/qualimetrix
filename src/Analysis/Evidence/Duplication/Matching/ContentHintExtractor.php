<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use LogicException;

/** A bounded display excerpt from one copy's half-open source byte interval. */
final class ContentHintExtractor
{
    private const int MAX_HINT_LENGTH = 80;

    public function extract(string $source, int $startByte, int $endByte): ?string
    {
        if ($startByte < 0 || $startByte >= \strlen($source) || $endByte <= $startByte) {
            return null;
        }

        $hint = $this->firstMeaningfulExcerpt(substr($source, $startByte, $endByte - $startByte));

        return $hint === null ? null : $this->truncateHint($hint);
    }

    private function firstMeaningfulExcerpt(string $slice): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $slice, 11);
        if ($lines === false) {
            throw new LogicException('Cannot split the hint source lines');
        }
        $meaningful = [];
        foreach (\array_slice($lines, 0, 10) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^[{};\s]+$/', $trimmed) === 1 || \strlen($trimmed) < 3) {
                continue;
            }
            $meaningful[] = $trimmed;
            if (\count($meaningful) === 3) {
                break;
            }
        }
        if ($meaningful === []) {
            return null;
        }

        $collapsed = preg_replace('/\s+/', ' ', implode(' ', $meaningful));
        if ($collapsed === null) {
            throw new LogicException('Cannot collapse the hint whitespace');
        }

        return trim($collapsed);
    }

    private function truncateHint(string $hint): string
    {
        $utf8 = mb_check_encoding($hint, 'UTF-8');
        $length = $utf8 ? mb_strlen($hint, 'UTF-8') : \strlen($hint);
        if ($length <= self::MAX_HINT_LENGTH) {
            return $hint;
        }

        // The three dots consume the last three code points of the limit.
        $hint = $utf8 ? mb_substr($hint, 0, self::MAX_HINT_LENGTH - 3, 'UTF-8') : substr($hint, 0, self::MAX_HINT_LENGTH - 3);
        $space = $utf8 ? mb_strrpos($hint, ' ', 0, 'UTF-8') : strrpos($hint, ' ');
        if ($space !== false && $space > 40) {
            $hint = $utf8 ? mb_substr($hint, 0, $space, 'UTF-8') : substr($hint, 0, $space);
        }

        return $hint . '...';
    }
}
