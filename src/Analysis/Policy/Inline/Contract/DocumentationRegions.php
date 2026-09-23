<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

/**
 * The parts of a comment that **quote** a directive instead of writing one.
 *
 * A docblock that documents the annotation syntax has to be able to name the
 * tags without addressing them, and the project spells that escape with
 * backticks (AGENTS.md §8). Both extractors ask this class the same question,
 * which is why it exists: while each carried its own copy of the rule, the two
 * differed in what they put in a region's place, so one of them could join two
 * halves of unrelated prose into a tag the other never saw.
 *
 * **A region never spans a line, and that is the whole of the fix it carries.**
 * The rule used to be "pair the backticks of the comment from left to right",
 * which makes every region boundary a function of how many backticks stand
 * above it: one stray backtick in prose shifts the pairing of everything below,
 * so a correctly escaped example becomes a live directive and the live
 * directive under it disappears. Both outcomes are silent — the first silences
 * a channel nobody asked to silence, the second loses an annotation before any
 * part of the tool can report on it. An inline region therefore opens and
 * closes within one line, and a backtick with no partner on its line is an
 * ordinary character.
 *
 * The multi-line form of quoting is a fenced block, recognised as itself rather
 * than as three inline regions that happen to pair up.
 *
 * What is returned is the same text with every quoted region **blanked**, not
 * removed: every offset and every line number in the result still addresses the
 * character the author wrote, which is what lets a caller report the line a tag
 * was written on after the quoting has been taken out of its way.
 */
final readonly class DocumentationRegions
{
    private const string FENCE = '```';

    /** Blanks every quoted region, preserving the length and the line structure of the text. */
    public static function mask(string $text): string
    {
        $inFence = false;
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            if (self::opensOrClosesFence($line)) {
                $inFence = !$inFence;
                $lines[$index] = self::blank($line);

                continue;
            }

            $lines[$index] = $inFence ? self::blank($line) : self::maskInlineRegions($line);
        }

        return implode("\n", $lines);
    }

    /**
     * A fence delimiter is the first thing on its line, after the docblock's
     * own leading asterisk. An info string ("```php") belongs to the opening
     * delimiter and is blanked with it.
     */
    private static function opensOrClosesFence(string $line): bool
    {
        return preg_match('/^\s*\*?\s*' . preg_quote(self::FENCE, '/') . '/', $line) === 1;
    }

    /**
     * Pairs the backticks of one line and blanks each pair with what it
     * encloses. An odd one out is left alone: it quotes nothing, because
     * nothing on this line closes it.
     */
    private static function maskInlineRegions(string $line): string
    {
        $positions = self::backtickPositions($line);
        $paired = intdiv(\count($positions), 2) * 2;

        for ($i = 0; $i < $paired; $i += 2) {
            $start = $positions[$i];
            $length = $positions[$i + 1] - $start + 1;
            $line = substr_replace($line, str_repeat(' ', $length), $start, $length);
        }

        return $line;
    }

    /** @return list<int> */
    private static function backtickPositions(string $line): array
    {
        $positions = [];
        $offset = 0;

        while (($position = strpos($line, '`', $offset)) !== false) {
            $positions[] = $position;
            $offset = $position + 1;
        }

        return $positions;
    }

    /** Keeps a carriage return's width rather than its meaning: only offsets are promised. */
    private static function blank(string $line): string
    {
        return str_repeat(' ', \strlen($line));
    }
}
