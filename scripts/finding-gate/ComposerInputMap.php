<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/**
 * Exact Composer autoload namespace keys and path values, preserving unrelated JSON bytes.
 *
 * @phpstan-import-type MapPair from RenameMaps
 */
final class ComposerInputMap
{
    /** @param list<MapPair> $pairs
     * @return array{string,array<int,int>} */
    public static function reverse(string $text, array $pairs): array
    {
        $lookup = [];
        foreach ($pairs as $index => $pair) {
            if (\in_array(RenameMaps::SYMBOLS, $pair['sources'], true)) {
                $lookup[$pair['new']] = [$pair['old'], $index];
            }
        }
        if ($lookup === []) {
            return [$text, []];
        }
        try {
            $value = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new GateError('A mapped Composer input is not JSON: ' . $error->getMessage());
        }
        if (!\is_array($value) || array_is_list($value)) {
            throw new GateError('A mapped Composer input requires its object schema.');
        }
        $edits = [];
        $hits = [];
        self::visit($text, $value, [], $lookup, $edits, $hits);
        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        $boundary = \strlen($text);
        foreach ($edits as [$start, $end, $replacement]) {
            if ($end > $boundary) {
                throw new GateError('Two Composer input declarations overlap a JSON span.');
            }
            $text = substr($text, 0, $start) . $replacement . substr($text, $end);
            $boundary = $start;
        }
        return [$text, $hits];
    }

    /** @param list<string> $path
     * @param array<string,array{string,int}> $lookup
     * @param list<array{int,int,string}> $edits
     * @param array<int,int> $hits */
    private static function visit(string $text, mixed $value, array $path, array $lookup, array &$edits, array &$hits): void
    {
        if (\is_array($value)) {
            if (array_is_list($value)) {
                foreach ($lookup as $name => [$old]) {
                    if (\in_array($name, $value, true) && \in_array($old, $value, true)) {
                        throw new GateError('A mapped Composer path collides with an existing autoload member.');
                    }
                }
            }
            foreach ($value as $key => $child) {
                $key = (string) $key;
                $name = rtrim($key, '\\');
                if (isset($lookup[$name])) {
                    if (\count($path) !== 2 || !\in_array($path[0], ['autoload', 'autoload-dev'], true) || $path[1] !== 'psr-4' || !str_ends_with($key, '\\')) {
                        throw new GateError('A mapped Composer namespace key is outside the named psr-4 input path.');
                    }
                    [$old, $index] = $lookup[$name];
                    $target = $old . '\\';
                    if (\array_key_exists($target, $value)) {
                        throw new GateError('A mapped Composer namespace collides with an existing psr-4 key.');
                    }
                    [$start] = self::span($text, [...$path, $key]);
                    if (preg_match('~("(?:[^"\\\\]|\\\\.)*")(\s*:\s*)$~s', substr($text, 0, $start), $match, \PREG_OFFSET_CAPTURE) !== 1) {
                        throw new GateError('A Composer namespace has no exact JSON key span.');
                    }
                    $edits[] = [$match[1][1], $match[1][1] + \strlen($match[1][0]), json_encode($target, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)];
                    $hits[$index] = ($hits[$index] ?? 0) + 1;
                }
                self::visit($text, $child, [...$path, $key], $lookup, $edits, $hits);
            }
            return;
        }
        if (!\is_string($value)) {
            return;
        }
        foreach ($lookup as $name => [$old, $index]) {
            if ($value !== $name) {
                if ($name !== '' && preg_match('~(?<![A-Za-z0-9_.\\\\/-])' . preg_quote($name, '~') . '(?![A-Za-z0-9_.-])~', $value) === 1) {
                    throw new GateError('A mapped Composer string requires unsupported partial or unknown-role rewriting.');
                }
                continue;
            }
            $known = \in_array($path[0] ?? '', ['autoload', 'autoload-dev'], true)
                && (($path[1] ?? null) === 'psr-4' && (\count($path) === 3 || (\count($path) === 4 && ctype_digit($path[3])))
                    || \in_array($path[1] ?? '', ['classmap', 'files'], true) && \count($path) === 3 && ctype_digit($path[2]));
            if (!$known) {
                throw new GateError('A mapped Composer value is outside the named autoload path profile.');
            }
            [$start, $end] = self::span($text, $path);
            $edits[] = [$start, $end, json_encode($old, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)];
            $hits[$index] = ($hits[$index] ?? 0) + 1;
        }
    }

    /** @param list<string> $path
     * @return array{int,int} */
    private static function span(string $text, array $path): array
    {
        $marker = '"<finding-gate-composer-span>"';
        if (str_contains($text, $marker)) {
            throw new GateError('The Composer JSON span marker already occurs in the input.');
        }
        [$replaced, $hits] = JsonText::redact($text, $path, $marker);
        $start = strpos($replaced, $marker);
        if ($hits !== 1 || $start === false) {
            throw new GateError('A Composer input path must address exactly one JSON value.');
        }
        return [$start, $start + \strlen($text) - \strlen($replaced) + \strlen($marker)];
    }
}
