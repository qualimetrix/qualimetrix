<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Independent enumeration of authored threshold sites, including refused mentions.
 *
 * This scanner uses tokenized comments, character runs and an explicit target
 * alphabet. It imports no product grammar: agreement is measured on authored forms.
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
     * Threshold population is read from docblocks. Ordinary comments are
     * separately refused by source policy and are outside this scan's population.
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

            foreach (self::commentLines($token[1]) as $offset => $line) {
                foreach (self::recognise($line) as $address) {
                    $sites[] = new EnumeratedSite($path, $token[2] + $offset, $address['target'], $address['values']);
                }
            }
        }

        return $sites;
    }

    /** @return list<array{target: string, values: string}> */
    public static function recognise(string $docblockLine): array
    {
        $line = rtrim($docblockLine, "\r");
        $cursor = 0;
        $addresses = [];
        while ($cursor < \strlen($line)) {
            $cursor = self::skipSeparators($line, $cursor);
            $word = self::wordAt($line, $cursor);
            $cursor += \strlen($word);
            if ($word === '' || !str_ends_with($word, self::DIRECTIVE)) {
                continue;
            }
            $position = $cursor - \strlen(self::DIRECTIVE);
            if (self::quotedMention($line, $position)) {
                continue;
            }
            $address = self::addressAfter($line, $cursor);
            if ($address === null) {
                continue;
            }
            $addresses[] = ['target' => $address['target'], 'values' => $address['values']];
            // Values belong to the first tag, but later tags are separately refused mentions.
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

        $stars = strspn($line, '*', $afterSeparators);
        if ($target === '' || ($stars > 0 && ($line[$afterSeparators + $stars] ?? null) === '/')) {
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

    /** @return list<string> */
    private static function commentLines(string $text): array
    {
        $lines = explode("\n", $text);
        $fence = null;
        $pending = [];
        foreach ($lines as $index => $line) {
            $content = substr($line, strspn($line, " \t\r\v\f/*#"));
            $character = $content[0] ?? '';
            $width = $character === '`' || $character === '~' ? strspn($content, $character) : 0;
            if ($fence !== null) {
                $tail = trim(substr($content, $width));
                if ($character === $fence[0] && $width >= $fence[1] && ($tail === '' || $tail === '*/')) {
                    $fence = null;
                    $pending = [];
                } elseif (str_starts_with($content, self::DIRECTIVE)) {
                    $pending[$index] = $line;
                }
                $lines[$index] = str_repeat(' ', \strlen($line));
            } elseif ($width >= 3 && ($character === '~' || !str_contains(substr($content, $width), '`'))) {
                $fence = [$character, $width];
                $lines[$index] = str_repeat(' ', \strlen($line));
            }
        }
        foreach ($pending as $index => $line) {
            $lines[$index] = $line;
        }

        return array_values($lines);
    }

    private static function quotedMention(string $line, int $position): bool
    {
        $cursor = 0;
        while (($opening = strpos($line, '`', $cursor)) !== false) {
            $width = strspn($line, '`', $opening);
            $search = $opening + $width;
            $closing = null;
            while (($run = strpos($line, '`', $search)) !== false) {
                $length = strspn($line, '`', $run);
                if ($length === $width) {
                    $closing = $run;
                    break;
                }
                $search = $run + $length;
            }
            if ($closing === null) {
                $cursor = $opening + $width;
                continue;
            }
            if ($position >= $opening + $width && $position < $closing) {
                $prefix = substr($line, $opening + $width, $position - $opening - $width);

                return strspn($prefix, " \t\r\v\f/*#`") === \strlen($prefix);
            }
            $cursor = $closing + $width;
        }

        return false;
    }
}
