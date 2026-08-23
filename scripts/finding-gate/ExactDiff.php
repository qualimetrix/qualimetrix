<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The whole difference between two artifacts, byte for byte.
 *
 * {@see Diff} is the opposite tool on purpose: it is a readable account for a
 * failure message, so it truncates at fourteen lines and clips at 220
 * characters. That makes it useless as a declaration — a declared delta the gate
 * compares byte for byte cannot be a summary of itself.
 *
 * The difference is reported as one hunk with no context lines: everything the
 * two sides share at the top and at the bottom is dropped, and what remains is
 * emitted whole. That is not a minimal edit script — a change in the middle of a
 * long artifact restates the whole span between the outermost differing lines —
 * and the alternative was a full LCS over artifacts the size of the HTML report.
 * A stated span is exact and cheap; a minimal one would be neither. Context
 * lines are left out for the same reason line numbers are kept: a declaration
 * should stop matching as soon as the surface moves, not drift quietly.
 */
final class ExactDiff
{
    /** A line this long cannot be read as a line, so it also gets a token diff. */
    private const LONG_LINE = 500;

    /** How many unchanged tokens to show around a token-level change. */
    private const TOKEN_CONTEXT = 6;

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function __construct(
        private readonly string $leftLabel,
        private readonly string $rightLabel,
        private readonly int $start,
        private readonly array $left,
        private readonly array $right,
    ) {}

    public static function between(string $left, string $right, string $leftLabel, string $rightLabel): self
    {
        $leftLines = explode("\n", $left);
        $rightLines = explode("\n", $right);

        $prefix = 0;
        $leftCount = \count($leftLines);
        $rightCount = \count($rightLines);

        while ($prefix < $leftCount && $prefix < $rightCount && $leftLines[$prefix] === $rightLines[$prefix]) {
            ++$prefix;
        }

        $suffix = 0;

        while (
            $suffix < $leftCount - $prefix
            && $suffix < $rightCount - $prefix
            && $leftLines[$leftCount - 1 - $suffix] === $rightLines[$rightCount - 1 - $suffix]
        ) {
            ++$suffix;
        }

        return new self(
            $leftLabel,
            $rightLabel,
            $prefix + 1,
            array_values(\array_slice($leftLines, $prefix, $leftCount - $prefix - $suffix)),
            array_values(\array_slice($rightLines, $prefix, $rightCount - $prefix - $suffix)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->left === [] && $this->right === [];
    }

    public function changedLineCount(): int
    {
        return \count($this->left) + \count($this->right);
    }

    public function render(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $lines = [
            '--- ' . $this->leftLabel,
            '+++ ' . $this->rightLabel,
            \sprintf('@@ -%d,%d +%d,%d @@', $this->start, \count($this->left), $this->start, \count($this->right)),
        ];

        foreach ($this->left as $line) {
            $lines[] = '-' . $line;
        }

        foreach ($this->right as $line) {
            $lines[] = '+' . $line;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The removed and added lines paired by their position in the hunk.
     *
     * Pairing is what lets a reader — and the overreach check — ask which field
     * on a line actually changed, rather than which fields the line mentions. A
     * compact JSON record names half the tuple on every line it prints, so
     * "mentions a compared field" would flag every such line.
     *
     * @return list<array{0: ?string, 1: ?string}>
     */
    public function pairs(): array
    {
        $pairs = [];

        for ($index = 0; $index < max(\count($this->left), \count($this->right)); ++$index) {
            $pairs[] = [$this->left[$index] ?? null, $this->right[$index] ?? null];
        }

        return $pairs;
    }

    /**
     * A token-level account of the lines too long to read as lines.
     *
     * The HTML report embeds its whole data payload on one line — measured at
     * roughly 59 thousand characters — so without this a declared delta on that
     * surface is a wall nobody can check.
     *
     * @return list<string>
     */
    public function tokenDetail(): array
    {
        $detail = [];

        foreach ($this->pairs() as $index => [$leftLine, $rightLine]) {
            if ($leftLine === null || $rightLine === null) {
                continue;
            }

            if (\strlen($leftLine) <= self::LONG_LINE && \strlen($rightLine) <= self::LONG_LINE) {
                continue;
            }

            $detail[] = \sprintf('token diff of hunk line %d (%d vs %d chars):', $index + 1, \strlen($leftLine), \strlen($rightLine));

            foreach (self::tokenDiff($leftLine, $rightLine) as $line) {
                $detail[] = '  ' . $line;
            }
        }

        return $detail;
    }

    /** @return list<string> */
    private static function tokenDiff(string $left, string $right): array
    {
        $leftTokens = self::tokens($left);
        $rightTokens = self::tokens($right);

        $prefix = 0;
        $leftCount = \count($leftTokens);
        $rightCount = \count($rightTokens);

        while ($prefix < $leftCount && $prefix < $rightCount && $leftTokens[$prefix] === $rightTokens[$prefix]) {
            ++$prefix;
        }

        $suffix = 0;

        while (
            $suffix < $leftCount - $prefix
            && $suffix < $rightCount - $prefix
            && $leftTokens[$leftCount - 1 - $suffix] === $rightTokens[$rightCount - 1 - $suffix]
        ) {
            ++$suffix;
        }

        $contextBefore = implode('', \array_slice($leftTokens, max(0, $prefix - self::TOKEN_CONTEXT), min($prefix, self::TOKEN_CONTEXT)));
        $contextAfter = implode('', \array_slice($leftTokens, $leftCount - $suffix, self::TOKEN_CONTEXT));

        return [
            '  …' . $contextBefore . '⟦',
            '- ' . implode('', \array_slice($leftTokens, $prefix, $leftCount - $prefix - $suffix)),
            '+ ' . implode('', \array_slice($rightTokens, $prefix, $rightCount - $prefix - $suffix)),
            '  ⟧' . $contextAfter . '…',
        ];
    }

    /** @return list<string> */
    private static function tokens(string $line): array
    {
        $tokens = preg_split('~(?<=[^A-Za-z0-9_.\-\\\\/])~', $line, -1, \PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [$line];
        }

        return array_map(strval(...), $tokens);
    }
}
