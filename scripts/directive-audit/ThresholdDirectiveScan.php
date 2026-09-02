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
 * Three rules below are not stylistic; each one is a divergence that was
 * measured and then closed:
 *
 * - a directive is a word *ending* in the tag. The product's pattern carries no
 *   left boundary, so a tag written straight against the docblock star, with no
 *   space between them, is honoured by the product; a measure demanding the tag
 *   as a whole word would report that site missing.
 * - once a directive is recognised the rest of the line is its values and the
 *   line is not scanned again. The product's values group is greedy to the end
 *   of the line, so two directives written on one line are one match to it.
 *   A word ending in the directive whose target is *not* there is not a
 *   recognition, and scanning does continue past it — the product's own
 *   `\s+` backtracks into the same answer.
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

            $source = file_get_contents($file->getPathname());

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
                $address = self::recognise($line);

                if ($address === null) {
                    continue;
                }

                $sites[] = new EnumeratedSite($path, $token[2] + $offset, $address['target'], $address['values']);
            }
        }

        return $sites;
    }

    /**
     * @return array{target: string, values: string}|null what the line addresses, if it addresses anything
     */
    public static function recognise(string $docblockLine): ?array
    {
        $line = rtrim($docblockLine, "\r");
        $length = \strlen($line);
        $cursor = 0;

        while ($cursor < $length) {
            $cursor = self::skipSeparators($line, $cursor);
            $word = self::wordAt($line, $cursor);
            $cursor += \strlen($word);

            if ($word === '' || !str_ends_with($word, self::DIRECTIVE)) {
                continue;
            }

            $address = self::addressAfter($line, $cursor);

            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }

    /**
     * The target and the values that follow a recognised directive word.
     *
     * The values are the remainder of the line, but only when a space or a tab
     * separates them from the target: the product takes them with `[ \t]+`
     * ahead of the group, so `cbo(x) 30` addresses `cbo` and carries no values
     * at all — an authored mistake both measures must report the same way.
     *
     * @return array{target: string, values: string}|null
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
        $separated = $afterTarget < \strlen($line) && str_contains(self::WORD_SEPARATORS, $line[$afterTarget]);

        return [
            'target' => $target,
            'values' => $separated ? trim(substr($line, $afterTarget)) : '',
        ];
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
