<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** A bounded, readable account of WHAT differs — never just that something did. */
final class Diff
{
    private const CONTEXT_LINES = 2;

    private const MAX_LINES = 14;

    /** @return list<string> */
    public static function between(string $left, string $right, string $leftLabel, string $rightLabel): array
    {
        $leftLines = explode("\n", $left);
        $rightLines = explode("\n", $right);
        $first = self::firstDifference($leftLines, $rightLines);

        if ($first === null) {
            return [];
        }

        $lines = [\sprintf('- %s / + %s (first difference at line %d)', $leftLabel, $rightLabel, $first + 1)];
        $from = max(0, $first - self::CONTEXT_LINES);
        $shown = 0;

        for ($index = $from; $index < max(\count($leftLines), \count($rightLines)); ++$index) {
            $leftLine = $leftLines[$index] ?? null;
            $rightLine = $rightLines[$index] ?? null;

            if ($leftLine === $rightLine) {
                if ($index < $first + self::CONTEXT_LINES) {
                    $lines[] = '  ' . self::clip((string) $leftLine);
                }

                continue;
            }

            if ($leftLine !== null) {
                $lines[] = '- ' . self::clip($leftLine);
            }

            if ($rightLine !== null) {
                $lines[] = '+ ' . self::clip($rightLine);
            }

            if (++$shown >= self::MAX_LINES) {
                $lines[] = \sprintf('  … truncated (%d line(s) compared)', max(\count($leftLines), \count($rightLines)));
                break;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     *
     * @return list<string>
     */
    public static function betweenSets(array $expected, array $actual, string $expectedLabel, string $actualLabel): array
    {
        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));
        $lines = [];

        foreach (\array_slice($missing, 0, self::MAX_LINES) as $item) {
            $lines[] = \sprintf('- only in %s: %s', $expectedLabel, $item);
        }

        foreach (\array_slice($extra, 0, self::MAX_LINES) as $item) {
            $lines[] = \sprintf('+ only in %s: %s', $actualLabel, $item);
        }

        if (\count($missing) + \count($extra) > 2 * self::MAX_LINES) {
            $lines[] = \sprintf('  … %d item(s) differ in total', \count($missing) + \count($extra));
        }

        return $lines;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private static function firstDifference(array $left, array $right): ?int
    {
        $length = max(\count($left), \count($right));

        for ($index = 0; $index < $length; ++$index) {
            if (($left[$index] ?? null) !== ($right[$index] ?? null)) {
                return $index;
            }
        }

        return null;
    }

    private static function clip(string $line): string
    {
        return \strlen($line) > 220 ? substr($line, 0, 220) . '…' : $line;
    }
}
