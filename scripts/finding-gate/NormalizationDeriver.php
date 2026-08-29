<?php

declare(strict_types=1);

namespace QmxFindingGate;

use stdClass;

/**
 * Derives the normalization list by measuring it: every surface, twice, on one
 * unchanged tree; whatever diverges is a row.
 *
 * The list is never written from memory. The previous plan revision listed
 * fields from recollection and the list turned out shorter than reality — which
 * is exactly the failure mode a measured list cannot have.
 */
final class NormalizationDeriver
{
    /**
     * A clock field or two is nondeterminism. A dozen is a structural
     * difference wearing its costume, and blanketing it would hollow out the
     * gate.
     */
    private const MAX_ROWS = 10;

    /** How much of the line before the varying field is kept as its label. */
    private const LABEL_LENGTH = 30;

    /**
     * Repeated passes, not two.
     *
     * `duration` rounds to the same three decimals often enough that a single
     * pair of runs misses it, and a list that depends on luck is not a measured
     * list: the missed field comes back later as a flaky red. Five passes make
     * the sampling of a real clock reliable while still measuring rather than
     * declaring.
     */
    private const PASSES = 5;

    public static function passes(): int
    {
        return self::PASSES;
    }

    /**
     * @param list<array<string, string>> $passes
     *
     * @return list<NormalizationRule>
     */
    public static function derive(array $passes): array
    {
        $first = $passes[0] ?? throw new GateError('Nothing measured.');
        $rules = [];

        foreach (\array_slice($passes, 1) as $other) {
            foreach ($first as $key => $content) {
                $counterpart = $other[$key] ?? null;

                if ($counterpart === null || $counterpart === $content) {
                    continue;
                }

                foreach (self::rulesFor(Surfaces::surfaceClass($key), $content, $counterpart) as $rule) {
                    $rules[$rule->surface . "\0" . $rule->locator . "\0" . $rule->kind] = $rule;
                }
            }
        }

        if (\count($rules) > self::MAX_ROWS) {
            throw new GateError(\sprintf(
                'Repeated runs of one unchanged tree diverged in %d fields: %s. That is not a clock; look for a real'
                . ' nondeterminism before declaring any of it normalizable.',
                \count($rules),
                implode(', ', array_map(
                    static fn(NormalizationRule $rule): string => $rule->surface . ':' . $rule->locator,
                    array_values($rules),
                )),
            ));
        }

        $derived = array_values($rules);
        usort($derived, static fn(NormalizationRule $a, NormalizationRule $b): int => [$a->surface, $a->locator] <=> [$b->surface, $b->locator]);

        return $derived;
    }

    /** @return list<NormalizationRule> */
    private static function rulesFor(string $surface, string $left, string $right): array
    {
        $leftJson = json_decode($left, false);
        $rightJson = json_decode($right, false);

        if (($leftJson instanceof stdClass || \is_array($leftJson)) && ($rightJson instanceof stdClass || \is_array($rightJson))) {
            return self::rules($surface, NormalizationRule::KIND_JSON_PATH, self::divergingPaths($leftJson, $rightJson, ''));
        }

        $embedded = self::embeddedReportData($left, $right);

        if ($embedded !== null) {
            return self::rules($surface, NormalizationRule::KIND_HTML_REPORT_DATA_PATH, $embedded);
        }

        return self::rules($surface, NormalizationRule::KIND_LINE_REGEX, self::divergingLinePatterns($left, $right));
    }

    /**
     * @param list<string> $locators
     *
     * @return list<NormalizationRule>
     */
    private static function rules(string $surface, string $kind, array $locators): array
    {
        return array_map(
            static fn(string $locator): NormalizationRule => new NormalizationRule(
                $surface,
                $locator,
                $kind,
                Normalization::MEASURED_REASON,
            ),
            $locators,
        );
    }

    /** @return list<string>|null */
    private static function embeddedReportData(string $left, string $right): ?array
    {
        $pattern = '~<script type="application/json" id="report-data">(.*?)</script>~s';

        if (preg_match($pattern, $left, $leftMatch) !== 1 || preg_match($pattern, $right, $rightMatch) !== 1) {
            return null;
        }

        return self::divergingPaths(
            json_decode($leftMatch[1], false, flags: \JSON_THROW_ON_ERROR),
            json_decode($rightMatch[1], false, flags: \JSON_THROW_ON_ERROR),
            '',
        );
    }

    /** @return list<string> */
    private static function divergingPaths(mixed $left, mixed $right, string $prefix): array
    {
        if ($left instanceof stdClass && $right instanceof stdClass) {
            return self::divergingChildren(get_object_vars($left), get_object_vars($right), $prefix, false);
        }

        if (\is_array($left) && \is_array($right)) {
            if (\count($left) !== \count($right)) {
                throw new GateError(\sprintf('Structural divergence at "%s": list length differs.', $prefix));
            }

            return self::divergingChildren($left, $right, $prefix, true);
        }

        if ($left === $right) {
            return [];
        }

        if (\gettype($left) !== \gettype($right)) {
            throw new GateError(\sprintf('Structural divergence at "%s": type changed between runs.', $prefix));
        }

        return [$prefix];
    }

    /**
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     *
     * @return list<string>
     */
    private static function divergingChildren(array $left, array $right, string $prefix, bool $isList): array
    {
        if (array_keys($left) !== array_keys($right)) {
            throw new GateError(\sprintf('Structural divergence at "%s": the key set changed between runs.', $prefix));
        }

        $paths = [];

        foreach ($left as $key => $value) {
            // One row must cover a whole list of findings, so a list index is
            // addressed as `*` rather than pinned to the position it happened to
            // diverge at.
            $segment = $isList ? '*' : (string) $key;
            $childPrefix = $prefix === '' ? $segment : $prefix . '.' . $segment;

            foreach (self::divergingPaths($value, $right[$key], $childPrefix) as $path) {
                $paths[$path] = $path;
            }
        }

        return array_values($paths);
    }

    /** @return list<string> */
    private static function divergingLinePatterns(string $left, string $right): array
    {
        $leftLines = explode("\n", $left);
        $rightLines = explode("\n", $right);

        if (\count($leftLines) !== \count($rightLines)) {
            throw new GateError('Structural divergence: two runs of one tree produced a different line count.');
        }

        $patterns = [];

        foreach ($leftLines as $index => $line) {
            $other = $rightLines[$index];

            if ($line === $other) {
                continue;
            }

            $pattern = self::linePattern($line, $other);
            $patterns[$pattern] = $pattern;
        }

        return array_values($patterns);
    }

    /**
     * The varying part, fenced by everything around it that did not vary — so
     * the locator names the field by its label, not by a substring that could
     * match somewhere else.
     */
    private static function linePattern(string $left, string $right): string
    {
        $prefixLength = 0;

        while ($prefixLength < min(\strlen($left), \strlen($right)) && $left[$prefixLength] === $right[$prefixLength]) {
            ++$prefixLength;
        }

        $suffixLength = 0;
        $limit = min(\strlen($left), \strlen($right)) - $prefixLength;

        while ($suffixLength < $limit && $left[\strlen($left) - 1 - $suffixLength] === $right[\strlen($right) - 1 - $suffixLength]) {
            ++$suffixLength;
        }

        // The character-level common prefix eats the leading digits a value
        // happens to share ("1.2s" vs "1.3s" agree on "1."), which would anchor
        // the locator to one value instead of to the field. Pulling both fences
        // back to the label keeps the locator precise about WHICH field it names.
        $prefix = rtrim(substr($left, 0, $prefixLength), '0123456789.,:%-+');
        $suffix = $suffixLength === 0 ? '' : ltrim(substr($left, -$suffixLength), '0123456789.,:%-+');

        if (trim($prefix) === '') {
            throw new GateError(\sprintf('Cannot name the diverging field on the line "%s" precisely.', $left));
        }

        return '~(' . self::labelPattern(self::label($prefix)) . ').*(' . preg_quote($suffix, '~') . ')$~m';
    }

    /**
     * The label as a pattern: a quantity is matched as a number and the word it
     * inflects is matched in both inflections.
     *
     * Measured defect this exists for: a locator derived from `4 files
     * analyzed, 0.1s` read `files analyzed, ` and matched nothing at all in the
     * `annotations` case, whose line is `1 file analyzed, 0.0s`. That is both
     * halves of the same failure — the rule went stale where it did not match,
     * and the duration it should have redacted showed up as undeclared
     * nondeterminism where it did. A locator must name the field across every
     * quantity the corpus can produce, because which case measured it is an
     * accident.
     *
     * Only the `s` inflection is tolerated. A word that pluralises otherwise
     * ("entry"/"entries") still yields a single-inflection locator; that fails
     * visibly as a stale rule rather than silently, which is the outcome we can
     * act on.
     */
    private static function labelPattern(string $label): string
    {
        $pattern = '';
        $afterQuantity = false;

        $tokens = preg_split('~(\s+)~', $label, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            throw new GateError(\sprintf('Cannot tokenise the label "%s" into a locator pattern.', $label));
        }

        foreach ($tokens as $token) {
            if (trim($token) === '') {
                $pattern .= preg_quote($token, '~');

                continue;
            }

            if (preg_match('~^\d+$~', $token) === 1) {
                $pattern .= '\\d+';
                $afterQuantity = true;

                continue;
            }

            if ($afterQuantity && preg_match('~^(\w+?)(s?)([^\w]*)$~', $token, $matches) === 1) {
                $pattern .= preg_quote($matches[1], '~') . 's?' . preg_quote($matches[3], '~');
                $afterQuantity = false;

                continue;
            }

            $pattern .= preg_quote($token, '~');
            $afterQuantity = false;
        }

        return $pattern;
    }

    /**
     * The field's label, not the whole line.
     *
     * A locator built from the entire common prefix would pin the numbers that
     * happened to precede the varying field — one row per case ("10 files
     * analyzed", "12 files analyzed") and one per tool version. Those rows are
     * not reproducible by re-measuring, and a list that changes shape every time
     * it is measured is not a measured list. Keeping the trailing label keeps the
     * row precise about WHICH field it names while covering every case.
     */
    private static function label(string $prefix): string
    {
        $label = $prefix;

        if (\strlen($label) > self::LABEL_LENGTH) {
            $label = substr($label, -self::LABEL_LENGTH);
            $boundary = strpos($label, ' ');
            $label = $boundary === false ? $label : substr($label, $boundary + 1);
        }

        // A number left verbatim would pin one case's shape ("12 files
        // analyzed"); a leading partial token would pin a digit fragment. Drop
        // the fragment, keep whole numbers — labelPattern() turns them into
        // `\d+`, which is what makes one row cover every case.
        if (preg_match('~^\S*\d\S*\s+~', $label, $matches) === 1 && preg_match('~^\d+\s~', $label) !== 1) {
            $label = substr($label, \strlen($matches[0]));
        }

        if (trim($label) === '') {
            throw new GateError(\sprintf('Cannot name a diverging field precisely inside "%s".', $prefix));
        }

        return $label;
    }
}
