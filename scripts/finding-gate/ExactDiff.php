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
 * The difference is reported as hunks with no context lines: what the two sides
 * share is dropped, and what remains is emitted whole, each hunk carrying the
 * line it starts at on both sides.
 *
 * It used to be a single hunk covering everything between the outermost
 * differing lines, and that was wrong for the reason its first real user found:
 * two small changes at opposite ends of a JSON report restated the three hundred
 * identical lines between them, and `delta-too-large` then counted padding as
 * change and refused a declaration that had nothing left to declare. Splitting
 * on runs of identical lines makes the count mean what the failure says it
 * means. Runs shorter than {@see ANCHOR} are not split on: in a compact JSON
 * report `},` and `{` recur everywhere, and anchoring on them would slice one
 * change into dozens of hunks.
 *
 * The split is found by the longest common run of lines rather than by a full
 * LCS, recursively on each side of it. That is not a minimal edit script and
 * still deliberately so — a minimal one needs an LCS over artifacts the size of
 * the HTML report — but it is exact: the hunks reconstruct the difference.
 *
 * It does not remove *all* padding, and the earlier wording here claimed it
 * did. A shared run shorter than {@see ANCHOR} stays inside its hunk and is
 * counted on both sides: in the tracked SARIF declaration, `"shortDescription":
 * {` and its two siblings are identical on both sides and account for 18 of 36
 * counted lines. What the split removes is padding measured in hundreds — the
 * span between two changes at opposite ends of a report — which is what made
 * `delta-too-large` refuse a declaration that had nothing left to declare.
 *
 * Beyond {@see SEARCH_BUDGET} line pairs the search would cost more than it
 * buys, and the span is **refused** rather than emitted whole: falling back to
 * one hunk would silently restore the behaviour this class was rewritten to
 * remove, and the failure it produces — `delta-too-large` advising "declare a
 * map row instead" — is advice about a diff that is mostly padding. A caller
 * that hits the budget gets {@see BudgetExceeded} and has to raise the budget
 * or shrink the artifact, which are the two honest answers.
 *
 * Context lines are left out for the same reason line numbers are kept: a
 * declaration should stop matching as soon as the surface moves, not drift
 * quietly.
 */
final class ExactDiff
{
    /** A line this long cannot be read as a line, so it also gets a token diff. */
    private const LONG_LINE = 500;

    /** How many unchanged tokens to show around a token-level change. */
    private const TOKEN_CONTEXT = 6;

    /**
     * The shortest run of identical lines worth splitting a hunk on.
     *
     * Below this, a compact JSON artifact anchors on its own punctuation and one
     * change becomes many hunks; at this length a run is structure rather than
     * coincidence.
     */
    private const ANCHOR = 4;

    /**
     * Line pairs the anchor search may consider for one span.
     *
     * Set from a measurement rather than by feel, and the first value was wrong
     * in the direction that matters: 4 000 000 pairs is 0.08 s of work, and an
     * ordinary perturbation of the corpus (one finding dropped, which shifts
     * every line after it) produced a 2045×2058 span — 4.2 million pairs. The
     * budget refused it, so a control that should have gone red went "the gate
     * could not run". A bound that a normal diff trips is not a bound on cost,
     * it is a bug.
     *
     * Measured on this machine, worst case (no shared run anywhere, so the
     * search never exits early): 4M pairs 0.08 s, 16M 0.35 s, 36M 0.76 s, 64M
     * 1.41 s, 144M 3.08 s. 144M is the limit here — three seconds inside a run
     * that already takes minutes, and room for a 12000-line span against a
     * corpus whose largest artifact is 1812 lines. Past it the span is refused
     * loudly rather than downgraded; see {@see BudgetExceeded}.
     */
    private const SEARCH_BUDGET = 144_000_000;

    /** @var list<array{start: array{0: int, 1: int}, left: list<string>, right: list<string>}> */
    private readonly array $hunks;

    /**
     * @param list<array{start: array{0: int, 1: int}, left: list<string>, right: list<string>}> $hunks
     */
    private function __construct(
        private readonly string $leftLabel,
        private readonly string $rightLabel,
        array $hunks,
    ) {
        $this->hunks = $hunks;
    }

    public static function between(string $left, string $right, string $leftLabel, string $rightLabel): self
    {
        return new self(
            $leftLabel,
            $rightLabel,
            self::hunks(explode("\n", $left), explode("\n", $right), 1, 1),
        );
    }

    public function isEmpty(): bool
    {
        return $this->hunks === [];
    }

    public function changedLineCount(): int
    {
        $count = 0;

        foreach ($this->hunks as $hunk) {
            $count += \count($hunk['left']) + \count($hunk['right']);
        }

        return $count;
    }

    public function render(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $lines = ['--- ' . $this->leftLabel, '+++ ' . $this->rightLabel];

        foreach ($this->hunks as $hunk) {
            $lines[] = \sprintf(
                '@@ -%d,%d +%d,%d @@',
                $hunk['start'][0],
                \count($hunk['left']),
                $hunk['start'][1],
                \count($hunk['right']),
            );

            foreach ($hunk['left'] as $line) {
                $lines[] = '-' . $line;
            }

            foreach ($hunk['right'] as $line) {
                $lines[] = '+' . $line;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The removed and added lines paired by their position within their hunk.
     *
     * Pairing is what lets a reader — and the overreach check — ask which field
     * on a line actually changed, rather than which fields the line mentions. A
     * compact JSON record names half the tuple on every line it prints, so
     * "mentions a compared field" would flag every such line. Pairing inside a
     * hunk rather than across the whole diff is what keeps that answer right
     * once a diff has several hunks.
     *
     * @return list<array{0: ?string, 1: ?string}>
     */
    public function pairs(): array
    {
        $pairs = [];

        foreach ($this->hunks as $hunk) {
            for ($index = 0; $index < max(\count($hunk['left']), \count($hunk['right'])); ++$index) {
                $pairs[] = [$hunk['left'][$index] ?? null, $hunk['right'][$index] ?? null];
            }
        }

        return $pairs;
    }

    /**
     * Trims what the two spans share, then splits what is left on its longest
     * common run of lines and recurses on both sides of it.
     *
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return list<array{start: array{0: int, 1: int}, left: list<string>, right: list<string>}>
     */
    private static function hunks(array $left, array $right, int $leftStart, int $rightStart): array
    {
        $left = array_values($left);
        $right = array_values($right);

        $prefix = 0;
        $leftCount = \count($left);
        $rightCount = \count($right);

        while ($prefix < $leftCount && $prefix < $rightCount && $left[$prefix] === $right[$prefix]) {
            ++$prefix;
        }

        $suffix = 0;

        while (
            $suffix < $leftCount - $prefix
            && $suffix < $rightCount - $prefix
            && $left[$leftCount - 1 - $suffix] === $right[$rightCount - 1 - $suffix]
        ) {
            ++$suffix;
        }

        $left = \array_slice($left, $prefix, $leftCount - $prefix - $suffix);
        $right = \array_slice($right, $prefix, $rightCount - $prefix - $suffix);
        $leftStart += $prefix;
        $rightStart += $prefix;

        if ($left === [] && $right === []) {
            return [];
        }

        $anchor = self::longestCommonRun($left, $right);

        if ($anchor === null) {
            return [['start' => [$leftStart, $rightStart], 'left' => array_values($left), 'right' => array_values($right)]];
        }

        [$leftAt, $rightAt, $length] = $anchor;

        return [
            ...self::hunks(\array_slice($left, 0, $leftAt), \array_slice($right, 0, $rightAt), $leftStart, $rightStart),
            ...self::hunks(
                \array_slice($left, $leftAt + $length),
                \array_slice($right, $rightAt + $length),
                $leftStart + $leftAt + $length,
                $rightStart + $rightAt + $length,
            ),
        ];
    }

    /**
     * The longest run of identical lines the two spans share, as
     * `[leftOffset, rightOffset, length]`, or null when nothing shared is long
     * enough to anchor on.
     *
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function longestCommonRun(array $left, array $right): ?array
    {
        $leftCount = \count($left);
        $rightCount = \count($right);

        if ($leftCount * $rightCount > self::SEARCH_BUDGET) {
            throw new BudgetExceeded(\sprintf(
                'Splitting a %d x %d line span would consider %d line pairs, past the %d this diff may spend.'
                . ' Emitting the span whole instead would count the identical lines between the outermost changes'
                . ' as changed, which is the behaviour this diff was rewritten to remove — so the span is refused'
                . ' rather than silently downgraded. Raise ExactDiff::SEARCH_BUDGET, or shrink the artifact.',
                $leftCount,
                $rightCount,
                $leftCount * $rightCount,
                self::SEARCH_BUDGET,
            ));
        }

        if ($leftCount < self::ANCHOR || $rightCount < self::ANCHOR) {
            return null;
        }

        $best = null;
        $previous = array_fill(0, $rightCount + 1, 0);

        for ($i = 0; $i < $leftCount; ++$i) {
            $current = array_fill(0, $rightCount + 1, 0);

            for ($j = 0; $j < $rightCount; ++$j) {
                if ($left[$i] !== $right[$j]) {
                    continue;
                }

                $length = $previous[$j] + 1;
                $current[$j + 1] = $length;

                if ($length >= self::ANCHOR && ($best === null || $length > $best[2])) {
                    $best = [$i - $length + 1, $j - $length + 1, $length];
                }
            }

            $previous = $current;
        }

        return $best;
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
