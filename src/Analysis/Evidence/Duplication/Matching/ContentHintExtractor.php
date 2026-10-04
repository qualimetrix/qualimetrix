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
        $meaningful = $this->firstMeaningfulLines($this->sourceLines($slice));
        if ($meaningful === []) {
            return null;
        }

        return $this->collapseWhitespace(implode(' ', $meaningful));
    }

    /** @return list<string> */
    private function sourceLines(string $slice): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $slice, 11);
        if ($lines === false) {
            throw new LogicException('Cannot split the hint source lines');
        }

        return \array_slice($lines, 0, 10);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function firstMeaningfulLines(array $lines): array
    {
        $meaningful = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^[{};\s]+$/', $trimmed) === 1 || \strlen($trimmed) < 3) {
                continue;
            }
            $meaningful[] = $trimmed;
            if (\count($meaningful) === 3) {
                break;
            }
        }

        return $meaningful;
    }

    private function collapseWhitespace(string $excerpt): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $excerpt);
        if ($collapsed === null) {
            throw new LogicException('Cannot collapse the hint whitespace');
        }

        return trim($collapsed);
    }

    private function truncateHint(string $hint): string
    {
        $encoding = mb_check_encoding($hint, 'UTF-8') ? 'UTF-8' : '8bit';
        $length = mb_strlen($hint, $encoding);
        if ($length <= self::MAX_HINT_LENGTH) {
            return $hint;
        }

        // The three dots consume the last three code points of the limit.
        $hint = mb_substr($hint, 0, self::MAX_HINT_LENGTH - 3, $encoding);
        $space = mb_strrpos($hint, ' ', 0, $encoding);
        if ($space !== false && $space > 40) {
            $hint = mb_substr($hint, 0, $space, $encoding);
        }

        return $hint . '...';
    }
}
