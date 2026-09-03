<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The second measure of the authored `@qmx-threshold` population.
 *
 * The gate compares what the audit judged against what a tree scan finds, and
 * that pair is only worth running if the two measures can disagree. They could
 * not: this scan used to hold a hand-copied duplicate of
 * {@see \Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor}'s
 * private pattern, with a test forcing the copy to stay byte-identical — so a
 * regression inside the pattern itself was authored once and landed in both
 * measures at the same instant.
 *
 * So this measure is built differently on purpose. It splits a docblock line
 * into words and cuts the target with its own character list, written out here
 * rather than referenced, and nothing it does is a regular expression over the
 * directive. The independence is textual rather than conceptual, and that is
 * the whole point: a character narrowed in one of the two spellings is caught
 * because the other one did not move. What it may not do is disagree on
 * authored forms, so a fixture of them is asserted against the product's own
 * extraction — that agreement, not this file, is what makes the measure usable
 * as a witness.
 *
 * Four rules below are not stylistic; each one is a divergence that was
 * measured and then closed:
 *
 * - a directive is a word *ending* in the tag. The product's pattern carries no
 *   left boundary, so a tag written straight against the docblock star, with no
 *   space between them, is honoured by the product; a measure demanding the tag
 *   as a whole word would report that site missing.
 * - a directive whose target is followed by a space takes the rest of the line
 *   as its values, and the line ends there. A directive whose target is not is
 *   a site with no values, and the scan resumes right after that target. The
 *   product's values group is greedy to the line break — but only once it
 *   matches at all, and it does not match when no space separates the target
 *   from what follows. Review measured the difference: on
 *   `@qmx-threshold cbo(x) @qmx-threshold other 20` the product reports two
 *   sites, and a measure that stopped at the first would report one.
 * - the values are what the product parses, terminator and all: a docblock
 *   written on a single line ends with `*` and a slash, and the product strips
 *   that marker with the whitespace around it before reading the values.
 * - backtick regions are blanked, not removed. The product replaces every
 *   non-newline character of such a region with a space (AGENTS.md §8); cutting
 *   the region out instead shortens the docblock by however many lines it
 *   spanned, and every directive below it is then reported on the wrong line.
 *
 * One divergence is left open and is not this measure's to close: the product's
 * separator between the directive and its target is `\s+`, which crosses a line
 * break, so `@qmx-threshold` alone at the end of a line takes the next line's
 * docblock star as its target. A target of `*` is not something an author
 * wrote; teaching this scan to reproduce it would be copying a defect into the
 * witness of it.
 */
final class ThresholdDirectiveScan
{
    private const string DIRECTIVE = '@qmx-threshold';

    /**
     * The characters a target is made of, spelled out rather than borrowed.
     *
     * `#` and `:` belong here because the product captures the retired
     * `rule#code` spelling and a `channel:level` pair whole in order to refuse
     * them by name; a measure that stopped at the separator would report a
     * different site than the one the audit judges.
     */
    private const string TARGET_CHARACTERS =
        'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#:-';

    private const string WORD_SEPARATORS = " \t";

    /**
     * @throws RuntimeException when the tree cannot be read
     *
     * @return list<EnumeratedSite> in traversal order
     */
    public static function overTree(string $root, string $directory): array
    {
        $sites = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // `is_readable()` first so that an unreadable file leaves this
            // refusal and not also an `E_WARNING`: the suite this scan is
            // guarded by fails on warnings, and a check that cannot be
            // exercised without tripping the runner is a check nobody runs.
            $source = $file->isReadable() ? file_get_contents($file->getPathname()) : false;

            if ($source === false) {
                throw new RuntimeException(\sprintf('unreadable: %s', $file->getPathname()));
            }

            foreach (self::overFile(substr($file->getPathname(), \strlen($root) + 1), $source) as $site) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * Only `T_DOC_COMMENT` is read: an ordinary comment carries no directive
     * for the product either, and a grep over the raw source would report
     * sites the audit will never judge.
     *
     * @return list<EnumeratedSite>
     */
    public static function overFile(string $path, string $source): array
    {
        $sites = [];

        foreach (token_get_all($source) as $token) {
            if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {
                continue;
            }

            foreach (explode("\n", self::blankBacktickRegions($token[1])) as $offset => $line) {
                foreach (self::recognise($line) as $address) {
                    $sites[] = new EnumeratedSite(
                        $path,
                        $token[2] + $offset,
                        $address['target'],
                        $address['values'],
                    );
                }
            }
        }

        return $sites;
    }

    /**
     * Everything one line addresses, in the order it is written.
     *
     * Usually that is nothing or one directive, and a directive carrying values
     * is always the last thing on its line: the product's values group runs to
     * the line break, so what follows a complete directive is its reason text
     * however many tags a reader sees there.
     *
     * A directive whose target is *not* followed by a space carries no values —
     * and then the product's own scan resumes right after that target and can
     * match a second directive on the same line. A cut-short target followed by
     * a second tag with a target of its own is two sites to the product, not
     * one, and a measure returning a single address per line would report the
     * second of them missing.
     *
     * @return list<array{target: string, values: string}>
     */
    public static function recognise(string $docblockLine): array
    {
        $line = rtrim($docblockLine, "\r");
        $length = \strlen($line);
        $cursor = 0;
        $addresses = [];

        while ($cursor < $length) {
            $cursor = self::skipSeparators($line, $cursor);
            $word = self::wordAt($line, $cursor);
            $cursor += \strlen($word);

            if ($word === '' || !str_ends_with($word, self::DIRECTIVE)) {
                continue;
            }

            $address = self::addressAfter($line, $cursor);

            if ($address === null) {
                continue;
            }

            $addresses[] = ['target' => $address['target'], 'values' => $address['values']];

            if ($address['values'] !== '' || $address['carriesValues']) {
                return $addresses;
            }

            $cursor = $address['end'];
        }

        return $addresses;
    }

    /**
     * The target and the values that follow a recognised directive word.
     *
     * The values are the remainder of the line, but only when a space or a tab
     * separates them from the target: the product takes them with `[ \t]+`
     * ahead of the group, so `cbo(x) 30` addresses `cbo` and carries no values
     * at all — an authored mistake both measures must report the same way.
     * `carriesValues` says which of the two happened, because an empty reason
     * text and no reason text at all end the line differently.
     *
     * @return array{target: string, values: string, carriesValues: bool, end: int}|null
     */
    private static function addressAfter(string $line, int $cursor): ?array
    {
        $afterSeparators = self::skipSeparators($line, $cursor);

        if ($afterSeparators === $cursor) {
            // Nothing separates the directive from what follows it, which on a
            // line-oriented reading means nothing follows it at all.
            return null;
        }

        $target = self::targetAt($line, $afterSeparators);

        if ($target === '') {
            return null;
        }

        $afterTarget = $afterSeparators + \strlen($target);
        $afterSpacing = self::skipSeparators($line, $afterTarget);
        $carriesValues = $afterSpacing > $afterTarget;

        return [
            'target' => $target,
            'values' => $carriesValues ? self::withoutTheDocblockTerminator(substr($line, $afterSpacing)) : '',
            'carriesValues' => $carriesValues,
            'end' => $afterTarget,
        ];
    }

    /**
     * A docblock written on one line ends its own values.
     *
     * `/** @qmx-threshold one.line 20 *\/` hands the product `20` and not
     * `20 *\/`: it strips a terminal docblock marker and the whitespace around
     * it before it parses anything. Without the same rule the values column of
     * the enumeration is a different string from the one the product read, on
     * a form nobody has written in `src/` yet.
     */
    private static function withoutTheDocblockTerminator(string $values): string
    {
        $trimmed = rtrim($values);

        if (!str_ends_with($trimmed, '*/')) {
            // Trailing whitespace with no marker behind it stays: the product
            // keeps it too, and a measure tidier than the thing it measures is
            // a measure that disagrees.
            return $values;
        }

        return rtrim(substr($trimmed, 0, -2));
    }

    private static function skipSeparators(string $line, int $cursor): int
    {
        while ($cursor < \strlen($line) && str_contains(self::WORD_SEPARATORS, $line[$cursor])) {
            ++$cursor;
        }

        return $cursor;
    }

    private static function wordAt(string $line, int $cursor): string
    {
        $end = $cursor;

        while ($end < \strlen($line) && !str_contains(self::WORD_SEPARATORS, $line[$end])) {
            ++$end;
        }

        return substr($line, $cursor, $end - $cursor);
    }

    private static function targetAt(string $line, int $cursor): string
    {
        $end = $cursor;

        while ($end < \strlen($line) && str_contains(self::TARGET_CHARACTERS, $line[$end])) {
            ++$end;
        }

        return substr($line, $cursor, $end - $cursor);
    }

    /**
     * A backtick region becomes as many spaces as it had characters, keeping
     * every line break it spanned, exactly as the product blanks it.
     */
    private static function blankBacktickRegions(string $text): string
    {
        return preg_replace_callback(
            '/`[^`]*`/',
            static fn(array $match): string => preg_replace('/[^\r\n]/', ' ', $match[0]) ?? $match[0],
            $text,
        ) ?? $text;
    }
}
